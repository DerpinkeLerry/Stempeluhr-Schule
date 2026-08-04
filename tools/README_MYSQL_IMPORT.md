# Import aus der alten MySQL-Stempeluhr

Der Importer kopiert die fachlich relevanten Daten der alten `StempelUhrAdmin`-Datenbank in die neue SQLite-Datenbank.

## Sicherheitsprinzip

Die alte MySQL-Datenbank wird ausschließlich gelesen. Der Importer enthält für MySQL nur:

- `SHOW TABLES`
- `SHOW COLUMNS`
- `SELECT`
- Start und Ende einer `READ ONLY`-Transaktion

Es werden gegen MySQL keine `INSERT`-, `UPDATE`-, `DELETE`- oder `ALTER`-Anweisungen ausgeführt.

Am sichersten ist ein eigener MySQL-Benutzer, der nur `SELECT` und `SHOW VIEW` auf der alten Datenbank besitzt. Wird der normale Stempeluhr-Benutzer verwendet, schützt der Importercode zwar weiterhin vor Schreibzugriffen, ein technisch eingeschränktes Konto ist aber die bessere zweite Schutzebene.

## Importierte Daten

| Alte MySQL-Struktur | Neue SQLite-Struktur |
|---|---|
| `emploee` | `employee` |
| `emploee.weekhours` | `employee.weekly_hours` und initiales `employee_schedule` |
| `emploee.holidays` | `vacation_account` für das konfigurierte Jahr |
| normale `worktime`-Zeile | `work_session` |
| `worktime.holiday` | `absence`, Typ `VACATION` |
| `worktime.ill` | `absence`, Typ `SICK` |
| `worktime.school` | `absence`, Typ `SCHOOL` |
| `worktime.halfholiday` | halber Urlaub `AM` oder `PM` |
| `worktime.other` an einem Feiertag | kein persönlicher Abwesenheitseintrag |
| sonstiges `worktime.other` | `absence`, Typ `OTHER` |
| `break` | `break_session`, verbunden mit der Arbeitssitzung desselben Tages |
| `public_holiday` | `public_holiday` |
| optionale Überstundentabelle | `overtime_event` |

Die alten IDs werden in den `legacy_*`-Spalten gespeichert. Dadurch ist der Import wiederholbar und erzeugt beim zweiten Lauf keine Duplikate.

## Voraussetzungen

- PHP 8.2 oder neuer
- `PDO_MySQL`
- `PDO_SQLite`
- Netzwerkzugriff vom Importrechner auf den alten MySQL-Server
- Schreibrechte für die neue SQLite-Datei und den Ordner `data`

Unter XAMPP müssen in `php.ini` mindestens diese Erweiterungen aktiviert sein:

```ini
extension=pdo_mysql
extension=pdo_sqlite
extension=sqlite3
```

Danach Apache beziehungsweise die Konsole neu starten.

Prüfung:

```bat
C:\xampp\php\php.exe -m
```

In der Ausgabe müssen `pdo_mysql`, `pdo_sqlite` und `sqlite3` erscheinen.

## 1. Konfiguration eintragen

Die ausgelieferte ZIP enthält bereits `tools/mysql-import.php` mit Platzhaltern. Diese Datei öffnen und mindestens eintragen:

```php
'mysql' => [
    'host' => 'IP-DES-ALTEN-MYSQL-SERVERS',
    'port' => 3306,
    'database' => 'worktimekeeping',
    'username' => 'READONLY_BENUTZER',
    'password' => 'PASSWORT',
],
```

Die echte Datei `tools/mysql-import.php` wird durch `.gitignore` ausgeschlossen und soll nicht in Git eingecheckt werden.

## 2. Alte Struktur untersuchen

Windows/XAMPP:

```bat
tools\import_mysql.bat --inspect
```

Alternativ:

```bat
C:\xampp\php\php.exe tools\import_mysql.php --inspect
```

Linux/macOS:

```bash
./tools/import_mysql.sh --inspect
```

Dieser Schritt verändert weder MySQL noch SQLite. Der Bericht listet alle erkannten Tabellen, Spalten und Zeilenanzahlen auf.

Bekannte Standardnamen sind bereits eingetragen:

```text
emploee
worktime
break
public_holiday
```

Falls der Inspektionsbericht abweichende Namen zeigt, werden sie in `tables` oder `columns` der Konfiguration eingetragen.

## 3. Trockenlauf durchführen

Vor jedem echten Import:

```bat
tools\import_mysql.bat --dry-run
```

Der Trockenlauf:

- liest alle alten Daten,
- simuliert die komplette Zuordnung,
- prüft Konflikte mit bereits vorhandenen Zielmitarbeitern,
- prüft doppelte Legacy-IDs,
- zeigt Stichproben der Zeitumrechnung,
- schreibt nichts in beide Datenbanken.

Die Berichte liegen anschließend unter:

```text
data/import-reports/
```

Besonders zu prüfen sind die Stichproben `worktime_conversion` und `break_conversion`:

```text
raw_start       alter Rohwert
utc_start       Speicherung in der neuen Datenbank
local_start     erwartete Uhrzeit in Europe/Berlin
```

`local_start` und `local_end` müssen mit der alten Stempeluhr übereinstimmen.

### Zeiten sind genau eine oder zwei Stunden verschoben

Nur wenn alle geprüften Stichproben konstant verschoben sind, kann in `tools/mysql-import.php` korrigiert werden:

```php
'legacy_timestamp_adjustment_seconds' => 3600,
```

oder:

```php
'legacy_timestamp_adjustment_seconds' => -3600,
```

Danach erneut `--dry-run` ausführen. Wegen der uneinheitlichen Zeitbehandlung im alten C#-Projekt darf diese Einstellung nicht ohne Stichproben geändert werden.

## 4. Namens- oder Personalnummernkonflikte lösen

Standardmäßig bricht der Import ab, wenn im Ziel dieselbe Personalnummer mit einem anderen Namen existiert. Das verhindert beispielsweise, dass ein echter Mitarbeiter versehentlich mit dem mitgelieferten Demo-Mitarbeiter `1000` zusammengeführt wird.

Möglichkeiten:

1. Demo-Datensatz vor dem Produktivimport in einer Arbeitskopie entfernen oder korrekt umnummerieren.
2. Eine explizite Zuordnung konfigurieren:

```php
'employee_target_map' => [
    '123' => 'M-00123',
],
```

Links steht die alte Personalnummer, rechts die bereits vorhandene Ziel-Personalnummer.

3. Eine alte Personalnummer bewusst überspringen:

```php
'skip_source_personnel_numbers' => ['9999'],
```

Die Einstellung `employee_name_conflict_policy => 'merge'` sollte nur nach manueller Prüfung verwendet werden.

## 5. Neuanwendung stoppen

Vor dem echten Import:

- Apache beziehungsweise den PHP-Server der neuen Stempeluhr stoppen,
- sicherstellen, dass niemand in der neuen Stempeluhr bucht,
- die alte Stempeluhr darf weiterlaufen, sollte für einen finalen Stichtagsimport aber ebenfalls kurz gesperrt werden.

Der Importer erstellt einen konsistenten MySQL-Lesesnapshot. Ein fester Stichtag verhindert trotzdem, dass unmittelbar nach dem Snapshot noch neue Buchungen im alten System entstehen.

## 6. Echten Import ausführen

```bat
tools\import_mysql.bat --execute
```

Vor dem ersten Schreibvorgang erstellt der Importer automatisch eine konsistente Sicherung neben der Ziel-Datenbank:

```text
data/stempeluhr.before-legacy-import-YYYYMMDD-HHMMSS.sqlite
```

Danach werden alle Änderungen in **einer SQLite-Transaktion** ausgeführt. Bei einem Fehler wird die komplette Transaktion zurückgerollt.

`--no-backup` existiert nur für Sonderfälle und wird nicht empfohlen.

## 7. Ergebnis prüfen

Nach einem erfolgreichen Import:

1. Textbericht unter `data/import-reports/` öffnen.
2. Es dürfen keine Fehler enthalten sein.
3. Neue Stempeluhr starten.
4. Mehrere bekannte Mitarbeiter prüfen.
5. Je einen Winter- und Sommertag vergleichen.
6. Urlaub, Krankheit, Schule und halbe Urlaubstage prüfen.
7. Wochenzettel eines alten vollständigen Monats mit dem alten System vergleichen.

Anschließend nochmals ausführen:

```bat
tools\import_mysql.bat --dry-run
```

Bereits importierte Datensätze sollten nun überwiegend als `skipped_existing_legacy` oder `merged` erscheinen. Das bestätigt den Duplikatschutz.

## Import eines begrenzten Zeitraums

Zum Testen kann ein Datumsbereich angegeben werden:

```bat
tools\import_mysql.bat --dry-run --from-date=2025-01-01 --to-date=2025-01-31
```

Beim späteren Vollimport die beiden Optionen weglassen.

## Urlaubsanspruch

`emploee.holidays` besitzt im alten System kein eigenes Jahr. Deshalb wird der Wert nur dem konfigurierten Jahr zugeordnet:

```php
'vacation_entitlement_year' => 2026,
```

Ein bereits manuell gepflegter, von null verschiedener Anspruch in der neuen Datenbank bleibt mit der Standardregel `fill-empty` erhalten. Der alte Wert wird zusätzlich in `legacy_entitlement_days` dokumentiert.

## Halbe Urlaubstage

Das alte Feld `halfholiday` enthält keine direkte Information, ob Vormittag oder Nachmittag frei war. Der Importer leitet die Seite aus der vorhandenen Arbeitszeit ab:

- Arbeitsbeginn ab 12:00 Uhr: Urlaub vormittags (`AM`)
- Arbeitsende bis 12:00 Uhr: Urlaub nachmittags (`PM`)
- nicht eindeutig: konfigurierter Fallback und Warnung im Bericht

Jede Warnung dieser Art muss nach dem Import manuell geprüft werden.

## Nicht erkannte Tabellen

Der Bericht nennt jede MySQL-Tabelle, die keinem bekannten Zielbereich zugeordnet wurde. Sie wird weder verändert noch stillschweigend verworfen. Falls dort weitere fachliche Daten liegen, kann ihre Zuordnung anhand des Inspektionsberichts ergänzt werden.

## Wiederholung und Rückkehr

- Der Import kann beliebig oft zuerst als Trockenlauf ausgeführt werden.
- Ein erfolgreicher Echtimport ist durch Legacy-IDs idempotent.
- Die alte MySQL-Datenbank bleibt unverändert.
- Die automatisch erzeugte SQLite-Sicherung ermöglicht die Rückkehr zum Zustand direkt vor dem Import.
- Nach der finalen Umstellung sollte die alte Datenbank dauerhaft als schreibgeschütztes Archiv gesichert werden.
