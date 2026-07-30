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

## Prüfungen

```bash
php tests/TimeRulesTest.php
php tests/TimeClockServiceTest.php
```

Für den zweiten Test muss `PDO_SQLite` aktiviert sein, wie auch für den normalen Betrieb der Anwendung.
