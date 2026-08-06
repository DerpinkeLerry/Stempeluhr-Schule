# Wepro Zeiterfassung

PHP-Anwendung zur Arbeitszeit-, Pausen-, Abwesenheits- und Urlaubsverwaltung mit SQLite.

## Funktionen

- Login für Administration und Mitarbeiter
- Arbeitsbeginn, Feierabend und beliebig viele Pausen
- individueller, historisierter Arbeitszeiten-Planer pro Mitarbeiter und Wochentag
- Standardplan bei der Anlage: Montag bis Donnerstag 8:30 Stunden, Freitag 4:00 Stunden
- Mitarbeiterstammdaten mit eindeutiger Personalnummer
- aktive und inaktive Beschäftigte ohne Verlust ihrer Historie
- Urlaubskonto pro Kalenderjahr mit Anspruch, Übertrag und Korrektur
- gemeinsamer Urlaubskalender für alle aktiven Mitarbeiter als kompakte, bildschirmbreite Jahresübersicht
- direkte Urlaubsauswahl für Administratoren per Klick oder Ziehen über die Tagesfelder oder den Tageskopf
- dauerhaftes Archiv für neue Urlaubs-, Verschiebungs- und Löschanträge mit Genehmigung oder Ablehnung durch die Administration
- Mitarbeiter können genehmigten, noch nicht begonnenen Urlaub zur Verschiebung oder Löschung einreichen
- automatische Übernahme ungenutzten Jahresurlaubs ins Folgejahr; Nutzung des Übertrags nur bis 31. März
- serverseitige Sperre bei nicht ausreichendem Urlaubsbestand
- ganze sowie halbe Urlaubstage vormittags oder nachmittags
- Krankheit, Schule und sonstige Abwesenheiten
- Wochen-, Monats- und Jahresnachweise als PDF ohne Sollzeit- oder Differenzspalten; jede Person erhält genau eine eigene, druckfertige Seite
- deutliche orange Pausenansicht, damit ein laufender Pausenstatus auch aus größerer Entfernung erkennbar ist
- Feiertage für Bayern/Kaufbeuren von 2025 bis 2035

## Voraussetzungen

- PHP 8.2 oder neuer
- PDO und PDO_SQLite
- Schreibrechte für den Ordner `data`

## Starten

```bash
php -S 127.0.0.1:8000 -t public public/router.php
```

Danach öffnen:

```text
http://127.0.0.1:8000/
```

Unter Windows kann `start.bat` verwendet werden.

## XAMPP

1. Projektordner nach `htdocs` kopieren.
2. In `php.ini` die Erweiterungen `pdo_sqlite` und `sqlite3` aktivieren.
3. Apache neu starten.
4. `http://localhost/<projektordner>/public/` öffnen.

Als Document Root sollte in einer produktiven Installation direkt der Ordner `public` verwendet werden. Dadurch ist die Datenbankdatei nicht über den Webserver erreichbar.

## Datenbank und automatische Migration

Die Datenbank liegt unter:

```text
data/stempeluhr.sqlite
```

Das Projekt verwendet Schema-Version 6. Eine ältere Projektdatenbank wird beim ersten Aufruf automatisch strukturell migriert. Version 6 aktiviert die individuellen, historisierten Wochenpläne wieder. Bereits vorhandene Arbeitszeitmodelle werden nicht mehr automatisch überschrieben. Vor jedem Update muss die SQLite-Datei trotzdem separat gesichert werden.

Die vollständige Tabellenbeschreibung steht in:

```text
database/STRUCTURE.md
```

Die mitgelieferte Datenbank ist bereits auf Version 6 vorbereitet.

## Wichtige Datenregeln

- Personalnummern sind für neu angelegte Mitarbeiter Pflicht und eindeutig.
- Mitarbeiter werden beim Entfernen nur deaktiviert. Historische Daten bleiben erhalten.
- Neue Mitarbeiter starten mit 38 Wochenstunden: Montag bis Donnerstag jeweils 8:30 Stunden, Freitag 4:00 Stunden.
- Jeder Wochentag kann individuell angepasst oder vollständig als freier Tag ausgeschaltet werden. Änderungen gelten ab einem wählbaren Datum und verändern keine Vergangenheit.
- Urlaub, Krankheit, Schule, sonstige Abwesenheiten und Feiertage werden an eingeplanten Arbeitstagen mit der individuell hinterlegten Tageszeit angerechnet.
- Bei mehr als 6 geplanten Stunden werden 30 Minuten Pausenanspruch berücksichtigt. Arbeitsbeginn vor 08:00 Uhr erhöht diesen Wert zusätzlich um die entsprechende Frühstartzeit.
- Urlaub wird aus den genehmigten Abwesenheiten berechnet; halbe Tage zählen als `0,5`.
- Ungenutzter regulärer Jahresurlaub wird automatisch ins Folgejahr übertragen. Dieser Übertrag kann nur für Urlaubstage bis einschließlich 31. März verwendet werden und verfällt danach.
- Neue Urlaubs- und Verschiebungsanträge sowie direkte Urlaubseinträge werden blockiert, sobald der verfügbare Bestand nicht ausreicht.
- Neue Urlaubs-, Änderungs- und Löschanträge bleiben nach Genehmigung oder Ablehnung vollständig im Admin-Archiv erhalten.
- Ein ganzer Abwesenheitstag wird beim tatsächlichen Einstempeln für diesen Tag entfernt. Ein halber Urlaubstag bleibt bestehen.
- Zeitstempel in `work_session` und `break_session` werden in UTC gespeichert.

## Demo-Zugänge der mitgelieferten Datenbank

Administration:

```text
admin@schule.local
admin123
```

Mitarbeiter:

```text
max@schule.local
max123
```

Diese Passwörter müssen vor einem Unternehmenseinsatz geändert werden. Die automatische Erzeugung von Demo-Benutzern ist in `app/config.php` bereits deaktiviert. Die vorhandenen Demo-Konten stammen aus der mitgelieferten Datenbank und müssen vor dem Unternehmenseinsatz geändert oder ersetzt werden.

## Tests

Reine Regeltests:

```bash
php tests/TimeRulesTest.php
php tests/VacationCalendarSegmentsTest.php
php tests/TimeReportPdfTest.php
```

Integrationstests mit aktiviertem PDO_SQLite:

```bash
php tests/TimeClockServiceTest.php
php tests/VacationWorkflowTest.php
```

Zusätzlich empfiehlt sich nach einer Datenbankmigration:

```sql
PRAGMA integrity_check;
PRAGMA foreign_key_check;
```

## Ordner

```text
app/                 PHP-Code, Services und Views
database/schema.sql  vollständiges Schema Version 6
database/STRUCTURE.md Datenmodell und Migrationshinweise
data/                SQLite-Datenbank
public/              einziger öffentlicher Webordner
tests/               Regel- und Integrationstests
```
