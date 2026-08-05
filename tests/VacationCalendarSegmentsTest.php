<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';

function assertVacationSegmentsSame(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FEHLER: {$message}\nErwartet: " . var_export($expected, true) . "\nErhalten: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$weekAcrossWeekend = vacation_calendar_workday_segments('2026-08-03', '2026-08-10');
assertVacationSegmentsSame(
    [
        ['start_date' => '2026-08-03', 'end_date' => '2026-08-07', 'start_day' => 3, 'end_day' => 7, 'span' => 5],
        ['start_date' => '2026-08-10', 'end_date' => '2026-08-10', 'start_day' => 10, 'end_day' => 10, 'span' => 1],
    ],
    $weekAcrossWeekend,
    'Ein Urlaub von Montag bis zum folgenden Montag muss am Wochenende sichtbar unterbrochen werden'
);

$withHoliday = vacation_calendar_workday_segments(
    '2026-08-03',
    '2026-08-10',
    ['2026-08-05' => 'Testfeiertag']
);
assertVacationSegmentsSame(
    [
        ['start_date' => '2026-08-03', 'end_date' => '2026-08-04', 'start_day' => 3, 'end_day' => 4, 'span' => 2],
        ['start_date' => '2026-08-06', 'end_date' => '2026-08-07', 'start_day' => 6, 'end_day' => 7, 'span' => 2],
        ['start_date' => '2026-08-10', 'end_date' => '2026-08-10', 'start_day' => 10, 'end_day' => 10, 'span' => 1],
    ],
    $withHoliday,
    'Feiertage müssen wie Wochenenden als freie Lücke im Urlaubsbalken bleiben'
);

assertVacationSegmentsSame(
    [['start_date' => '2026-08-10', 'end_date' => '2026-08-10', 'start_day' => 10, 'end_day' => 10, 'span' => 1]],
    vacation_calendar_workday_segments('2026-08-08', '2026-08-10'),
    'Ein am Wochenende beginnender Zeitraum darf erst am Montag sichtbar werden'
);

assertVacationSegmentsSame([], vacation_calendar_workday_segments('2026-02-30', '2026-03-02'), 'Ungültige Datumswerte müssen abgelehnt werden');

echo "VacationCalendarSegmentsTest: OK\n";
