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
   - Der Pausenanspruch beträgt grundsätzlich 30 Minuten.
   - Bei Arbeitsbeginn vor 08:00 Uhr wird die Zeit bis 08:00 Uhr zusätzlich gutgeschrieben, zum Beispiel 40 Minuten bei Arbeitsbeginn um 07:50 Uhr.
   - Die Pausenanzeige bewegt sich beim Starten und Beenden einer Pause dezent und flüssig.

7. **Mitarbeiter bearbeiten und löschen**
   - In der Teamübersicht steht für jeden Mitarbeiter ein Bearbeiten-Dialog zur Verfügung.
   - Name, E-Mail, Rolle und Zeitzone können geändert werden.
   - Optional kann ein neues Passwort vergeben werden; ein leeres Passwortfeld behält das bisherige Passwort bei.
   - Mitarbeiter können direkt im Bearbeiten-Dialog gelöscht werden. Zugehörige Arbeitszeiten, Pausen und Abwesenheiten werden dabei ebenfalls entfernt.
   - Das aktuell angemeldete Admin-Konto kann weder gelöscht noch zum Mitarbeiter herabgestuft werden.

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
