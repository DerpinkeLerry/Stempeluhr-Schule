# Datenstruktur – Schema Version 6

Die Anwendung verwendet SQLite. Die Schema-Version steht in `PRAGMA user_version` und ist aktuell `6`. Beim ersten Start mit einer älteren Projektdatenbank führt `app/db.php` die strukturelle Migration automatisch aus. Vor einem produktiven Update muss trotzdem eine Dateikopie von `data/stempeluhr.sqlite` erstellt werden.

## Zentrale Tabellen

### `employee`
Stammdaten und Zugang eines Mitarbeiters.

Wichtige Felder:

- `personnel_number`: eindeutige Personalnummer
- `department`, `phone`: weitere Stammdaten
- `weekly_hours`: aus dem aktuell gespeicherten Wochenplan berechnete Gesamtstundenzahl
- `is_trainee`: Kennzeichnung als Auszubildender
- `special_time`: technisches Alt-Feld; die Anwendung setzt es fest auf `0`
- `active`: Beschäftigungsstatus
- `login_enabled`: Anmeldung an der Stempeluhr erlaubt

Mitarbeiter werden nicht physisch gelöscht. Beim Deaktivieren bleiben Zeiten, Pausen, Abwesenheiten und Urlaubskonten erhalten.

### `employee_schedule`
Historisierte Sollarbeitszeit pro Mitarbeiter und Wochentag.

- `valid_from`, `valid_to`: Gültigkeitszeitraum des Modells
- `weekday`: ISO-Wochentag 1 bis 7
- `target_minutes`: geplante Arbeitszeit des Tages; `0` bedeutet regelmäßig frei
- `planned_start`, `planned_end`: technische Planzeiten für automatische Korrekturen
- `source`: Ursprung, zum Beispiel `web` oder `system`

Neue Mitarbeiter erhalten standardmäßig Montag bis Donnerstag `510` Minuten und Freitag `240` Minuten. Änderungen legen ab dem gewählten Datum eine neue Version an. Zeitnachweise, Urlaubstage, Feiertage und Abwesenheitsgutschriften verwenden immer das für das jeweilige Datum gültige Modell.

### `vacation_account`
Urlaubskonto eines Mitarbeiters pro Kalenderjahr.

- `entitlement_days`: Jahresanspruch
- `carryover_days`: Übertrag aus dem Vorjahr
- `adjustment_days`: manuelle Korrektur

Der genommene Urlaub wird nicht redundant gespeichert, sondern aus `absence` berechnet. Halbe Urlaubstage zählen als `0,5` Tage. Gesetzliche Feiertage und arbeitsfreie Wochentage werden nicht abgezogen.

Ungenutzter regulärer Anspruch wird automatisch in das unmittelbar folgende Kalenderjahr übertragen. Dieser Übertrag steht nur für Urlaubstage bis einschließlich 31. März zur Verfügung. Danach wird ein nicht genutzter Rest als verfallen ausgewiesen und kann nicht für spätere Urlaubstage verwendet werden. Übertrag aus einem noch älteren Jahr wird nicht erneut übertragen.

### `work_session`
Tatsächliche Arbeitszeiträume.

- `started_at`, `ended_at`: UTC-Zeitstempel
- `source`: Erfassungsquelle
- `note`: optionale interne Notiz

### `break_session`
Pausen einer Arbeitssitzung.

- `work_session_id`: zugehörige Arbeitssitzung
- `started_at`, `ended_at`: UTC-Zeitstempel
- `source`: Erfassungsquelle

### `absence`
Urlaub, Krankheit, Schule und sonstige Abwesenheiten.

- `type`: `VACATION`, `SICK`, `SCHOOL` oder `OTHER`
- `portion`: `FULL`, `AM` oder `PM`
- `start_date`, `end_date`: Datumsbereich
- `credit_minutes_override`: optionale feste Zeitgutschrift

`AM` und `PM` sind nur bei Urlaub und nur für ein einzelnes Datum erlaubt.

### `vacation_request`
Dauerhaftes Archiv aller durch Mitarbeiter eingereichten neuen Urlaubs-, Änderungs- und Löschanträge.

- `request_type`: `CREATE`, `CHANGE` oder `DELETE`
- `target_absence_id`: Verknüpfung zum bestehenden Urlaub, der geändert oder gelöscht werden soll
- `original_start_date`, `original_end_date`, `original_portion`, `original_note`: unveränderlicher Stand des Urlaubs zum Zeitpunkt des Änderungsantrags
- `start_date`, `end_date`, `portion`: gewünschter neuer Zeitraum; bei einer Löschung bleibt hier der betroffene ursprüngliche Zeitraum gespeichert
- `note`: Hinweis des Mitarbeiters
- `status`: `PENDING`, `APPROVED`, `REJECTED` oder `CANCELLED`
- `requested_at`: Zeitpunkt der Antragstellung
- `decided_at`, `decided_by`, `decision_note`: Entscheidung und Nachvollziehbarkeit
- `absence_id`: optionale Verknüpfung zum erzeugten oder nach einer Änderung fortbestehenden Urlaubseintrag

Bei `CREATE` erzeugt eine Genehmigung einen Datensatz in `absence`. Bei `CHANGE` wird der bestehende Urlaub erst nach der Genehmigung verschoben. Bei `DELETE` bleibt der Urlaub bis zur Genehmigung unverändert und wird anschließend entfernt. Wird ein verknüpfter Urlaub gelöscht, setzt SQLite die direkten Verknüpfungen auf `NULL`; der ursprüngliche Zeitraum bleibt durch die Snapshot-Felder trotzdem vollständig im Archiv nachvollziehbar. Mitarbeiter werden in der Anwendung ausschließlich deaktiviert und nicht physisch gelöscht, damit auch deren Antragshistorie aufrufbar bleibt.

### `public_holiday`
Gesetzliche Feiertage pro Region. Die vorhandene Region ist `DE-BY-KF`.

### `work_rule`
Allgemeine Regeln pro Wochentag:

- frühester Arbeitsbeginn
- Ende des Frühstart-Pausenbonus
- automatische Endzeit bei vergessenem Feierabend
- technische Grundpause als Rückfallwert
- Standard-Tageszeit als Rückfallwert, falls für einen Mitarbeiter noch kein Wochenplan existiert

Die tatsächliche Pausengutschrift richtet sich nach der geplanten Tageszeit: Bei mehr als 6 Stunden gelten 30 Minuten Grundpause; die Zeit eines Arbeitsbeginns vor 08:00 Uhr kommt zusätzlich hinzu.

### `overtime_event`
Besondere Überstunden- oder Zeitgutschrift-Tage.

## Sicheres Vorgehen beim Update

1. Webanwendung kurz sperren.
2. `data/stempeluhr.sqlite` kopieren und separat sichern.
3. Neue Projektversion einspielen.
4. Anwendung einmal öffnen; dadurch läuft die Strukturmigration.
5. `PRAGMA integrity_check` und `PRAGMA foreign_key_check` ausführen.
6. Erst danach die Anwendung wieder freigeben.
