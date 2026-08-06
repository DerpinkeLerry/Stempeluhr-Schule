<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/TimeClockService.php';

function assertRule(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$berlin = new DateTimeZone('Europe/Berlin');

assertRule(
    !TimeClockService::isWorkStartAllowed(new DateTimeImmutable('2026-07-30 07:29:59', $berlin)),
    '07:29:59 muss gesperrt sein'
);
assertRule(
    TimeClockService::isWorkStartAllowed(new DateTimeImmutable('2026-07-30 07:30:00', $berlin)),
    '07:30:00 muss erlaubt sein'
);
assertRule(
    TimeClockService::calculateNetSeconds(7200, 1800) === 5400,
    'Pausenzeit muss von der Arbeitszeit abgezogen werden'
);
assertRule(
    TimeClockService::calculateNetSeconds(1200, 1800) === 0,
    'Nettoarbeitszeit darf nicht negativ werden'
);

assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-30 07:50:00', $berlin)) === 2400,
    'Arbeitsbeginn um 07:50 Uhr muss 40 Minuten Pause ergeben'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-30 08:00:00', $berlin)) === 1800,
    'Arbeitsbeginn um 08:00 Uhr muss 30 Minuten Pause ergeben'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-30 07:30:00', $berlin)) === 3600,
    'Arbeitsbeginn um 07:30 Uhr muss 60 Minuten Pause ergeben'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-31 08:00:00', $berlin)) === 0,
    'Freitags darf es ab 08:00 Uhr keine Pausengutschrift geben'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-31 07:50:00', $berlin)) === 600,
    'Freitags darf bei Arbeitsbeginn um 07:50 Uhr nur die Frühzeit von zehn Minuten gutgeschrieben werden'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-31 07:30:00', $berlin)) === 1800,
    'Freitags darf bei Arbeitsbeginn um 07:30 Uhr nur die Frühzeit von 30 Minuten gutgeschrieben werden'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-31 08:00:00', $berlin), 510) === 1800,
    'Auch an einem Freitag müssen bei mehr als 6 geplanten Stunden 30 Minuten Pause gelten'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-30 07:50:00', $berlin), 240) === 600,
    'Bei einem kurzen Arbeitstag darf nur der Frühstart als Pausenzeit zählen'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-30 08:00:00', $berlin), 360) === 0,
    'Bei genau 6 geplanten Stunden darf keine Grundpause addiert werden'
);
assertRule(
    TimeClockService::calculateBreakAllowanceSeconds(new DateTimeImmutable('2026-07-30 08:00:00', $berlin), 361) === 1800,
    'Bei mehr als 6 geplanten Stunden müssen 30 Minuten Grundpause gelten'
);

assertRule(
    TimeClockService::calculateAbsenceCreditSeconds(1) === 8 * 3600 + 30 * 60,
    'Abwesenheit muss montags mit 8:30 Stunden angerechnet werden'
);
assertRule(
    TimeClockService::calculateAbsenceCreditSeconds(5) === 4 * 3600,
    'Abwesenheit muss freitags mit 4:00 Stunden angerechnet werden'
);
assertRule(
    TimeClockService::calculateAbsenceCreditSeconds(6) === 0,
    'Abwesenheit darf samstags nicht automatisch angerechnet werden'
);

assertRule(
    TimeClockService::calculateScheduledAbsenceCreditSeconds(420, 'FULL') === 7 * 3600,
    'Ein ganzer Abwesenheitstag muss exakt die individuell geplanten 7 Stunden anrechnen'
);
assertRule(
    TimeClockService::calculateScheduledAbsenceCreditSeconds(420, 'AM') === 3 * 3600 + 30 * 60,
    'Ein halber Abwesenheitstag muss die Hälfte der individuell geplanten Zeit anrechnen'
);
assertRule(
    TimeClockService::calculateScheduledAbsenceCreditSeconds(420, 'PM', true) === 3 * 3600 + 30 * 60,
    'Bei einem halben Abwesenheitstag bleibt die halbe Gutschrift zusätzlich zu echter Arbeit erhalten'
);
assertRule(
    TimeClockService::calculateScheduledAbsenceCreditSeconds(420, 'FULL', true) === 0,
    'Ein ganzer Abwesenheitstag darf bei tatsächlicher Arbeit nicht zusätzlich doppelt angerechnet werden'
);
assertRule(
    TimeClockService::calculateScheduledAbsenceCreditSeconds(0, 'FULL') === 0,
    'An einem planmäßig freien Tag darf keine Abwesenheitszeit angerechnet werden'
);

$middleRanges = TimeClockService::absenceRangesAfterWorkedDay('2026-07-27', '2026-07-31', '2026-07-30');
assertRule(
    $middleRanges === [
        ['start_date' => '2026-07-27', 'end_date' => '2026-07-29'],
        ['start_date' => '2026-07-31', 'end_date' => '2026-07-31'],
    ],
    'Ein Arbeitstag mitten in einer Abwesenheit muss den Zeitraum korrekt teilen'
);

$firstDayRanges = TimeClockService::absenceRangesAfterWorkedDay('2026-07-27', '2026-07-31', '2026-07-27');
assertRule(
    $firstDayRanges === [['start_date' => '2026-07-28', 'end_date' => '2026-07-31']],
    'Der erste Arbeitstag muss aus der Abwesenheit entfernt werden'
);

$onlyDayRanges = TimeClockService::absenceRangesAfterWorkedDay('2026-07-30', '2026-07-30', '2026-07-30');
assertRule($onlyDayRanges === [], 'Eine eintägige Abwesenheit muss beim Einstempeln vollständig entfallen');

$mondayStart = new DateTimeImmutable('2026-07-27 07:30:00', $berlin);
$mondayEnd = TimeClockService::forgottenSessionEndLocal($mondayStart);
assertRule($mondayEnd->format('Y-m-d H:i:s') === '2026-07-27 17:00:00', 'Montag muss auf 17:00 Uhr korrigiert werden');

$fridayStart = new DateTimeImmutable('2026-07-24 07:30:00', $berlin);
$fridayEnd = TimeClockService::forgottenSessionEndLocal($fridayStart);
assertRule($fridayEnd->format('Y-m-d H:i:s') === '2026-07-24 12:00:00', 'Freitag muss auf 12:00 Uhr korrigiert werden');

assertRule(
    $fridayEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') === '2026-07-24 10:00:00',
    'Die Sommerzeit-Umrechnung nach UTC ist falsch'
);

echo "[OK] Zeitregeln erfolgreich geprüft.\n";
