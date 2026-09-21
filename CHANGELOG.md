# Changelog

Alle nennenswerten Änderungen.
Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung [Semantic Versioning](https://semver.org/lang/de/).

## [0.1.0] — 2026-09-21

Erste Fassung.

### Neu

- Anmeldung mit Authenticator-App (TOTP, RFC 6238) nach dem Passwort, als
  Plugin am Anmeldeereignis von ISPConfig, ohne Änderung an Dateien von
  ISPConfig.
- Einrichten im Profil (Werkzeuge → Einstellungen) in einem Fenster: QR-Code,
  Bestätigungscode, acht Wiederherstellungscodes zum Kopieren oder Speichern.
- Deaktivieren mit App- oder Wiederherstellungscode; Admins setzen die App
  eines Kontos unter System → Benutzer zurück.
- Schlüssel verschlüsselt und an das Konto gebunden gespeichert,
  Wiederherstellungscodes als Hash; jeder Code gilt einmal.
- Grenzen: fünf Fehlversuche je Anmeldung, zehn seit der letzten
  erfolgreichen Anmeldung sperren die App.
- Einträge im `auth.log` für Anmeldungen, Fehlversuche, Einrichten und
  Zurücksetzen.
- Texte deutsch und englisch.
- Installer mit Prüfung der Einhakpunkte und des erzwungenen
  Passwortwechsels, Deinstallation mit und ohne Daten, Tests für TOTP-Kern,
  Speicher, Installer und die ganze Anmeldung gegen ein echtes Panel.
