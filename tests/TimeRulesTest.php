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
