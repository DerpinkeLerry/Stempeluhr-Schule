<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/TimeClockService.php';
require_once __DIR__ . '/../app/SimplePdf.php';
require_once __DIR__ . '/../app/TimeReportPdfRenderer.php';

function assertTimeReport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$week = TimeClockService::reportPeriod('week', '2026-W32');
assertTimeReport($week['start_date'] === '2026-08-03', 'Die ISO-Kalenderwoche muss montags beginnen');
assertTimeReport($week['end_date'] === '2026-08-09', 'Die ISO-Kalenderwoche muss sonntags enden');

$month = TimeClockService::reportPeriod('month', '2028-02');
assertTimeReport($month['end_date'] === '2028-02-29', 'Schaltjahre müssen im Monatsbericht berücksichtigt werden');

$year = TimeClockService::reportPeriod('year', '2026');
assertTimeReport($year['start_date'] === '2026-01-01' && $year['end_date'] === '2026-12-31', 'Der Jahresbericht muss das komplette Kalenderjahr abdecken');

$invalidWeekRejected = false;
try {
    TimeClockService::reportPeriod('week', '2026-W54');
} catch (RuntimeException) {
    $invalidWeekRejected = true;
}
assertTimeReport($invalidWeekRejected, 'Ungültige Kalenderwochen müssen abgelehnt werden');

$employee = [
    'id' => 1,
    'name' => 'Test Mitarbeiter',
    'personnel_number' => 'T-1',
    'department' => 'Test',
    'weekly_hours' => 38,
];
$days = [];
for ($day = 1; $day <= 31; $day++) {
    $days[] = [
        'day' => 'Mo',
        'date' => sprintf('%02d.08.2026', $day),
        'date_short' => sprintf('%02d.08.', $day),
        'start' => '08:00',
        'end' => '16:30',
        'break_seconds' => 1800,
        'work_seconds' => 28800,
        'planned_seconds' => 28800,
        'difference_seconds' => 0,
        'note' => '',
        'absence_type' => null,
        'is_weekend' => false,
        'is_holiday' => false,
    ];
}
$employeeReport = [
    'employee' => $employee,
    'days' => $days,
    'months' => [],
    'work_seconds' => 892800,
    'break_seconds' => 55800,
    'planned_seconds' => 892800,
    'difference_seconds' => 0,
    'vacation_days' => 0,
    'sick_days' => 0,
    'holiday_days' => 0,
    'presence_days' => 31,
];
$report = [
    'type' => 'month',
    'period' => ['title' => 'August 2026', 'range_label' => '01.08.2026 bis 31.08.2026'],
    'created_at' => '05.08.2026 15:35',
    'employees' => [$employeeReport, $employeeReport],
];
$pdf = (new TimeReportPdfRenderer())->render($report);
assertTimeReport(str_starts_with($pdf, '%PDF-1.4'), 'Der Renderer muss eine PDF-Datei erzeugen');
assertTimeReport(str_contains($pdf, '/Count 2'), 'Jeder Mitarbeiter muss genau eine eigene PDF-Seite erhalten');
assertTimeReport(str_contains($pdf, '/MediaBox [0 0 841.89 595.28]'), 'Monatsberichte müssen im A4-Querformat erzeugt werden');
assertTimeReport(!str_contains($pdf, 'Sollzeit'), 'Sollzeiten dürfen im Zeitnachweis nicht mehr erscheinen');
assertTimeReport(!str_contains($pdf, 'Differenz'), 'Differenzen dürfen im Zeitnachweis nicht mehr erscheinen');

echo "[OK] Zeitnachweis-Zeiträume und PDF-Seiten erfolgreich geprüft.\n";
