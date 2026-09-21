# ISPConfig TOTP: Design

Stand 21.09.2026, abgestimmt mit Mathias.

## Ziel

ISPConfig 3.3 kennt als zweiten Faktor nur einen Code per E-Mail
(`sys_user.otp_type` ist `set('none','email')`). Dieses Projekt ergänzt eine
Anmeldung mit Authenticator-App (TOTP nach RFC 6238), ohne eine Kerndatei
von ISPConfig zu ändern. Es übersteht Updates, weil es nur Dateien in eigenen
Ordnern und eine eigene Tabelle anlegt.

Öffentliches Repo `brightcolor/ispconfig-totp`, Lizenz BSD-2-Clause wie
ISPConfig. Code englisch, Oberfläche deutsch mit englischer Rückfallsprache.

## Einhakpunkte in ISPConfig (geprüft gegen 3.3.1p1)

| Punkt | Datei | Nutzung |
|---|---|---|
| Plugins aus `web/<modul>/lib/plugin.d/*.inc.php` | `lib/classes/plugin.inc.php` | eigenes Modulverzeichnis `web/totpauth/` mit Plugin |
| Ereignis `login` nach geprüftem Passwort, vor der Weiterleitung ins Panel | `web/login/index.php` | Sitzung anhalten, auf die Codeseite leiten |
| Skripte aus `web/js/js.d/*.js` auf jeder Panel-Seite | `web/index.php` | Zeile und Modal im Profil, Knopf in der Benutzerverwaltung |
| Formularereignisse `tools:user_settings:on_after_update`, `admin:users:on_after_update` | `lib/classes/tform_actions.inc.php` | E-Mail-Code bleibt aus, solange die App aktiv ist |
| `csrf_token_get()` / `csrf_token_check()` | `lib/classes/auth.inc.php` | CSRF-Schutz für Codeseite und API, Token sind einmalig |
| `auth_log()` | `lib/app.inc.php` | Anmeldungen landen im selben `auth.log` wie die des Panels |

## Bausteine

```
interface/web/totpauth/
  lib/plugin.d/totpauth_plugin.inc.php   Ereignisse login und Formular-Speichern
  lib/totp.inc.php                       RFC 6238, Base32, Codeprüfung mit Fenster
  lib/store.inc.php                      Tabelle, Verschlüsselung, Wiederherstellungscodes
  lib/lang/de.lng, en.lng                Texte
  verify.php                             Codeseite nach dem Passwort
  api.php                                JSON für Modal und Admin-Knopf
  templates/verify.htm                   Formular der Codeseite
interface/web/js/js.d/totpauth.js        Profilzeile, Modal, Admin-Knopf
interface/web/js/js.d/totpauth-qr.js     QR-Erzeuger (qrcode-generator, MIT)
/usr/local/ispconfig/totpauth/secret.key 32 Byte Schlüssel, 0400, Eigentümer ispconfig
```

Der Modulordner heißt `totpauth`, damit er mit keinem heutigen oder
wahrscheinlichen ISPConfig-Modul kollidiert. Er erscheint in keiner
Navigation: `nav.php` zeigt nur Module aus `sys_user.modules`.

## Tabelle `totpauth_user`

| Spalte | Typ | Inhalt |
|---|---|---|
| `userid` | int unsigned, PK | `sys_user.userid` |
| `secret` | varbinary(255) | Schlüssel, mit `sodium_crypto_secretbox` verschlüsselt, Nonce vorangestellt |
| `recovery` | text | JSON-Liste von `password_hash()` der acht Wiederherstellungscodes |
| `last_step` | bigint | zuletzt angenommener Zeitschritt (Schutz vor Wiederverwendung) |
| `failed` | int | Fehlversuche seit der letzten erfolgreichen Anmeldung |
| `created` | datetime | Zeitpunkt der Einrichtung |

Nur Konten aus `sys_user` mit `typ` `admin` oder `user`. Mailbenutzer
(`build_fake_user`, erkennbar an `mailuser_id` in der Sitzung) bleiben außen
vor: Ihre Kennung stammt aus einer anderen Tabelle und könnte mit einer
`sys_user.userid` zusammenfallen.

## Abläufe

### Einrichten (Profil → Modal)

1. `totpauth.js` erkennt `tools/user_settings.php` am Feld `#otp_type` und
   setzt darunter eine Zeile „Authenticator-App“ mit Status und Knopf.
2. „Einrichten“ öffnet ein Bootstrap-Modal. `api.php?action=begin` erzeugt
   einen 20-Byte-Schlüssel, legt ihn **nur in der Sitzung** ab und liefert
   `otpauth://`-URI, Schlüssel als Base32 und Aussteller (Rechnername des
   Panels).
3. Schritt 1: QR-Code (im Browser erzeugt) und Schlüssel zum Abtippen.
4. Schritt 2: Code eingeben → `action=confirm`. Stimmt er, wird der
   Schlüssel verschlüsselt gespeichert, `sys_user.otp_type` auf `none`
   gesetzt und acht Wiederherstellungscodes erzeugt.
5. Schritt 3: Codes anzeigen, mit Kopieren und Herunterladen als Textdatei.
   Sie erscheinen nur dieses eine Mal.

Jede Antwort der API trägt ein frisches CSRF-Token für den nächsten Aufruf.

### Deaktivieren

„Deaktivieren“ im Profil verlangt einen aktuellen App-Code oder einen
Wiederherstellungscode (`action=disable`). Danach ist der Datensatz gelöscht,
`otp_type` bleibt `none`; der E-Mail-Code lässt sich wieder wählen.

### Anmelden

1. Passwort richtig → ISPConfig löst `login` aus.
2. Das Plugin prüft: normale Anmeldung (kein `$_SESSION['s_old']`, also kein
   „Anmelden als“), Konto aus `sys_user`, Datensatz vorhanden. Dann legt es
   die Sitzung nach `s_pending` wie ISPConfig beim E-Mail-Code, setzt
   `$_SESSION['totpauth']` und leitet nach `/totpauth/verify.php`.
3. `verify.php` nimmt einen sechsstelligen App-Code (Fenster ±1 Schritt, nur
   Schritte über `last_step`) oder einen Wiederherstellungscode (wird
   verbraucht).
4. Erfolg: Sitzung zurück nach `s`, `failed` auf 0, Eintrag in `auth.log`,
   Weiterleitung ins Panel.
5. Grenzen: fünf Fehlversuche je Anmeldung beenden die Anmeldung; zehn
   Fehlversuche seit der letzten erfolgreichen Anmeldung sperren das Konto
   für die App, bis ein Admin es zurücksetzt oder ein Wiederherstellungscode
   genutzt wird. Fehlversuche landen in `auth.log`.

### Admin

In **System → Benutzer** setzt `totpauth.js` einen Knopf „App-Anmeldung
zurücksetzen“ in das Formular, wenn für das Konto eine App eingerichtet ist.
`action=admin_reset` prüft `typ = admin` der eigenen Sitzung.

### E-Mail-Code und App

Beide zugleich funktionieren nicht: Nach einem erfolgreichen E-Mail-Code löst
ISPConfig kein `login`-Ereignis aus, die App würde übersprungen. Deshalb:

- Aktivieren der App setzt `otp_type` auf `none`.
- Das Plugin setzt `otp_type` nach dem Speichern von Profil oder Benutzer
  wieder auf `none`, wenn eine App eingerichtet ist.
- `totpauth.js` sperrt die Auswahl im Profil und nennt den Grund.

## Bekannte Grenzen

- **Erzwungener Passwortwechsel.** Mit `force_password_change_days > 0`
  leitet ISPConfig vor dem `login`-Ereignis zum Passwortwechsel, und
  `force_password_change.php` gibt die Sitzung ohne das Ereignis frei. Die App
  würde dann übersprungen. Installer und Codeseite prüfen den Wert; der
  Installer bricht ab, solange er über 0 steht, das Profil zeigt einen
  Hinweis. Auf dem Produktivpanel steht er auf 0.
- **Andere Anmeldewege** (Remote-API, Fremdsysteme mit eigenem Login) laufen
  nicht über `login/index.php` und bleiben ohne App.
- Ändert ISPConfig die Plugin-Schnittstelle, fällt die Anmeldung auf Passwort
  (und gegebenenfalls E-Mail-Code) zurück. Der Installer prüft deshalb nach
  jedem Update, ob die Einhakpunkte noch da sind.

## Fehlermeldungen

Jede Meldung sagt, was passiert ist und was jetzt zu tun ist, zum Beispiel
„Der Code passt nicht. Nimm den aktuellen Code aus der App; er wechselt alle
30 Sekunden.“ oder „Zu viele Fehlversuche. Melde dich mit einem
Wiederherstellungscode an oder bitte den Administrator, die App-Anmeldung
zurückzusetzen.“ Die API antwortet immer als JSON mit `error` im Klartext;
`totpauth.js` übersetzt Netzwerkfehler und HTML-Fehlerseiten.

## Installation

`install.sh` (root, idempotent):

1. Panel-Version und die Einhakpunkte prüfen (Plugin-Loader, `login`-Ereignis,
   `js.d`), `force_password_change_days` prüfen.
2. Schlüsseldatei anlegen, **nie** überschreiben.
3. Tabelle anlegen (`CREATE TABLE IF NOT EXISTS`) über die Zugangsdaten aus
   `interface/lib/config.inc.php`.
4. Dateien in einen Zwischenordner, prüfen, dann tauschen; Rechte wie beim
   Standard-Theme.
5. Den Plugin-Cache aller Sitzungen muss niemand leeren: ISPConfig baut ihn
   bei jeder Anmeldung neu.

`uninstall.sh` entfernt Modul und Skripte; Tabelle und Schlüssel bleiben,
außer mit `--purge`.

## Prüfen

- `tests/totp-test.php`: RFC-6238-Testvektoren (SHA-1), Base32, Fenster,
  Wiederverwendung.
- `tests/store-test.php`: Verschlüsseln und Entschlüsseln, Wiederherstellungscodes.
- Nachbau: Codeseite und Modal mit echten Vorlagen im Harness des Themes.
- Ende-zu-Ende auf dem Panel mit einem eigens angelegten Testkonto, das danach
  wieder entfernt wird; Ablauf und Aufräumen im Serverprotokoll.
