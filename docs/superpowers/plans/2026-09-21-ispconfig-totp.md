# ISPConfig TOTP Implementation Plan

> **For agentic workers:** Umsetzung inline und seriell durch Claude (Vorgabe von Mathias: keine Subagenten ohne Freigabe). Schritte als Checkboxen.

**Goal:** TOTP als zweiter Faktor für ISPConfig 3.3, eingerichtet im Profil per Modal, geprüft nach dem Passwort, ohne Änderung an Kerndateien.

**Architecture:** Eigenes Modulverzeichnis `interface/web/totpauth/` mit Plugin am `login`-Ereignis, Codeseite und JSON-API; ein Skript in `web/js/js.d/` baut Profilzeile, Modal und Admin-Knopf. Schlüssel verschlüsselt in eigener Tabelle, Schlüssel für die Verschlüsselung außerhalb des Webordners.

**Tech Stack:** PHP 8 (ISPConfig-Umgebung, `sodium`), jQuery und Bootstrap 3 aus dem Panel, qrcode-generator (MIT) für den QR-Code, POSIX-sh für Installer.

**Spec:** `docs/superpowers/specs/2026-09-21-ispconfig-totp-design.md`

## Global Constraints

- Keine Kerndatei von ISPConfig ändern; nur `interface/web/totpauth/`, `interface/web/js/js.d/totpauth*.js`, `/usr/local/ispconfig/totpauth/`, Tabelle `totpauth_user`.
- RFC 6238: SHA-1, 6 Stellen, 30 s, Fenster ±1 Schritt, nur Schritte über `last_step`.
- 20-Byte-Schlüssel, Base32 ohne Padding; acht Wiederherstellungscodes, Format `xxxx-xxxx-xxxx` (Base32-Kleinbuchstaben), gespeichert mit `password_hash`.
- Fünf Fehlversuche je Anmeldung, zehn seit der letzten erfolgreichen Anmeldung sperren.
- Nur `sys_user`-Konten (`typ` admin/user), keine Mailbenutzer; „Anmelden als“ (`$_SESSION['s_old']`) wird durchgelassen.
- Konstanten nur `LOGLEVEL_DEBUG`, `LOGLEVEL_WARN`, `LOGLEVEL_ERROR`.
- Fehlermeldungen: Ursache und nächster Schritt, echte Umlaute; API antwortet immer JSON.
- Code englisch, Texte deutsch (en.lng als Rückfall); keine echten Daten in Repo, Tests, Doku.

---

### Task 1: TOTP-Kern

**Files:** Create `module/totpauth/lib/totp.inc.php`, `tests/totp-test.php`

**Interfaces (Produces):**
- `totpauth_base32_encode(string $bin): string`, `totpauth_base32_decode(string $b32): ?string`
- `totpauth_code(string $secretBin, int $step, int $digits = 6): string`
- `totpauth_step(?int $time = null): int` (floor(time/30))
- `totpauth_match(string $secretBin, string $code, int $lastStep, ?int $time = null): ?int` → angenommener Schritt oder null
- `totpauth_new_secret(): string` (20 Byte), `totpauth_uri(string $issuer, string $account, string $secretBin): string`

- [ ] Test: RFC-6238-Vektoren SHA-1 (Seed `12345678901234567890`; Zeiten 59, 1111111109, 1111111111, 1234567890, 2000000000, 20000000000 → 94287082, 07081804, 14050471, 89005924, 69279037, 65353130) mit 8 Stellen; Base32-Rundreise; Fenster ±1 angenommen, ±2 abgelehnt; Schritt ≤ lastStep abgelehnt; falsches Format (5 Stellen, Buchstaben) abgelehnt.
- [ ] Test rot sehen, implementieren, grün, Gegenprobe (Fensterprüfung zurückdrehen → rot).
- [ ] Commit.

### Task 2: Speicher

**Files:** Create `module/totpauth/lib/store.inc.php`, `tests/store-test.php`, `tests/fakedb.php`

**Interfaces (Produces):**
- `class totpauth_store { __construct($db, string $keyFile); get(int $uid): ?array; enabled(int $uid): bool; enable(int $uid, string $secretBin): array /* 8 Klartext-Codes */; secret(array $row): string; accept_step(int $uid, int $step): void; use_recovery(int $uid, string $code): bool; failed(int $uid): int /* neuer Stand */; reset_failed(int $uid): void; delete(int $uid): void; }`
- DB-Aufrufe nur über `$db->queryOneRecord($sql, ...$p)` und `$db->query($sql, ...$p)` (ISPConfig `db`-Klasse).
- `totpauth_key(string $file): string` lädt 32 Byte oder wirft `RuntimeException` mit Klartext.

- [ ] Tests mit Fake-DB (sqlite, nur für Logik; SQL wird in Task 7 gegen MariaDB geprüft): verschlüsseln/entschlüsseln, Nonce je Speicherung neu, falscher Schlüssel → Ausnahme; Wiederherstellungscode genau einmal gültig, Format unempfindlich gegen Groß/Klein und Bindestriche; `failed` zählt, `reset_failed` setzt 0.
- [ ] Rot, implementieren, grün, Commit.

### Task 3: Plugin und Codeseite

**Files:** Create `module/totpauth/lib/plugin.d/totpauth_plugin.inc.php`, `module/totpauth/lib/common.inc.php`, `module/totpauth/verify.php`, `module/totpauth/templates/verify.htm`, `module/totpauth/lib/lang/de.lng`, `en.lng`

**Interfaces:**
- Plugin-Klasse `totpauth_plugin` mit `onLoad()` → `registerEvent('login', 'totpauth_plugin', 'on_login', 'totpauth')`, `registerEvent('tools:user_settings:on_after_update', …, 'on_settings_saved', 'totpauth')`, `registerEvent('admin:users:on_after_update', …, 'on_settings_saved', 'totpauth')`.
- `common.inc.php`: `totpauth_app_store(): totpauth_store`, `totpauth_lng(string $key): string`, `totpauth_eligible(array $sessionUser): bool`, `totpauth_force_change_active(): bool`.
- Sitzung: `$_SESSION['totpauth'] = ['userid' => int, 'attempts' => int, 'started' => int]`.

- [ ] `on_login`: Ausnahmen („Anmelden als“, Mailbenutzer, kein Datensatz) → return; sonst `s` nach `s_pending`, `totpauth` setzen, `Location: ../totpauth/verify.php`, `die()`.
- [ ] `verify.php`: aktive Sitzung → `/index.php`; ohne `totpauth`/`s_pending` → `/login/`; POST mit CSRF; App-Code über `totpauth_match` + `accept_step`; Wiederherstellungscode über `use_recovery`; Grenzen 5/10; Erfolg: Sitzung zurück, `reset_failed`, `auth_log`, `Location: ../index.php`. Rendern mit `main_login.tpl.htm` und `templates/verify.htm`.
- [ ] `on_settings_saved`: ist die App aktiv, `UPDATE sys_user SET otp_type='none'`.
- [ ] Prüfung per Harness-Rendern der Codeseite (Theme-Harness) und `php -l`; Konstanten-Prüfung (`grep LOGLEVEL_`).
- [ ] Commit.

### Task 4: API

**Files:** Create `module/totpauth/api.php`

**Interfaces:** POST `action` ∈ `status|begin|confirm|disable|admin_status|admin_reset`, Antwort `{ok, error?, csrf:{id,key}, …}`. `status` darf GET sein und liefert das erste Token.

- [ ] Nur mit aktiver Sitzung (`$_SESSION['s']['user']['active'] == 1`), userid nur aus der Sitzung; admin_* nur mit `typ == admin`; CSRF ab `begin`; Fehler als JSON mit Klartext und HTTP 400/403.
- [ ] `begin`: Schlüssel in `$_SESSION['totpauth_setup']`; `confirm`: Code prüfen → `enable` → `otp_type='none'` → Codes zurück; `disable`: App- oder Wiederherstellungscode → `delete`.
- [ ] Prüfung: Harness-Router ruft api.php mit Fake-Sitzung, jede Aktion einmal gut, einmal schlecht.
- [ ] Commit.

### Task 5: Profil-Modal und Admin-Knopf

**Files:** Create `jsd/totpauth.js`, `jsd/totpauth-qr.js` (qrcode-generator, MIT, mit Lizenzkopf)

- [ ] Erkennt Profil an `#pageContent select#otp_type` + `data-form-action="tools/user_settings.php"`; setzt Zeile nach der Zwei-Faktor-Zeile; bei aktiver App Auswahl gesperrt mit Hinweis.
- [ ] Modal (Bootstrap 3, `role=dialog`, Fokus im Modal, Escape schließt): Schritt QR + Schlüssel, Schritt Code, Schritt Wiederherstellungscodes (Kopieren, Herunterladen als `.txt`).
- [ ] Deaktivieren-Modal mit Codeeingabe.
- [ ] Admin: Formular `admin/users` → Knopf „App-Anmeldung zurücksetzen“ nach `admin_status`.
- [ ] Netzwerk- und HTML-Fehler in Klartext übersetzen.
- [ ] Steht `force_password_change_days` über 0, zeigt die Profilzeile einen Hinweis (API `status` liefert `force_change`).
- [ ] Prüfung im Nachbau des Themes (hell, dunkel, Default-Theme), Screenshots.
- [ ] Commit.

### Task 6: Installer

**Files:** Create `install.sh`, `uninstall.sh`, `install/setup.php`, `tests/install-test.sh`

- [ ] Prüft Panel-Version, `plugin.d`-Loader, `raiseEvent('login'` in `login/index.php`, `js.d` in `web/index.php`; bricht bei `force_password_change_days > 0` ab.
- [ ] Schlüssel anlegen (nie überschreiben), Tabelle anlegen, Dateien über Zwischenordner, Rechte wie `web/tools`.
- [ ] `uninstall.sh [--purge]`.
- [ ] Test gegen nachgestelltes Panel: frisch, erneut (Schlüssel unverändert), fehlender Einhakpunkt → Abbruch ohne Änderung.
- [ ] Commit.

### Task 7: Ende-zu-Ende auf dem Panel, Doku, Release

- [ ] README (Einrichtung, Grenzen, Update-Pflicht), CHANGELOG 0.1.0.
- [ ] Theme: `verify.php` in `LOGIN_TITLES` von brightcolor aufnehmen (Theme-Patch-Release).
- [ ] Installieren auf dem Produktivpanel, Testkonto `totp-test` anlegen, Einrichten per API, Anmeldung per curl: Passwort → Codeseite → falscher Code → richtiger Code → Panel; Wiederverwendung abgelehnt; Wiederherstellungscode; Sperre; Admin-Reset. Testkonto und Datensatz entfernen. Alles im Serverprotokoll.
- [ ] Tag v0.1.0, ausrollen.
