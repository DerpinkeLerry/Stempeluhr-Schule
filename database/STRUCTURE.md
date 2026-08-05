# Datenstruktur – Schema Version 3

Die Anwendung verwendet SQLite. Die Schema-Version steht in `PRAGMA user_version` und ist aktuell `3`.
Beim ersten Start mit einer älteren Projektdatenbank führt `app/db.php` die strukturelle Migration automatisch aus. Vor einem produktiven Update muss trotzdem eine Dateikopie von `data/stempeluhr.sqlite` erstellt werden.

## Zentrale Tabellen

### `employee`
Stammdaten und Zugang eines Mitarbeiters.

Wichtige Felder:

- `personnel_number`: eindeutige Personalnummer und späterer Zuordnungsschlüssel für den Altimport
- `legacy_employee_id`: technische ID aus dem Altsystem
- `department`, `phone`: weitere Stammdaten
- `weekly_hours`: aktuelle Wochenstundenzahl als Übersichtswert
- `is_trainee`, `special_time`: Merkmale aus der alten Verwaltung
- `active`: Beschäftigungsstatus
- `login_enabled`: Anmeldung an der Stempeluhr erlaubt

Mitarbeiter werden nicht mehr physisch gelöscht. Beim Deaktivieren bleiben Zeiten, Pausen, Abwesenheiten und Urlaubskonten erhalten.

### `employee_schedule`
Historisierte Sollarbeitszeit pro Wochentag.

- `valid_from`, `valid_to`: Gültigkeitszeitraum
- `weekday`: ISO-Wochentag 1 bis 7
- `target_minutes`: Sollzeit des Tages
- `planned_start`, `planned_end`: optionale Planzeiten
- `source`: Ursprung, zum Beispiel `web`, `system` oder später `legacy-import`

Eine Änderung legt eine neue Version ab. Vergangene Auswertungen behalten dadurch das damals gültige Soll.

### `vacation_account`
Urlaubskonto eines Mitarbeiters pro Kalenderjahr.

- `entitlement_days`: Jahresanspruch
- `carryover_days`: Übertrag aus dem Vorjahr
- `adjustment_days`: manuelle Korrektur
- `legacy_entitlement_days`: ursprünglicher Anspruch aus der alten Datenbank

Der genommene Urlaub wird nicht redundant gespeichert, sondern aus `absence` berechnet. Halbe Urlaubstage zählen als `0,5` Tage. Gesetzliche Feiertage und arbeitsfreie Wochentage werden nicht abgezogen.

Ungenutzter regulärer Anspruch wird automatisch in das unmittelbar folgende Kalenderjahr übertragen. Dieser Übertrag steht nur für Urlaubstage bis einschließlich 31. März zur Verfügung. Danach wird ein nicht genutzter Rest als verfallen ausgewiesen und kann nicht für spätere Urlaubstage verwendet werden. Übertrag aus einem noch älteren Jahr wird nicht erneut übertragen.

### `work_session`
Tatsächliche Arbeitszeiträume.

- `started_at`, `ended_at`: UTC-Zeitstempel
- `source`: Erfassungsquelle
- `legacy_worktime_id`: eindeutige Referenz auf den später importierten Altdatensatz

### `break_session`
Pausen einer Arbeitssitzung.

- `work_session_id`: zugehörige Arbeitssitzung
- `legacy_break_id`: eindeutige Referenz aus dem Altsystem

### `absence`
Urlaub, Krankheit, Schule und sonstige Abwesenheiten.

- `type`: `VACATION`, `SICK`, `SCHOOL` oder `OTHER`
- `portion`: `FULL`, `AM` oder `PM`
- `start_date`, `end_date`: Datumsbereich
- `credit_minutes_override`: optionale feste Zeitgutschrift
- `legacy_worktime_id`: Referenz auf den alten Tagesdatensatz

`AM` und `PM` sind nur bei Urlaub und nur für ein einzelnes Datum erlaubt.

### `vacation_request`
Dauerhaftes Archiv aller durch Mitarbeiter eingereichten Urlaubsanträge.

- `start_date`, `end_date`, `portion`: beantragter Urlaubszeitraum
- `note`: Hinweis des Mitarbeiters
- `status`: `PENDING`, `APPROVED`, `REJECTED` oder `CANCELLED`
- `requested_at`: Zeitpunkt der Antragstellung
- `decided_at`, `decided_by`, `decision_note`: Entscheidung und Nachvollziehbarkeit
- `absence_id`: optionale Verknüpfung zum erzeugten Urlaubseintrag

Bei einer Genehmigung wird ein Datensatz in `absence` angelegt. Wird dieser Urlaubseintrag später gelöscht, setzt SQLite nur `absence_id` auf `NULL`; der Antrag selbst bleibt im Archiv erhalten. Mitarbeiter werden in der Anwendung ausschließlich deaktiviert und nicht physisch gelöscht, damit auch deren Antragshistorie aufrufbar bleibt.

### `public_holiday`
Gesetzliche Feiertage pro Region. Die vorhandene Region ist `DE-BY-KF`.

### `work_rule`
Allgemeine Regeln pro Wochentag:

- frühester Arbeitsbeginn
- Ende des Frühstart-Pausenbonus
- automatische Endzeit bei vergessenem Feierabend
- Grundpause
- Standard-Sollzeit als Rückfallwert

### `overtime_event`
Vorbereitung für besondere Überstunden- oder Zeitgutschrift-Tage aus der alten Verwaltung.

### `import_batch`
Protokolliert später jeden Lauf des Alt-Datenbank-Importers. Der eigentliche Importer ist bewusst noch nicht Bestandteil dieses Schritts.

## Legacy-Felder

Die Spalten mit Präfix `legacy_` bilden die technische Brücke zur alten MySQL-Datenbank. Sie sind eindeutig, damit ein Import wiederholt werden kann, ohne dieselben Datensätze doppelt anzulegen.

## Sicheres Vorgehen beim Update

1. Webanwendung kurz sperren.
2. `data/stempeluhr.sqlite` kopieren und separat sichern.
3. Neue Projektversion einspielen.
4. Anwendung einmal öffnen; dadurch läuft die Strukturmigration.
5. `PRAGMA integrity_check` und `PRAGMA foreign_key_check` ausführen.
6. Erst danach die Anwendung wieder freigeben.

## Noch nicht enthalten

Der MySQL-zu-SQLite-Importer für die alte Stempeluhr wird im nächsten Schritt separat gebaut. Das jetzige Schema enthält bereits alle dafür vorgesehenen Zuordnungsfelder und Protokolltabellen.

## Legacy-MySQL-Importer

Der Importer unter `tools/import_mysql.php` verwendet die vorhandenen Legacy-Spalten als technische Idempotenzschlüssel:

| Zieltabelle | Import-Schlüssel |
|---|---|
| `employee` | `legacy_employee_id` |
| `work_session` | `legacy_worktime_id` |
| `absence` | `legacy_worktime_id` |
| `break_session` | `legacy_break_id` |
| `public_holiday` | `legacy_public_holiday_id` |
| `overtime_event` | `legacy_overtime_id` |

Eine alte `worktime`-Zeile kann bei einem halben Urlaubstag gleichzeitig eine `work_session` und eine `absence` erzeugen. Beide Tabellen dürfen deshalb dieselbe alte Worktime-ID verwenden; die Eindeutigkeit gilt jeweils innerhalb der Zieltabelle.

Importierte Datensätze tragen `source = 'legacy-mysql'`. Ein Importlauf wird zusätzlich in `import_batch` protokolliert. Die MySQL-Quelle wird ausschließlich gelesen; alle Zieländerungen laufen in einer SQLite-Transaktion.

Die Bedienungs- und Prüfhinweise stehen in `tools/README_MYSQL_IMPORT.md`.
