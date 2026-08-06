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

$visualSegments = vacation_calendar_merge_visual_segments([
    [
        'employee_id' => 5,
        'employee' => ['name' => 'Erika Beispiel'],
        'portion' => 'FULL',
        'start_day' => 3,
        'end_day' => 3,
        'span' => 1,
        'visible_start_date' => '2026-08-03',
        'visible_end_date' => '2026-08-03',
        'vacation' => ['id' => 101],
    ],
    [
        'employee_id' => 5,
        'employee' => ['name' => 'Erika Beispiel'],
        'portion' => 'FULL',
        'start_day' => 4,
        'end_day' => 5,
        'span' => 2,
        'visible_start_date' => '2026-08-04',
        'visible_end_date' => '2026-08-05',
        'vacation' => ['id' => 102],
    ],
    [
        'employee_id' => 5,
        'employee' => ['name' => 'Erika Beispiel'],
        'portion' => 'FULL',
        'start_day' => 8,
        'end_day' => 8,
        'span' => 1,
        'visible_start_date' => '2026-08-08',
        'visible_end_date' => '2026-08-08',
        'vacation' => ['id' => 103],
    ],
]);

assertVacationSegmentsSame(
    [
        ['start_day' => 3, 'end_day' => 5, 'span' => 3, 'children' => 2],
        ['start_day' => 8, 'end_day' => 8, 'span' => 1, 'children' => 1],
    ],
    array_map(static fn(array $segment): array => [
        'start_day' => (int)$segment['start_day'],
        'end_day' => (int)$segment['end_day'],
        'span' => (int)$segment['span'],
        'children' => count($segment['children']),
    ], $visualSegments),
    'Direkt benachbarte Einzeleinträge derselben Person müssen zu einem visuellen Balken verbunden werden'
);


$stableTracks = vacation_calendar_assign_employee_tracks([
    ['employee_id' => 30, 'employee' => ['name' => 'Clara C'], 'start_day' => 2, 'end_day' => 4],
    ['employee_id' => 10, 'employee' => ['name' => 'Anna A'], 'start_day' => 20, 'end_day' => 22],
    ['employee_id' => 20, 'employee' => ['name' => 'Bernd B'], 'start_day' => 8, 'end_day' => 9],
    ['employee_id' => 10, 'employee' => ['name' => 'Anna A'], 'start_day' => 3, 'end_day' => 5],
], [10, 20, 30]);

assertVacationSegmentsSame(
    [
        ['employee_id' => 10, 'track' => 1, 'start_day' => 3],
        ['employee_id' => 10, 'track' => 1, 'start_day' => 20],
        ['employee_id' => 20, 'track' => 2, 'start_day' => 8],
        ['employee_id' => 30, 'track' => 3, 'start_day' => 2],
    ],
    array_map(static fn(array $segment): array => [
        'employee_id' => (int)$segment['employee_id'],
        'track' => (int)$segment['track'],
        'start_day' => (int)$segment['start_day'],
    ], $stableTracks['segments']),
    'Jeder Mitarbeiter muss im Monat unabhängig vom Startdatum eine feste, alphabetisch geordnete Spur behalten'
);

assertVacationSegmentsSame([3], [(int)$stableTracks['track_count']], 'Die Spuranzahl muss der Zahl sichtbarer Mitarbeiter entsprechen');

echo "VacationCalendarSegmentsTest: OK\n";
