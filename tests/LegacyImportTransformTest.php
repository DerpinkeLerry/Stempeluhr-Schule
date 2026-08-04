<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/LegacyMysqlImporter.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FEHLER: {$message}\nErwartet: " . var_export($expected, true) . "\nErhalten: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$converter = new LegacyValueConverter('Europe/Berlin', 0);

assertSameValue(true, $converter->bool('True'), 'True muss als wahr erkannt werden');
assertSameValue(false, $converter->bool('False'), 'False muss als falsch erkannt werden');
assertSameValue(true, $converter->bool(1), '1 muss als wahr erkannt werden');
assertSameValue(true, $converter->bool("\x01"), 'MySQL BIT 1 muss als wahr erkannt werden');
assertSameValue(false, $converter->bool("\x00"), 'MySQL BIT 0 muss als falsch erkannt werden');
assertSameValue(38.5, $converter->number('38,5'), 'Dezimalkomma muss verarbeitet werden');
assertSameValue('2026-08-04', $converter->date('04.08.2026'), 'Deutsches Datum muss verarbeitet werden');
assertSameValue('2026-08-04', $converter->date(20260804), 'YYYYMMDD muss verarbeitet werden');

// 08:00 Ortszeit ist im Winter 07:00 UTC und im Sommer 06:00 UTC.
assertSameValue('2026-01-15 07:00:00', $converter->utcDateTime(8 * 3600, '2026-01-15'), 'Sekunden seit Mitternacht im Winter');
assertSameValue('2026-07-15 06:00:00', $converter->utcDateTime(8 * 3600, '2026-07-15'), 'Sekunden seit Mitternacht im Sommer');
assertSameValue('08:00:00', $converter->localTime('2026-01-15 07:00:00'), 'UTC muss nach lokaler Zeit zurueckgerechnet werden');

$winterEpoch = (new DateTimeImmutable('2026-01-15 08:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
assertSameValue('2026-01-15 07:00:00', $converter->utcDateTime($winterEpoch), 'Unix-Zeitstempel muss als UTC-Instant gespeichert werden');
assertSameValue('2026-01-15', $converter->date($winterEpoch), 'Unix-Zeitstempel muss zum lokalen Datum werden');

$adjusted = new LegacyValueConverter('Europe/Berlin', 3600);
assertSameValue('2026-01-15 08:00:00', $adjusted->utcDateTime($winterEpoch), 'Konfigurierter Zeitstempelversatz muss angewendet werden');

[$from, $to] = $converter->localDayUtcBounds('2026-07-15');
assertSameValue('2026-07-14 22:00:00', $from, 'Sommerlicher Tagesanfang in UTC');
assertSameValue('2026-07-15 22:00:00', $to, 'Sommerliches Tagesende in UTC');

$report = new LegacyImportReport('test');
$report->increment('employees.inserted', 2);
$report->warning('Beispielwarnung');
$report->finish();
$data = $report->toArray();
assertSameValue(2, $data['counters']['employees.inserted'], 'Berichtszaehler');
assertSameValue(1, $data['counters']['warnings'], 'Warnungszaehler');

echo "LegacyImportTransformTest: OK\n";
