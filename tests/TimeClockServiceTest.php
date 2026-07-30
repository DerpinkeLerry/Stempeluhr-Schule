<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/TimeClockService.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] PDO_SQLite ist in dieser PHP-Laufzeit nicht aktiviert.\n";
    exit(0);
}

function newTestContext(string $nowUtc): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Testschema konnte nicht geladen werden');
    }
    $pdo->exec($schema);

    $clock = new DateTimeImmutable($nowUtc, new DateTimeZone('UTC'));
    $service = new TimeClockService($pdo, static function () use (&$clock): DateTimeImmutable {
        return $clock;
    });

    $insert = $pdo->prepare('INSERT INTO employee(name, email, password_hash, role, timezone, holiday_region, active, created_at) VALUES(?,?,?,?,?,?,1,?)');
    $insert->execute(['Test Mitarbeiter', 'test@example.local', 'hash', 'employee', 'Europe/Berlin', 'DE-BY-KF', '2026-01-01 00:00:00']);

    return [$pdo, $service, &$clock, (int)$pdo->lastInsertId()];
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(' (erwartet: %s, erhalten: %s)', var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectRuntimeException(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        assertTrueValue(str_contains($e->getMessage(), $messagePart), 'Unerwartete Fehlermeldung: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Erwartete RuntimeException wurde nicht ausgelöst');
}

$tests = [];

$tests['Arbeitsbeginn vor 07:30 wird verhindert'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 05:29:59'); // 07:29:59 in Berlin
    expectRuntimeException(static fn() => $service->startWork($employeeId), 'frühestens ab 07:30 Uhr');
    assertSameValue(0, (int)$pdo->query('SELECT COUNT(*) FROM work_session')->fetchColumn(), 'Es darf keine Arbeitszeit gespeichert werden');
};

$tests['Arbeitsbeginn ab 07:30 ist möglich'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 05:30:00'); // 07:30 in Berlin
    $service->startWork($employeeId);
    assertSameValue(1, (int)$pdo->query('SELECT COUNT(*) FROM work_session')->fetchColumn(), 'Arbeitsbeginn um 07:30 wurde nicht gespeichert');
};

$tests['Pausen werden von der Arbeitszeit abgezogen'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 05:30:00'); // 07:30
    $service->startWork($employeeId);
    $clock = new DateTimeImmutable('2026-07-30 07:00:00', new DateTimeZone('UTC')); // 09:00
    $service->startBreak($employeeId);
    $clock = new DateTimeImmutable('2026-07-30 07:30:00', new DateTimeZone('UTC')); // 09:30

    $totals = $service->getTodayTotals($employeeId);
    assertSameValue(7200, $totals['gross_seconds'], 'Bruttozeit ist falsch');
    assertSameValue(1800, $totals['break_seconds'], 'Pausenzeit ist falsch');
    assertSameValue(5400, $totals['net_seconds'], 'Arbeitszeit muss während der Pause stehen bleiben');
};

$tests['Restpause berücksichtigt frühen Arbeitsbeginn'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 05:50:00'); // 07:50
    $service->startWork($employeeId);
    $clock = new DateTimeImmutable('2026-07-30 06:10:00', new DateTimeZone('UTC')); // 08:10
    $service->startBreak($employeeId);
    $clock = new DateTimeImmutable('2026-07-30 06:20:00', new DateTimeZone('UTC')); // 08:20

    $totals = $service->getTodayTotals($employeeId);
    assertSameValue(2400, $totals['break_allowance_seconds'], '07:50 Uhr muss 40 Minuten Pausenanspruch ergeben');
    assertSameValue(600, $totals['break_seconds'], 'Die laufende Pause muss berücksichtigt werden');
    assertSameValue(1800, $totals['break_remaining_seconds'], 'Nach zehn Minuten müssen 30 Minuten Restpause bleiben');
};

$tests['Vergessener Feierabend Montag wird auf 17:00 gesetzt'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-28 06:00:00'); // Dienstag 08:00
    $pdo->prepare('INSERT INTO work_session(employee_id, started_at, source) VALUES(?,?,?)')
        ->execute([$employeeId, '2026-07-27 05:30:00', 'test']); // Montag 07:30

    $status = $service->getLiveStatus($employeeId);
    assertTrueValue(!empty($status['stale_session']), 'Vergessener Feierabend muss erkannt werden');
    assertSameValue(0, $service->getTodayTotals($employeeId)['net_seconds'], 'Vergessene Zeit darf nicht in den Folgetag laufen');

    $result = $service->endWork($employeeId);
    $endedAt = (string)$pdo->query('SELECT ended_at FROM work_session LIMIT 1')->fetchColumn();
    assertSameValue('2026-07-27 15:00:00', $endedAt, 'Montag muss um 17:00 Berliner Zeit beendet werden');
    assertTrueValue(str_contains((string)($result['warning'] ?? ''), '17:00 Uhr'), 'Die Warnung muss die Korrekturzeit nennen');
};

$tests['Vergessener Feierabend Freitag wird auf 12:00 gesetzt'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-25 08:00:00'); // Samstag 10:00
    $pdo->prepare('INSERT INTO work_session(employee_id, started_at, source) VALUES(?,?,?)')
        ->execute([$employeeId, '2026-07-24 05:30:00', 'test']); // Freitag 07:30

    $result = $service->endWork($employeeId);
    $endedAt = (string)$pdo->query('SELECT ended_at FROM work_session LIMIT 1')->fetchColumn();
    assertSameValue('2026-07-24 10:00:00', $endedAt, 'Freitag muss um 12:00 Berliner Zeit beendet werden');
    assertTrueValue(str_contains((string)($result['warning'] ?? ''), '12:00 Uhr'), 'Die Freitagswarnung muss 12:00 Uhr nennen');
};

$tests['Wochenzettel enthält Nettoarbeitszeit ohne Pause'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00');
    $pdo->prepare('INSERT INTO work_session(employee_id, started_at, ended_at, source) VALUES(?,?,?,?)')
        ->execute([$employeeId, '2026-07-30 05:30:00', '2026-07-30 08:00:00', 'test']); // 07:30-10:00
    $sessionId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO break_session(work_session_id, started_at, ended_at) VALUES(?,?,?)')
        ->execute([$sessionId, '2026-07-30 06:30:00', '2026-07-30 07:00:00']); // 08:30-09:00

    $report = $service->buildWeekReport([$employeeId]);
    $thursday = $report['employees'][0]['days'][3];
    assertSameValue(1800, $thursday['break_seconds'], 'Pause im Wochenzettel ist falsch');
    assertSameValue(7200, $thursday['work_seconds'], 'Arbeitszeit im Wochenzettel muss netto sein');
};

$tests['Zeitzonen-Auswahl enthält Europe/Berlin'] = static function (): void {
    [, $service] = newTestContext('2026-07-30 10:00:00');
    $found = false;
    foreach ($service->listTimezoneOptions() as $options) {
        foreach ($options as $option) {
            if ($option['value'] === 'Europe/Berlin') {
                $found = true;
                break 2;
            }
        }
    }
    assertTrueValue($found, 'Europe/Berlin fehlt in der Zeitzonen-Auswahl');
};

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo "[OK] {$name}\n";
}

echo "\n{$passed} Tests erfolgreich.\n";
