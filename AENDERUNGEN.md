## Version 29 – Anmeldung importierter Mitarbeiter

- Beim ersten Vergeben eines Passworts wird der Login für aktive importierte Mitarbeiter automatisch aktiviert.
- Die Bearbeitungsmaske setzt beim Eingeben eines neuen Passworts automatisch den Schalter „Anmeldung erlaubt“.
- Ein Login kann nicht ohne hinterlegtes Passwort aktiviert werden; die Oberfläche zeigt dafür eine klare Fehlermeldung.
- Bereits vorhandene Zugänge können weiterhin unabhängig vom Passwort deaktiviert bleiben.

## Version 28 – Hover-Beschriftungen im Urlaubskalender

- Vollständige Mitarbeiternamen auf kurzen Urlaubsbalken werden beim Hover nicht mehr vom Datumsbereich verdeckt.
- Die schwebende Beschriftung darf nun sichtbar über den Tageskopf ragen und liegt dabei zuverlässig über dem Kalenderkopf.
- Keine Datenbankänderung erforderlich.

## Kompakter Arbeitszeiten-Planer in Mitarbeiterdetails (v26)

- Urlaubskonto und Arbeitszeiten-Planer nutzen den verfügbaren Platz nun im Verhältnis 4:8 statt 5:7.
- Montag bis Donnerstag stehen in einer gleichmäßigen ersten Reihe; Freitag bis Sonntag bilden eine breitere zweite Reihe.
- Tagesnamen, Schalter und Stundenfelder werden nicht mehr gequetscht oder abgeschnitten.
- Das Urlaubskonto verwendet im schmaleren Bereich eine übersichtliche zweispaltige Eingabeanordnung.
- Hinweise zur Pausenregel sind optisch als eigener kompakter Informationsbereich abgesetzt.
- Auf Tablets und Smartphones bricht der Planer automatisch auf zwei beziehungsweise eine Spalte um.
- Für diese Layoutkorrektur ist keine Datenbankmigration erforderlich.

## Individuelle Arbeitszeiten-Planung (v25)

- Der historisierte Arbeitszeiten-Planer ist wieder aktiv und kann einzelne Wochentage vollständig als frei markieren.
- Bereits beim Anlegen eines Mitarbeiters lässt sich der komplette Wochenplan festlegen.
- Standardmäßig werden 38 Wochenstunden vorbelegt: Montag bis Donnerstag je 8:30 Stunden, Freitag 4:00 Stunden.
- Die Wochenstunden werden automatisch aus den aktiven Tageswerten berechnet und nicht separat eingegeben.
- Urlaub, Krankheit, Schule, sonstige Abwesenheiten und Feiertage verwenden die am jeweiligen Datum gültige individuelle Tageszeit; freie Tage zählen mit 0 Stunden.
- Bei mehr als 6 geplanten Stunden gelten 30 Minuten Pausenanspruch. Arbeitsbeginn vor 08:00 Uhr wird zusätzlich als Frühstartzeit angerechnet.
- Änderungen am Wochenplan gelten ab einem frei wählbaren Datum; frühere Auswertungen bleiben unverändert.
- Die PDF-Zeitnachweise bleiben wie zuvor ohne Sollzeit- und Differenzspalten, zeigen im Kopf aber wieder die aktuelle Wochenstundenzahl.
- Das Datenbankschema wurde auf Version 6 angehoben; vorhandene Arbeitszeitmodelle werden nicht mehr automatisch normalisiert.

## Feste Arbeitszeiten und bereinigte Zeitnachweise (v24)

- Für alle Mitarbeiter gelten verbindlich Montag bis Donnerstag jeweils **8:30 Stunden** und Freitag **4:00 Stunden**.
- Importierte Wochenstunden und frühere individuelle Tagesmodelle werden nicht mehr ausgewertet; bestehende Werte werden beim Start auf 38 Wochenstunden normalisiert.
- Urlaub, Krankheit, Schule, sonstige Abwesenheiten und Feiertage werden mit der festen Tageszeit angerechnet.
- Das Arbeitszeitmodell sowie Wochenstunden und Sonderarbeitszeit wurden aus der Mitarbeiterverwaltung entfernt.
- Wochen-, Monats- und Jahres-PDFs zeigen keine Sollzeiten oder Differenzen mehr, sondern nur erfasste beziehungsweise angerechnete Zeiten, Pausen und Abwesenheitsinformationen.
- Das Datenbankschema wurde auf Version 5 angehoben.

## Ausgewogene Mitarbeiterfarben im Urlaubskalender (v22)

- Die sehr kräftige Neon-Palette wurde durch eine ausgewogene, moderne Farbpalette ersetzt.
- Die Farben bleiben deutlich voneinander unterscheidbar, wirken aber ruhiger und angenehmer.
- Hover-Effekt, Konturen und Schatten wurden ebenfalls etwas zurückgenommen.
- Lesbare helle oder dunkle Schrift wird weiterhin passend zur jeweiligen Farbe verwendet.
- Farblegende, Urlaubsbalken und Urlaubskonten behalten dieselbe feste Farbe je Mitarbeiter.
- Für diese Darstellung ist keine Datenbankmigration erforderlich.

## Kräftigere Mitarbeiterfarben im Urlaubskalender (v21)

- Die bisherigen gedeckten Farben wurden durch eine stark gesättigte, kontrastreiche Farbpalette ersetzt.
- Aufeinanderfolgende Mitarbeiter erhalten bewusst abwechselnde warme, kalte und sehr helle Farbtöne.
- Für helle Farben verwendet der Kalender automatisch dunkle Schrift, für dunkle Farben weiße Schrift.
- Farblegende, Urlaubsbalken und Urlaubskonten verwenden weiterhin dieselbe feste Farbe je Mitarbeiter.
- Für diese Darstellung ist keine Datenbankmigration erforderlich.

## Verbundene Urlaubsbalken im Jahreskalender (v17)

- Direkt aufeinanderfolgende Urlaubseinträge derselben Person werden im Jahreskalender zu einem durchgehenden farbigen Balken verbunden.
- Wochenenden und Feiertage bleiben weiterhin sichtbar frei und unterbrechen den Balken.
- Der vollständige Mitarbeitername steht einmal auf dem zusammenhängenden Balken statt als wiederholte Initialen in jedem einzelnen Tagesfeld.
- Bei kurzen Einträgen wird der vollständige Name beim Darüberfahren zusätzlich als kompakter Hinweis eingeblendet.
- Administratoren können weiterhin jeden zugrunde liegenden Urlaubseintrag einzeln anklicken und bearbeiten.
- Für diese Darstellung ist keine Datenbankmigration erforderlich.

## Zeitnachweise und sichtbarer Pausenmodus (v16)

- Der bisherige Wochenzettel-Dialog wurde zu einem gemeinsamen Bereich **Zeitnachweise** erweitert.
- Administration kann zwischen **Woche**, **Monat** und **Jahr** wechseln und den gewünschten Zeitraum frei auswählen.
- Für jede ausgewählte Person entsteht weiterhin genau eine eigene PDF-Seite.
- Der Wochenbericht bleibt eine detaillierte A4-Hochformatseite mit sieben Tageszeilen und Unterschriftsfeld.
- Der Monatsbericht nutzt A4-Querformat und zeigt alle Kalendertage mit Beginn, Ende, Pause, Arbeitszeit und Bemerkung kompakt auf einer Seite.
- Der Jahresbericht nutzt A4-Querformat und fasst die zwölf Monate mit Arbeitszeit, Pausen, Urlaubs-, Krankheits-, Feiertags- und Anwesenheitstagen auf einer Seite zusammen.
- Abwesenheiten, Wochenenden und Feiertage werden in den PDF-Tabellen dezent farblich unterschieden.
- Während einer laufenden Pause erhält die persönliche Stempeluhr einen sanft animierten orangefarbenen Seitenhintergrund und ein orange getöntes Uhr-Panel. Beim Beenden der Pause blendet die Darstellung wieder zurück.
- Für diese Erweiterung ist keine Datenbankmigration erforderlich.

## Mitarbeiterübersicht: aktive Beschäftigte als Standard

- In der Admin-Mitarbeiterübersicht werden standardmäßig nur aktiv beschäftigte Mitarbeiter angezeigt.
- Ein kompakter Schalter „Alle anzeigen“ blendet inaktive Mitarbeiter bei Bedarf ein.
- Suche und Live-Sortierung berücksichtigen den gewählten Filter gemeinsam.
- Die Kennzahl im Kopf zeigt jetzt die Anzahl der aktiven Mitarbeiter.
- Inaktive Mitarbeiter bleiben vollständig erhalten und können weiterhin bearbeitet oder reaktiviert werden.

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


## Schema Version 2 – Erweiterte Mitarbeiter- und Zeitstruktur

- Abteilung, Telefon, Wochenstunden, Auszubildenden- und Sonderzeitmerkmal
- getrennte Aktiv- und Login-Schalter; keine physische Löschung von Mitarbeitern mehr
- historisierte Arbeitszeitmodelle mit Gültigkeitszeitraum und Sollminuten je Wochentag
- Urlaubskonten pro Kalenderjahr mit Anspruch, Übertrag und manueller Korrektur
- ganze sowie halbe Urlaubstage (`FULL`, `AM`, `PM`)
- Wochenzettel mit Sollzeit und Wochenabweichung
- automatische Strukturmigration älterer Projektdatenbanken auf `PRAGMA user_version = 2`
- mitgelieferte SQLite-Datenbank verlustfrei auf Version 2 übertragen
- vollständige Dokumentation unter `database/STRUCTURE.md`

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

## 05.08.2026 – Urlaubskalender nur noch als Jahresübersicht

- Der Umschalter zwischen Monats- und Jahresansicht wurde entfernt.
- Der Urlaubsplan rendert unabhängig von alten URL-Parametern ausschließlich die vollständige Jahresübersicht.
- Monatsbezeichnungen sind nicht mehr anklickbar; die frühere Monatsdetailansicht wurde aus der Oberfläche entfernt.
- Jahresnavigation, Suche, Mitarbeiterfarben, Antragsverwaltung und Urlaubsbearbeitung bleiben erhalten.


## 05.08.2026 – Urlaubskonten kompakter gestaltet

- Die Urlaubskonten wurden als deutlich kompaktere, zentrierte Übersicht neu gestaltet.
- Die Kontoliste besitzt eine begrenzte Höhe mit internem Scrollbereich und feststehender Tabellenüberschrift.
- Dezente Wechselzeilen, farbige Kennzahlen und Mitarbeiter-Avatare verbessern die schnelle Zuordnung.
- Der verfügbare Resturlaub wird zusätzlich als kompakter Fortschrittsbalken dargestellt.
- Die breite Textschaltfläche wurde durch eine platzsparende Pfeilaktion ersetzt.
- Anspruch, Übertrag, genommene und verfügbare Tage bleiben vollständig sichtbar und funktional unverändert.

## 05.08.2026 – Wochenenden im Urlaubskalender freigehalten

- Samstage und Sonntage werden serverseitig grundsätzlich nicht als Urlaubstage berechnet und im Jahresplan sichtbar freigehalten.
- Mehrtägige Urlaubsbalken werden an Samstagen und Sonntagen sichtbar unterbrochen.
- Ein Zeitraum von Montag bis zum folgenden Montag erscheint deshalb als Montag bis Freitag und anschließend separat am nächsten Montag.
- Im Kalender markierte Feiertage bleiben ebenfalls frei, da sie nicht vom Urlaubskonto abgezogen werden.
- Ein Urlaubseintrag bleibt trotz mehrerer sichtbarer Teilbalken ein einzelner Datensatz und kann über jeden Teilbalken vollständig bearbeitet werden.
- Monatsstatistiken zählen weiterhin Urlaubseinträge und nicht die durch Wochenenden entstehenden Teilsegmente.

## Urlaubskalender – direkte Bereichsauswahl

- Administratoren können einen freien Arbeitstag direkt im Jahreskalender anklicken, um den Dialog „Urlaub eintragen“ mit vorausgefülltem Datum zu öffnen.
- Durch Gedrückthalten und Ziehen lassen sich zusammenhängende Zeiträume auch über mehrere Monate hinweg markieren.
- Wochenenden und Feiertage werden in der Auswahl sichtbar übersprungen und weder als Start-/Endtag noch als Urlaubstag übernommen.
- Der Eintragsdialog zeigt den ausgewählten Zeitraum und die Anzahl der enthaltenen Arbeitstage kompakt an.
- Bestehende Urlaubsbalken bleiben direkt anklickbar und öffnen weiterhin die Bearbeitung, ohne versehentlich eine neue Auswahl zu starten.

## 05.08.2026 – Tageskopf ebenfalls für die Urlaubsauswahl nutzbar

- Administratoren können die Auswahl nun nicht nur in der freien Kalenderfläche, sondern auch direkt über Wochentag und Tagesnummer beginnen.
- Beim Ziehen über den Tageskopf wird der markierte Zeitraum genauso erweitert wie in den großen Tagesfeldern.
- Die Markierung wird gleichzeitig im Tageskopf und in der Kalenderfläche angezeigt, damit der ausgewählte Zeitraum eindeutig erkennbar bleibt.
- Bereits eingetragene Urlaubsbalken blockieren die Auswahl nicht mehr: Der immer freie Tageskopf dient als zuverlässige Auswahlfläche.
- Wochenenden und Feiertage bleiben auch im Tageskopf nicht auswählbar und werden beim Ziehen weiterhin automatisch übersprungen.
- Die Tagesfelder sind zusätzlich per Tastatur mit Eingabe- oder Leertaste für einen einzelnen Urlaubstag nutzbar.

## 05.08.2026 – Tageskopf steuert nur noch das darunterliegende Auswahlfeld

- Wochentag und Tagesnummer bleiben als zusätzliche Eingabefläche für Klick- und Ziehauswahl nutzbar.
- Beim Darüberfahren über das Datum wird ausschließlich das große Kalenderfeld darunter hervorgehoben.
- Der Tageskopf selbst erhält weder Hover-Hintergrund noch Auswahlrahmen und bleibt dadurch optisch ruhig.
- Auch während einer Bereichsauswahl wird die Markierung nur in der eigentlichen Kalenderfläche dargestellt.
- Tastaturauswahl über Eingabe- oder Leertaste markiert ebenfalls nur das darunterliegende Tagesfeld.

## 05.08.2026 – Änderungs- und Löschanträge für bestehenden Urlaub

- Mitarbeiter erhalten neben „Urlaub beantragen“ die neue Aktion **„Urlaub ändern“**.
- Noch nicht begonnene, genehmigte Urlaube können zur Verschiebung, Änderung des Tagesumfangs oder Löschung eingereicht werden.
- Der bestehende Urlaub bleibt bis zur Entscheidung der Administration vollständig unverändert.
- Pro Urlaub ist höchstens ein offener Änderungs- oder Löschantrag möglich.
- Die Administration sieht Antragstyp, ursprünglichen Zeitraum, gewünschten neuen Zeitraum, betroffene Urlaubstage und Begründung direkt im gemeinsamen Antragsarchiv.
- Genehmigte Verschiebungen aktualisieren den vorhandenen Urlaubseintrag; genehmigte Löschungen entfernen ihn aus dem Kalender.
- Bei zwischenzeitlich direkt geändertem oder gelöschtem Urlaub wird eine veraltete Genehmigung blockiert, damit keine Daten unbeabsichtigt überschrieben werden.
- Ursprünglicher Zeitraum, Umfang, Notiz, Entscheidung und Bearbeitungszeitpunkt bleiben dauerhaft gespeichert, auch wenn der Urlaub später gelöscht wird.
- Datenbankschema auf `PRAGMA user_version = 4` angehoben; vorhandene Version-3-Datenbanken werden beim ersten Start automatisch erweitert.

## Version 13 – Eigenes Urlaubskonto für Mitarbeiter sichtbar

- Mitarbeiter sehen im Urlaubskalender jetzt ihr eigenes Urlaubskonto für das gewählte Jahr.
- Angezeigt werden Anspruch, Resturlaub-Übertrag, genommene und noch verfügbare Tage.
- Urlaubskonten anderer Mitarbeiter bleiben weiterhin ausschließlich für Administratoren sichtbar.
- Die Mitarbeiteransicht ist rein lesend; administrative Kontobearbeitung bleibt geschützt.

## 06.08.2026 – Einheitlich weiße Schrift im Urlaubskalender

- Namen und Initialen auf sämtlichen farbigen Urlaubsbalken werden unabhängig von der Mitarbeiterfarbe immer weiß dargestellt.
- Die weiße Beschriftung gilt ebenfalls bei Hover und Fokus.
- Keine Datenbankänderung erforderlich.

## 06.08.2026 – Abwesenheiten mit individueller Tageszeit angerechnet

- Urlaub, Krankheit, Schule und sonstige Abwesenheiten schreiben für den jeweiligen Tag automatisch die im Arbeitszeiten-Planer hinterlegte Sollzeit gut.
- Ein Mitarbeiter mit beispielsweise 7 geplanten Stunden erhält bei einer ganztägigen Abwesenheit exakt 7:00 Stunden angerechnet.
- Halbe Urlaubstage rechnen die Hälfte der geplanten Tageszeit an.
- Planmäßig freie Tage bleiben bei 0:00 Stunden.
- Ganztägige Abwesenheit und tatsächlich erfasste Arbeit werden nicht doppelt gezählt; bei halben Urlaubstagen wird die halbe Gutschrift zusätzlich zur realen Arbeitszeit berücksichtigt.
- Feiertage rechnen an geplanten Arbeitstagen ebenfalls die individuell hinterlegte Tageszeit an.
- Die Tagesanzeige in Mitarbeiterübersicht und Mitarbeiterprofil berücksichtigt die Abwesenheits- beziehungsweise Feiertagsgutschrift nun ebenfalls.
- Historische Import-Gutschriften überschreiben die aktuelle, zum jeweiligen Datum gültige Arbeitszeitplanung nicht mehr.
- Keine Datenbankänderung erforderlich.

## 06.08.2026 – Feste Mitarbeiterreihenfolge im Urlaubskalender

- Jeder Mitarbeiter belegt innerhalb eines Monats nur noch eine feste Zeile, unabhängig davon, an welchem Tag sein Urlaub beginnt.
- Die Zeilen folgen der alphabetischen Mitarbeiterreihenfolge; dadurch bleibt die relative Position der Personen in allen Monaten konsistent.
- Mehrere getrennte Urlaube derselben Person werden im selben Monat immer auf derselben Spur dargestellt.
- Es werden weiterhin nur Mitarbeiter mit Urlaub im jeweiligen Monat als Zeile berücksichtigt, damit die Jahresübersicht kompakt bleibt.
- Dezente horizontale Trennlinien verbessern die Lesbarkeit der festen Spuren.
- Keine Datenbankänderung erforderlich.
