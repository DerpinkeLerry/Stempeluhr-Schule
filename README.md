# Wepro Zeiterfassung

Moderne Arbeitszeiterfassung mit PHP und SQLite im visuellen Stil der Wepro GmbH.

## Design

Die Oberfläche wurde vollständig neu gestaltet. Sie verwendet eine Wepro-inspirierte Markenwelt mit Marineblau, warmem Orange, großzügiger Typografie, feinen Liniengrafiken und zurückhaltenden Animationen. Alle Hauptansichten sind responsiv und für Desktop, Tablet und Smartphone optimiert.

Für die Darstellung werden Bootstrap 5.3 und Montserrat über CDN geladen. Ohne Internetverbindung greift die Schrift automatisch auf installierte Systemschriften zurück; die Kernfunktionen der Anwendung bleiben davon unberührt.

## Funktionen

- Login für Admin und Mitarbeiter
- Arbeitsbeginn und Feierabend
- Pausen starten und beenden; während einer Pause steht die Arbeitszeit
- An allen Tagen außer Freitag 30 Minuten Grundpause plus Zeitgutschrift bei Arbeitsbeginn vor 08:00 Uhr; freitags nur die Zeit vor 08:00 Uhr und sonst 0 Minuten Pause
- Arbeitsbeginn frühestens ab 07:30 Uhr
- Vergessener Feierabend wird am Folgetag mit Warnung korrigiert: 17:00 Uhr, freitags 12:00 Uhr
- Mitarbeiter mit Zeitzonen-Auswahl anlegen
- Mitarbeiterdaten, Rolle, Zeitzone und Passwort als Admin bearbeiten
- Mitarbeiter im Bearbeiten-Dialog inklusive zugehöriger Zeiten und Abwesenheiten löschen
- Arbeitszeiten und Abwesenheiten anzeigen
- Abwesenheiten eintragen, bearbeiten und löschen
- Trotz eingetragener Abwesenheit einstempeln; der Arbeitstag überschreibt nur die Abwesenheit dieses Tages
- Scrollbare Mitarbeiterauswahl für Wochenzettel mit fest erreichbarem PDF-Button
- Wochenzettel für alle oder ausgewählte Mitarbeiter als PDF
- Unterschriftsfeld auf jedem Wochenzettel
- Feiertage für Bayern und Kaufbeuren von 2025 bis 2035

## Schnell starten

Voraussetzungen:

- PHP 8.1 oder neuer
- PDO_SQLite muss aktiviert sein

Im Projektordner ausführen:

```bash
php -S 127.0.0.1:8000 -t public public/router.php
```

Danach im Browser öffnen:

```text
http://127.0.0.1:8000/
```

Unter Windows kann auch `start.bat` benutzt werden.

## Mit XAMPP

1. Den Ordner nach `htdocs` kopieren.
2. Apache starten.
3. Im Browser `http://localhost/Stempeluhr-Neu-SCHULE/public/` öffnen.
4. Falls eine Datenbank-Fehlermeldung kommt, in der `php.ini` die Erweiterungen `pdo_sqlite` und `sqlite3` aktivieren.

Die Datenbank wird beim ersten Aufruf automatisch im Ordner `data` erstellt.


## Datenbank

Die Datenbank liegt in:

```text
data/stempeluhr.sqlite
```

Urlaub, Krankheit, Schule und sonstige Abwesenheiten stehen alle in der Tabelle `absence`.
Die Spalte `type` legt die Art fest:

```text
VACATION = Urlaub
SICK = Krank
SCHOOL = Schule
OTHER = Sonstiges
```

Pausen stehen in `break_session`. Die Pausendauer wird von der Arbeitszeit abgezogen.

## Wochenzettel

Als Admin gibt es in der Mitarbeiterübersicht den Button `Wochenzettel PDF`.
Dort können alle oder einzelne Mitarbeiter ausgewählt werden. Das PDF nimmt immer die aktuelle Woche von Montag bis Sonntag. Für jeden Mitarbeiter wird eine eigene Seite mit Unterschriftsfeld erstellt.
Die Mitarbeiterauswahl ist scrollbar; der Button `PDF öffnen` bleibt im Kopf des Fensters erreichbar.

Für das PDF wird keine zusätzliche PHP-Erweiterung und kein Composer-Paket gebraucht.

## Demo-Logins

Admin:

```text
admin@schule.local
admin123
```

Mitarbeiter:

```text
max@schule.local
max123
```

Die Demo-Passwörter sollten später geändert werden.

## Ordner

```text
app/          PHP-Code und Views
database/     Datenbankschema
data/         SQLite-Datenbank
public/       Öffentlicher Webordner
```

## Imagick-Warnung

Imagick wird nicht gebraucht. Wenn beim Start eine Warnung zu `php_imagick.dll` kommt, kann die Imagick-Zeile in der `php.ini` mit einem Semikolon auskommentiert werden.

## Wochenzettel und Abwesenheiten

Im Wochenzettel werden keine Buchungsanzahlen angezeigt. Bei Urlaub oder Krankheit werden Montag bis Donnerstag jeweils 8 Stunden 30 Minuten und freitags 4 Stunden Arbeitszeit angerechnet. In der Bemerkung steht weiterhin Urlaub oder Krank. Samstage und Sonntage werden nicht automatisch angerechnet.

Im Wochenzettel sind Abwesenheiten farblich markiert: Krank hellgelb, Urlaub hellblau und Schule oder Sonstiges hellgrün. Normale Arbeitstage bleiben ohne Hintergrundfarbe.

Wird an einem Abwesenheitstag eingestempelt, wird dieser Tag automatisch aus der Abwesenheit entfernt. Bei einem mehrtägigen Zeitraum bleiben die Tage davor und danach als getrennte Abwesenheiten erhalten.

## Tests

Die reinen Zeitregeln können ohne Datenbank geprüft werden:

```bash
php tests/TimeRulesTest.php
```

Mit aktiviertem `PDO_SQLite` stehen zusätzliche Integrationstests zur Verfügung:

```bash
php tests/TimeClockServiceTest.php
```
