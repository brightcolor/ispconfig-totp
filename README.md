# TOTP für ISPConfig

Anmeldung mit Authenticator-App (TOTP nach RFC 6238) als zweiter Faktor für
ISPConfig 3.3. ISPConfig selbst kennt nur einen Code per E-Mail. Dieses
Projekt ergänzt die App als Plugin und ändert keine Datei von ISPConfig.

**Stand: 0.1.0.** Geprüft gegen ISPConfig 3.3.1p1, Ende-zu-Ende auf einem
laufenden Panel.

## So sieht es aus

- **Einrichten im Profil.** Unter **Werkzeuge → Einstellungen** steht unter
  „Zwei-Faktor-Authentifizierung“ eine Zeile „Authenticator-App“. Der Knopf
  „Einrichten“ öffnet ein Fenster in drei Schritten: QR-Code scannen (der
  Schlüssel steht zum Abtippen darunter), einen Code bestätigen, acht
  Wiederherstellungscodes sichern.
- **Anmelden.** Nach dem Passwort fragt das Panel den sechsstelligen Code aus
  der App. Ein Wiederherstellungscode geht auch, jeder genau einmal.
- **Deaktivieren** im Profil mit einem Code aus der App oder einem
  Wiederherstellungscode.
- **Admins** finden unter **System → Benutzer** im Formular eines Kontos den
  Knopf „App-Anmeldung zurücksetzen“, wenn dort eine App eingerichtet ist.

Mit aktiver App bleibt der Code per E-Mail aus. ISPConfig lässt beide nicht
zusammen zu: Nach einem E-Mail-Code öffnet es die Sitzung, ohne dass ein
Plugin noch eingreifen kann.

## Sicherheit

- Der Schlüssel jeder App liegt verschlüsselt in der Tabelle `totpauth_user`
  (XChaCha20-Poly1305, an die Benutzerkennung gebunden). Der Schlüssel dafür
  liegt in `/usr/local/ispconfig/totpauth/secret.key`, außerhalb des
  Webordners, nur für den Nutzer des Panels lesbar.
- Wiederherstellungscodes sind nur als Hash gespeichert.
- Jeder Code gilt einmal: Ein Code, der eine Anmeldung geöffnet hat, öffnet
  keine zweite. Die Uhr darf um einen Schritt (30 Sekunden) abweichen.
- Fünf falsche Codes beenden die Anmeldung. Zehn falsche Codes seit der
  letzten erfolgreichen Anmeldung sperren die App, bis ein
  Wiederherstellungscode kommt oder ein Admin sie zurücksetzt.
- Jede Anmeldung mit App, jeder Fehlversuch, jedes Einrichten und
  Zurücksetzen steht im `auth.log` des Panels.
- „Anmelden als“ aus dem Admin-Bereich fragt keinen Code: Der Admin hat sich
  selbst schon angemeldet.

## Grenzen

- **Erzwungener Passwortwechsel.** Steht unter **System →
  Systemkonfiguration → Einstellungen**, Reiter „Diverses“, der Wert „Force
  password change after X days“ über 0, schickt ISPConfig Nutzer mit altem
  Passwort vor dem Plugin zum Passwortwechsel und öffnet danach die Sitzung
  direkt. Der Installer bricht deshalb ab, solange der Wert über 0 steht, und
  Admins sehen im Profil einen Hinweis.
- **Andere Anmeldewege** (Remote-API, Fremdsysteme mit eigenem Login) laufen
  nicht über die Anmeldeseite und fragen keinen Code.
- **Mailbenutzer** melden sich über eine eigene Tabelle an und bleiben außen
  vor.

## Installation

Auf dem ISPConfig-Server als root:

```bash
sudo ./install.sh
```

Das Skript prüft zuerst, ob ISPConfig die Stellen noch hat, an denen das
Plugin einhakt (Plugin-Loader, Anmeldeereignis, `js/js.d`), und ob der
erzwungene Passwortwechsel aus ist. Dann legt es den Schlüssel an (nur beim
ersten Mal, er wird nie ersetzt), die Tabelle (als MySQL-root über den
lokalen Socket, der Datenbanknutzer des Panels darf nur lesen und schreiben)
und die Dateien:

| Ort | Inhalt |
|---|---|
| `interface/web/totpauth/` | Plugin, Codeseite, API |
| `interface/web/js/js.d/totpauth.js`, `totpauth-qr.js` | Profilzeile, Fenster, Admin-Knopf, QR-Code |
| `/usr/local/ispconfig/totpauth/secret.key` | Schlüssel für die gespeicherten Geheimnisse |
| Tabelle `totpauth_user` | eine Zeile je Konto mit App |

### Nach jedem ISPConfig-Update

```bash
sudo ./install.sh
```

Ein Update kann Dateien entfernen oder die Stellen ändern, an denen das
Plugin einhakt. Das Skript prüft sie erneut und legt die Dateien wieder an.

### Entfernen

```bash
sudo ./uninstall.sh            # Modul weg, Tabelle und Schlüssel bleiben
sudo ./uninstall.sh --purge    # auch Tabelle und Schlüssel, alle Apps sind dann weg
```

**Die Schlüsseldatei gehört in die Sicherung.** Ohne sie lässt sich keine
gespeicherte App mehr prüfen; dann muss ein Admin jede App zurücksetzen.

## Wie es einhakt

- ISPConfig lädt Plugins aus `web/<modul>/lib/plugin.d/`. Das Plugin meldet
  sich für das Ereignis `login`, das `login/index.php` nach geprüftem Passwort
  auslöst. Hat das Konto eine App, legt es die Sitzung zur Seite, wie
  ISPConfig es beim E-Mail-Code tut, und leitet auf `/totpauth/verify.php`.
  Erst nach dem richtigen Code wird die Sitzung freigegeben.
- ISPConfig lädt jede Datei aus `web/js/js.d/` auf jeder Panel-Seite. Das
  Skript erkennt dort das Profil und die Benutzerverwaltung und fügt Zeile,
  Fenster und Knopf ein. Das klappt mit jedem Theme.
- Nach dem Speichern von Profil oder Benutzer setzt das Plugin den E-Mail-Code
  zurück auf „aus“, solange eine App eingerichtet ist.

## Prüfen

```bash
php tests/totp-test.php      # RFC-6238-Testwerte, Base32, Zeitfenster, Wiederverwendung
php tests/store-test.php     # Verschlüsselung, Wiederherstellungscodes, Zähler
sh tests/install-test.sh     # Installer und Deinstallation gegen ein nachgestelltes Panel
```

`tests/e2e.sh` spielt die ganze Anmeldung gegen ein echtes Panel durch, mit
einem eigens angelegten Konto ohne App:

```bash
TOTP_E2E_PASSWORD=... sh tests/e2e.sh https://panel.example.test totp-test
```

`harness/` führt die echte `api.php` mit nachgebildetem ISPConfig gegen eine
SQLite-Datei aus und zeigt Profilzeile und Fenster im Browser:

```bash
php -S 127.0.0.1:8142 -t harness harness/router.php
```

## Lizenz

BSD-2-Clause wie ISPConfig, siehe `LICENSE`. `jsd/totpauth-qr.js` ist
[qrcode-generator](https://www.npmjs.com/package/qrcode-generator) 1.4.4 von
Kazuhiko Arase unter der MIT-Lizenz, unverändert.
