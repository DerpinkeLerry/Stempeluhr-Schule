# WEPRO Zeiterfassung

Digitale Zeiterfassung mit PHP und SQLite im Design der WEPRO GmbH.

## Funktionen

- Login für Admin und Mitarbeiter
- Arbeitsbeginn und Feierabend
- Bezahlte Pausen starten und beenden
- Arbeitszeit läuft auch während einer Pause weiter
- Arbeitszeit und Pausenzeit laufen jede Sekunde weiter
- Mitarbeiter anlegen
- Arbeitszeiten und Abwesenheiten anzeigen
- Abwesenheiten eintragen, bearbeiten und löschen
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

Pausen stehen in `break_session`. Es gibt keinen Pausentyp, da alle Pausen bezahlt sind.

## Wochenzettel

Als Admin gibt es in der Mitarbeiterübersicht den Button `Wochenzettel PDF`.
Dort können alle oder einzelne Mitarbeiter ausgewählt werden. Das PDF nimmt immer die aktuelle Woche von Montag bis Sonntag. Für jeden Mitarbeiter wird eine eigene Seite mit Unterschriftsfeld erstellt.

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

Im Wochenzettel werden keine Buchungsanzahlen angezeigt. Bei Urlaub oder Krankheit werden normale Werktage von Montag bis Freitag mit 8 Stunden 30 Minuten Arbeitszeit angerechnet. In der Bemerkung steht weiterhin Urlaub oder Krank. Samstage und Sonntage werden nicht automatisch angerechnet.

Im Wochenzettel sind Abwesenheiten farblich markiert: Krank hellgelb, Urlaub hellblau und Schule oder Sonstiges hellgrün. Normale Arbeitstage bleiben ohne Hintergrundfarbe.

## WEPRO-Design

Die Oberfläche wurde an den Markenauftritt der WEPRO GmbH in Kaufbeuren angelehnt:

- dunkles Navy als Grundfarbe
- Orange als Aktions- und Signalfarbe
- helle, großzügige Arbeitsflächen
- abstrahierte Netzwerk- und Zeiterfassungsformen
- responsive WEPRO-Wortmarke und eigenes Favicon

Die fachlichen Funktionen und die vorhandene SQLite-Datenbank bleiben unverändert.

Bootstrap ist lokal unter `public/assets/` eingebunden. Die Oberfläche funktioniert daher auch ohne Internetverbindung.
