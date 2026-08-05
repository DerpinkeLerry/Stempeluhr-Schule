# Behobene Punkte

1. **Wochenzettel drucken**
   - Die Mitarbeiterliste im Dialog ist jetzt scrollbar.
   - Der Button `PDF öffnen` befindet sich fest im Kopfbereich und bleibt auch bei vielen Mitarbeitern erreichbar.

2. **Arbeitszeit während Pausen**
   - Die Pausenzeit wird von der Arbeitszeit abgezogen.
   - Die laufende Arbeitszeitanzeige bleibt während einer aktiven Pause stehen.
   - Die Nettozeit wird auch in Mitarbeiterdetails und Wochenzetteln verwendet.

3. **Frühester Arbeitsbeginn**
   - Einstempeln ist serverseitig und in der Oberfläche erst ab 07:30 Uhr möglich.
   - Maßgeblich ist die beim Mitarbeiter hinterlegte Zeitzone.

4. **Vergessener Feierabend**
   - Eine offene Arbeitszeit vom Vortag wird erkannt.
   - Beim Korrigieren erscheint eine Warnung.
   - Montag bis Donnerstag sowie Samstag/Sonntag wird auf 17:00 Uhr korrigiert.
   - Freitag wird auf 12:00 Uhr korrigiert.
   - Die vergessene Zeit läuft nicht in den Folgetag hinein.

5. **Zeitzonen-Auswahl**
   - Beim Anlegen eines Mitarbeiters steht jetzt ein Dropdown mit gültigen IANA-Zeitzonen zur Verfügung.
   - `Europe/Berlin` ist vorausgewählt.

6. **Dynamische Restpause**
   - Während einer laufenden Pause erscheint unter der Pausenzeit eine live herunterzählende Restpause.
   - An allen Tagen außer Freitag beträgt der Pausenanspruch grundsätzlich 30 Minuten.
   - Bei Arbeitsbeginn vor 08:00 Uhr wird die Zeit bis 08:00 Uhr zusätzlich gutgeschrieben, zum Beispiel 40 Minuten bei Arbeitsbeginn um 07:50 Uhr.
   - Freitags beträgt der Pausenanspruch grundsätzlich 0 Minuten; bei Arbeitsbeginn vor 08:00 Uhr wird ausschließlich die Zeit bis 08:00 Uhr gutgeschrieben.
   - Die Pausenanzeige bewegt sich beim Starten und Beenden einer Pause dezent und flüssig.

7. **Mitarbeiter bearbeiten und deaktivieren**
   - In der Teamübersicht steht für jeden Mitarbeiter ein Bearbeiten-Dialog zur Verfügung.
   - Name, E-Mail, Rolle und Zeitzone können geändert werden.
   - Optional kann ein neues Passwort vergeben werden; ein leeres Passwortfeld behält das bisherige Passwort bei.
   - Mitarbeiter können direkt im Bearbeiten-Dialog deaktiviert werden. Arbeitszeiten, Pausen, Abwesenheiten und Urlaubskonten bleiben vollständig erhalten.
   - Das aktuell angemeldete Admin-Konto kann weder deaktiviert noch zum Mitarbeiter herabgestuft werden.

## Prüfungen

```bash
php tests/TimeRulesTest.php
php tests/TimeClockServiceTest.php
```

Für den zweiten Test muss `PDO_SQLite` aktiviert sein, wie auch für den normalen Betrieb der Anwendung.

## Wepro-Redesign

Die gesamte Benutzeroberfläche wurde visuell neu aufgebaut und an die öffentlich sichtbare Gestaltung der Wepro GmbH in Kaufbeuren angelehnt.

- konsistente Wepro-Farbwelt aus tiefem Marineblau, warmem Orange, Weiß und hellen Grautönen
- neue Wepro-Wortmarke mit Produktbezeichnung „Zeiterfassung“
- vollständig überarbeitete Navigation mit responsivem Mobilmenü
- neue Dashboard-Kennzahlen und verbesserte Mitarbeiterübersicht
- Live-Suche in der Mitarbeiterliste
- neu gestaltete persönliche Stempeluhr mit großen Zeitwerten und klaren Aktionen
- hochwertig überarbeitete Mitarbeiterdetails, Abwesenheiten und Feiertagsansicht
- neu gestalteter Login als zweigeteilte Marken- und Anmeldefläche
- überarbeitete Modale, Formulare, Tabellen, Statusanzeigen und Rückmeldungen
- dezente Animationen für Seitenaufbau, Status, Schaltflächen, Karten und Dialoge
- Berücksichtigung von `prefers-reduced-motion` für barriereärmere Nutzung
- optimierte Darstellung für Desktop, Tablet und Smartphone
- Mitarbeiter- und Wochenzettelsuche sowie Auswahlzähler im Druckdialog
- nicht blockierende Toast-Hinweise statt einfacher Browser-Warnfenster bei den meisten Aktionen

Die bestehende Zeitlogik und alle zuvor behobenen Funktionen bleiben erhalten.


## Korrektur: nicht anklickbarer Wochenzettel-Dialog

- Ursache behoben: Die Seiten-Eingangsanimation behielt einen CSS-`transform` bei und erzeugte dadurch einen eigenen Stapelkontext. Der Bootstrap-Hintergrund lag deshalb über dem Dialog und blockierte alle Klicks.
- Modale werden nun zuverlässig direkt unter `body` platziert.
- Explizite Ebenen für Dialog und Hintergrund ergänzen zusätzliche Sicherheit.
- Der Wochenzettel-Dialog bleibt vollständig anklickbar, scrollbar und über die Schließen-Schaltfläche bedienbar.

## Abwesenheit durch Einstempeln überschreiben

- Mitarbeiter können sich auch dann einstempeln, wenn für den aktuellen Tag Urlaub, Krankheit, Schule oder eine sonstige Abwesenheit eingetragen ist.
- Beim Einstempeln wird ausschließlich der aktuelle Tag aus der Abwesenheit entfernt.
- Mehrtägige Abwesenheiten werden bei Bedarf in zwei verbleibende Zeiträume geteilt; Art und Notiz bleiben erhalten.
- Pausen, Feierabend und die laufende Zeiterfassung funktionieren danach unverändert.
- Im Wochenzettel hat eine tatsächlich erfasste Arbeitszeit Vorrang vor einer versehentlich überlappenden Abwesenheit.


## Schema Version 2 – Vorbereitung der Altdatenmigration

- eindeutige Personalnummer und technische Legacy-ID je Mitarbeiter
- Abteilung, Telefon, Wochenstunden, Auszubildenden- und Sonderzeitmerkmal
- getrennte Aktiv- und Login-Schalter; keine physische Löschung von Mitarbeitern mehr
- historisierte Arbeitszeitmodelle mit Gültigkeitszeitraum und Sollminuten je Wochentag
- Urlaubskonten pro Kalenderjahr mit Anspruch, Übertrag und manueller Korrektur
- ganze sowie halbe Urlaubstage (`FULL`, `AM`, `PM`)
- Legacy-Referenzen an Arbeitszeiten, Pausen, Abwesenheiten, Feiertagen und Überstundenereignissen
- Importprotokolltabelle `import_batch` für den späteren MySQL-Importer
- Wochenzettel mit Sollzeit und Wochenabweichung
- automatische Strukturmigration älterer Projektdatenbanken auf `PRAGMA user_version = 2`
- mitgelieferte SQLite-Datenbank verlustfrei auf Version 2 übertragen
- vollständige Dokumentation unter `database/STRUCTURE.md`

## Read-only MySQL-Importer

- CLI-Importer für die alte `StempelUhrAdmin`-MySQL-Datenbank ergänzt
- MySQL-Zugriff ausschließlich über `SHOW` und `SELECT` in einem READ-ONLY-Snapshot
- Modi `--inspect`, `--dry-run` und `--execute`
- automatische Erkennung und konfigurierbare Zuordnung alter Tabellen und Spalten
- Import von Mitarbeitern, Arbeitsmodellen, Urlaubskonten, Arbeitszeiten, Pausen, Abwesenheiten, Feiertagen und optionalen Überstundenereignissen
- Unterscheidung zwischen `worktime.other` und gesetzlichen Feiertagen
- heuristische Zuordnung alter halber Urlaubstage zu `AM` oder `PM` mit Warnbericht
- konfigurierbare Korrektur alter Unix-Zeitstempel nach kontrollierter Stichprobe
- Duplikatschutz über alle vorhandenen `legacy_*`-Spalten
- Konfliktschutz bei gleichen Personalnummern und abweichenden Namen
- automatische konsistente SQLite-Sicherung vor dem Echtimport
- vollständiger SQLite-Import in einer Transaktion mit Rollback bei Fehlern
- Text- und JSON-Prüfberichte unter `data/import-reports`
- ausführliche Anleitung unter `tools/README_MYSQL_IMPORT.md`

## Schema Version 3 – Urlaubskalender und Antragsworkflow

- Neuer Navigationspunkt **Urlaubskalender** direkt neben den Feiertagen für Administration und Mitarbeiter.
- Moderne Monatsplanung mit aktiven Mitarbeitern, festen Namensspalten, Wochenend- und Feiertagsmarkierung, Suche und Filter „Nur mit Urlaub“.
- Mitarbeiter sehen ausschließlich genehmigte Urlaube der aktuell angestellten Mitarbeiter; Krankheit, Schule, sonstige Abwesenheiten, interne Notizen und fremde Kontostände bleiben verborgen.
- Administration kann Urlaube direkt eintragen, bearbeiten und löschen sowie alle Urlaubskonten des ausgewählten Jahres kompakt vergleichen.
- Mitarbeiter können ganze oder halbe Urlaubstage beantragen.
- Administration erhält eine offene Antragszahl in der Navigation und kann Anträge genehmigen oder ablehnen.
- Jeder Antrag wird dauerhaft in `vacation_request` gespeichert. Status, Antragstext, Entscheidung, Entscheidungsnotiz, Bearbeitungszeitpunkt und bearbeitende Administration bleiben im Archiv aufrufbar.
- Eine Genehmigung erzeugt automatisch einen verknüpften Urlaubseintrag. Wird dieser später gelöscht, bleibt der ursprüngliche Antrag erhalten.
- Resturlaub aus dem regulären Jahresanspruch wird automatisch in das Folgejahr übertragen. Nicht genutzter Übertrag verfällt nach dem 31. März; älterer Übertrag wird nicht erneut weitergetragen.
- Direkte Urlaubseinträge, Änderungen und neue Anträge werden serverseitig blockiert, wenn der verfügbare Urlaub nicht ausreicht.
- Große Benachrichtigungen mit Statussymbol, klarer Überschrift, längerer Sichtbarkeit und Fortschrittsbalken zeigen an, wann eine Meldung automatisch verschwindet. Der Countdown pausiert beim Darüberfahren oder Fokussieren.
- Datenbankschema auf `PRAGMA user_version = 3` angehoben; vorhandene Version-2-Datenbanken erhalten die neue Antragstabelle ohne Umbau der bestehenden Zeiterfassungstabellen.

## Urlaubskalender – Monats- und Jahresansicht

- Neuer Umschalter **Monat / Jahr** direkt in der Kalenderwerkzeugleiste.
- Die Monatsansicht bleibt für die detaillierte Tagesplanung erhalten.
- Die Jahresansicht zeigt alle zwölf Monate nebeneinander und alle aktiven Mitarbeiter untereinander, sodass das komplette Planungsjahr auf breiten Bildschirmen gleichzeitig sichtbar ist.
- Urlaubszeiträume werden je Mitarbeiter als kompakte farbige Balken dargestellt; genaue Daten erscheinen beim Darüberfahren und können von der Administration weiterhin direkt bearbeitet werden.
- Monatsüberschriften in der Jahresansicht führen mit einem Klick in die jeweilige detaillierte Monatsansicht.
- Suche und Filter „Nur mit Urlaub“ funktionieren in beiden Ansichten.
- Der Urlaubskalender nutzt als einzige Seite die nahezu vollständige Bildschirmbreite; auf kleineren Geräten bleibt die Ansicht kontrolliert horizontal scrollbar.
- Navigation, Jahresauswahl und Antragsfilter behalten die gewählte Ansicht bei.

## Urlaubskalender: moderne Jahres-Planungstafel (v5)

- Die Jahresansicht ist jetzt die Standardansicht.
- Monate stehen untereinander; jeder Kalendertag bleibt als eigene Spalte sichtbar.
- Urlaube werden wie im bewährten alten Jahresplan als farbige Balken dargestellt.
- Jeder aktive Mitarbeiter erhält eine feste, eindeutige Farbe in Monats- und Jahresansicht.
- Überschneidende Urlaube werden automatisch auf kompakte Spuren verteilt, damit Balken nicht verdeckt werden.
- Mitarbeiterfarben können angeklickt werden, um den Jahresplan direkt zu filtern.
- Monatszeilen zeigen Eintrags- und Mitarbeiteranzahl und öffnen per Klick die Detailansicht.
- Wochenenden, Feiertage und der aktuelle Tag bleiben klar markiert.
- Der Jahresplan nutzt die vollständige verfügbare Bildschirmbreite und scrollt nur auf kleineren Bildschirmen horizontal.

