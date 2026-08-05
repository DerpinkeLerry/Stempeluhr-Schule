# Wepro Zeiterfassung

PHP-Anwendung zur Arbeitszeit-, Pausen-, Abwesenheits- und Urlaubsverwaltung mit SQLite.

## Funktionen

- Login für Administration und Mitarbeiter
- Arbeitsbeginn, Feierabend und beliebig viele Pausen
- konfigurierbare Zeitregeln pro Wochentag
- Mitarbeiterstammdaten mit eindeutiger Personalnummer
- aktive und inaktive Beschäftigte ohne Verlust ihrer Historie
- historisierte Arbeitszeitmodelle pro Wochentag
- Urlaubskonto pro Kalenderjahr mit Anspruch, Übertrag und Korrektur
- gemeinsamer Urlaubskalender für alle aktiven Mitarbeiter als kompakte, bildschirmbreite Jahresübersicht
- direkte Urlaubsauswahl für Administratoren per Klick oder Ziehen über die Tagesfelder
- dauerhaftes Urlaubsantragsarchiv mit Genehmigung oder Ablehnung durch die Administration
- automatische Übernahme ungenutzten Jahresurlaubs ins Folgejahr; Nutzung des Übertrags nur bis 31. März
- serverseitige Sperre bei nicht ausreichendem Urlaubsbestand
- ganze sowie halbe Urlaubstage vormittags oder nachmittags
- Krankheit, Schule und sonstige Abwesenheiten
- Wochenzettel als PDF mit Sollzeit und Differenz
- Feiertage für Bayern/Kaufbeuren von 2025 bis 2035
- vorbereitete Legacy-IDs für den späteren Import aus der alten MySQL-Stempeluhr

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

Das Projekt verwendet Schema-Version 3. Eine ältere Projektdatenbank wird beim ersten Aufruf automatisch strukturell migriert. Version 3 ergänzt das dauerhafte Urlaubsantragsarchiv. Vor jedem Update muss die SQLite-Datei trotzdem separat gesichert werden.

Die vollständige Tabellenbeschreibung steht in:

```text
database/STRUCTURE.md
```

Die mitgelieferte Datenbank wurde bereits auf Version 2 umgestellt. Der spätere Import aus der alten MySQL-Stempeluhr ist noch nicht enthalten.

## Wichtige Datenregeln

- Personalnummern sind für neu angelegte Mitarbeiter Pflicht und eindeutig.
- Mitarbeiter werden beim Entfernen nur deaktiviert. Historische Daten bleiben erhalten.
- Änderungen am Wochenplan gelten ab einem wählbaren Datum und überschreiben keine Vergangenheit.
- Urlaub wird aus den genehmigten Abwesenheiten berechnet; halbe Tage zählen als `0,5`.
- Ungenutzter regulärer Jahresurlaub wird automatisch ins Folgejahr übertragen. Dieser Übertrag kann nur für Urlaubstage bis einschließlich 31. März verwendet werden und verfällt danach.
- Urlaubsanträge und direkte Urlaubseinträge werden blockiert, sobald der verfügbare Bestand nicht ausreicht.
- Urlaubsanträge bleiben nach Genehmigung oder Ablehnung vollständig im Admin-Archiv erhalten.
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

Reine Zeitregeln und Importlogik:

```bash
php tests/TimeRulesTest.php
php tests/LegacyImportTransformTest.php
php tests/LegacyImporterSourceSafetyTest.php
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
database/schema.sql  vollständiges Schema Version 3
database/STRUCTURE.md Datenmodell und Migrationshinweise
data/                SQLite-Datenbank
public/              einziger öffentlicher Webordner
tests/               Regel- und Integrationstests
```

## Import aus der alten MySQL-Stempeluhr

Ein read-only MySQL-Importer ist unter `tools/import_mysql.php` enthalten. Er kopiert Mitarbeiter, Arbeitszeiten, Pausen, Abwesenheiten, Urlaubsansprüche, Feiertage und optional Überstunden in die neue SQLite-Struktur. Die alte MySQL-Datenbank wird nicht verändert.

Die vollständige Schritt-für-Schritt-Anleitung steht in:

```text
tools/README_MYSQL_IMPORT.md
```

Grundablauf:

```bat
tools\mysql-import.php bearbeiten
tools\import_mysql.bat --inspect
tools\import_mysql.bat --dry-run
tools\import_mysql.bat --execute
```

Der Echtimport erstellt automatisch eine SQLite-Sicherung und läuft innerhalb einer Transaktion. Legacy-IDs verhindern Duplikate bei wiederholten Läufen.
