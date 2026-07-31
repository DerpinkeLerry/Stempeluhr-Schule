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

$tests['Einstempeln überschreibt die Abwesenheit nur für den heutigen Tag'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00'); // Donnerstag 12:00
    $pdo->prepare('INSERT INTO absence(employee_id, type, start_date, end_date, note, created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$employeeId, 'VACATION', '2026-07-27', '2026-07-31', 'Sommerurlaub', '2026-07-20 10:00:00']);

    $statusBefore = $service->getLiveStatus($employeeId);
    assertSameValue('VACATION', $statusBefore['status'], 'Vor dem Einstempeln muss Urlaub angezeigt werden');
    assertTrueValue(($statusBefore['work_start_allowed'] ?? false) === true, 'Einstempeln muss trotz Abwesenheit angeboten werden');

    $result = $service->startWork($employeeId);
    assertTrueValue(($result['absence_overridden'] ?? false) === true, 'Das Überschreiben der Abwesenheit muss gemeldet werden');
    assertSameValue('WORKING', $service->getLiveStatus($employeeId)['status'], 'Nach dem Einstempeln muss der Mitarbeiter arbeiten');

    $absences = $pdo->query('SELECT start_date, end_date, type, note FROM absence ORDER BY start_date')->fetchAll();
    assertSameValue(2, count($absences), 'Die mehrtägige Abwesenheit muss um den Arbeitstag geteilt werden');
    assertSameValue('2026-07-27', $absences[0]['start_date'], 'Der erste Restzeitraum beginnt falsch');
    assertSameValue('2026-07-29', $absences[0]['end_date'], 'Der erste Restzeitraum endet falsch');
    assertSameValue('2026-07-31', $absences[1]['start_date'], 'Der zweite Restzeitraum beginnt falsch');
    assertSameValue('2026-07-31', $absences[1]['end_date'], 'Der zweite Restzeitraum endet falsch');
    assertSameValue('Sommerurlaub', $absences[1]['note'], 'Die Notiz muss beim Teilen erhalten bleiben');

    $clock = new DateTimeImmutable('2026-07-30 11:00:00', new DateTimeZone('UTC'));
    $service->startBreak($employeeId);
    assertSameValue('ON_BREAK', $service->getLiveStatus($employeeId)['status'], 'Pausen müssen nach dem Überschreiben normal funktionieren');

    $clock = new DateTimeImmutable('2026-07-30 11:15:00', new DateTimeZone('UTC'));
    $service->endBreak($employeeId);
    $clock = new DateTimeImmutable('2026-07-30 12:00:00', new DateTimeZone('UTC'));
    $service->endWork($employeeId);
    assertSameValue('NOT_PRESENT', $service->getLiveStatus($employeeId)['status'], 'Nach Feierabend darf die Abwesenheit für heute nicht zurückkehren');
};

$tests['Eintägige Abwesenheit wird beim Einstempeln gelöscht'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00');
    $pdo->prepare('INSERT INTO absence(employee_id, type, start_date, end_date, note, created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$employeeId, 'SICK', '2026-07-30', '2026-07-30', '', '2026-07-30 06:00:00']);

    $service->startWork($employeeId);

    assertSameValue(0, (int)$pdo->query('SELECT COUNT(*) FROM absence')->fetchColumn(), 'Eine eintägige Abwesenheit muss vollständig gelöscht werden');
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

$tests['Freitag hat ohne Einstempelung keine Grundpause'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-31 05:45:00'); // Freitag 07:45

    $totals = $service->getTodayTotals($employeeId);
    assertSameValue(0, $totals['break_allowance_seconds'], 'Freitags darf ohne Einstempelung keine Grundpause angezeigt werden');
    assertSameValue(0, $totals['break_remaining_seconds'], 'Freitags darf ohne Einstempelung keine Restpause angezeigt werden');
};

$tests['Freitag hat ab 08:00 keine Pausengutschrift'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-31 06:00:00'); // Freitag 08:00
    $service->startWork($employeeId);

    $totals = $service->getTodayTotals($employeeId);
    assertSameValue(0, $totals['break_allowance_seconds'], 'Freitags darf ab 08:00 Uhr keine Pause gutgeschrieben werden');
    assertSameValue(0, $totals['break_remaining_seconds'], 'Freitags darf ab 08:00 Uhr keine Restpause bestehen');
};

$tests['Freitag schreibt nur die Zeit vor 08:00 als Pause gut'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-31 05:50:00'); // Freitag 07:50
    $service->startWork($employeeId);
    $clock = new DateTimeImmutable('2026-07-31 06:00:00', new DateTimeZone('UTC')); // 08:00
    $service->startBreak($employeeId);
    $clock = new DateTimeImmutable('2026-07-31 06:05:00', new DateTimeZone('UTC')); // 08:05

    $totals = $service->getTodayTotals($employeeId);
    assertSameValue(600, $totals['break_allowance_seconds'], 'Freitags müssen bei Arbeitsbeginn um 07:50 Uhr genau zehn Minuten gutgeschrieben werden');
    assertSameValue(300, $totals['break_seconds'], 'Die laufende Freitagspause muss berücksichtigt werden');
    assertSameValue(300, $totals['break_remaining_seconds'], 'Nach fünf Minuten müssen noch fünf Minuten Freitagsgutschrift übrig sein');
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

$tests['Abwesenheit wird freitags mit vier Stunden angerechnet'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00');
    $pdo->prepare('INSERT INTO absence(employee_id, type, start_date, end_date, note, created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$employeeId, 'VACATION', '2026-07-27', '2026-07-31', '', '2026-07-30 10:00:00']);

    $report = $service->buildWeekReport([$employeeId]);
    $days = $report['employees'][0]['days'];

    assertSameValue(8 * 3600 + 30 * 60, $days[0]['work_seconds'], 'Montag muss weiterhin mit 8:30 Stunden angerechnet werden');
    assertSameValue(4 * 3600, $days[4]['work_seconds'], 'Freitag muss mit 4:00 Stunden angerechnet werden');
    assertSameValue(0, $days[5]['work_seconds'], 'Samstag darf nicht automatisch angerechnet werden');
    assertSameValue(38 * 3600, $report['employees'][0]['work_seconds'], 'Die Wochenarbeitszeit der Abwesenheit muss 38 Stunden betragen');
};

$tests['Arbeitszeit hat im Wochenzettel Vorrang vor überlappender Abwesenheit'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00');
    $pdo->prepare('INSERT INTO work_session(employee_id, started_at, ended_at, source) VALUES(?,?,?,?)')
        ->execute([$employeeId, '2026-07-30 06:00:00', '2026-07-30 10:00:00', 'test']); // 08:00-12:00
    $pdo->prepare('INSERT INTO absence(employee_id, type, start_date, end_date, note, created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$employeeId, 'VACATION', '2026-07-30', '2026-07-30', '', '2026-07-30 05:00:00']);

    $report = $service->buildWeekReport([$employeeId]);
    $thursday = $report['employees'][0]['days'][3];
    assertSameValue(4 * 3600, $thursday['work_seconds'], 'Die echte Arbeitszeit darf nicht durch Abwesenheitsgutschrift erhöht werden');
    assertSameValue('', $thursday['note'], 'Ein Arbeitstag darf im Wochenzettel nicht als abwesend markiert werden');
    assertSameValue(null, $thursday['absence_type'], 'Ein Arbeitstag darf keine Abwesenheitsfarbe erhalten');
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

$tests['Mitarbeiterdaten und Passwort können aktualisiert werden'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00');
    $pdo->prepare('INSERT INTO employee(name, email, password_hash, role, timezone, holiday_region, active, created_at) VALUES(?,?,?,?,?,?,1,?)')
        ->execute(['Admin', 'admin@example.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'Europe/Berlin', 'DE-BY-KF', '2026-01-01 00:00:00']);
    $adminId = (int)$pdo->lastInsertId();

    $service->updateEmployee($employeeId, 'Neuer Name', 'neu@example.local', 'neuespasswort', 'admin', 'UTC', $adminId);

    $employee = $service->getEmployee($employeeId);
    assertSameValue('Neuer Name', $employee['name'], 'Der Name wurde nicht aktualisiert');
    assertSameValue('neu@example.local', $employee['email'], 'Die E-Mail wurde nicht aktualisiert');
    assertSameValue('admin', $employee['role'], 'Die Rolle wurde nicht aktualisiert');
    assertSameValue('UTC', $employee['timezone'], 'Die Zeitzone wurde nicht aktualisiert');
    $hash = (string)$pdo->query('SELECT password_hash FROM employee WHERE id=' . $employeeId)->fetchColumn();
    assertTrueValue(password_verify('neuespasswort', $hash), 'Das neue Passwort wurde nicht gespeichert');
};

$tests['Leeres Passwort behält das vorhandene Passwort'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00');
    $originalHash = password_hash('bestehend123', PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE employee SET password_hash=? WHERE id=?')->execute([$originalHash, $employeeId]);
    $pdo->prepare('INSERT INTO employee(name, email, password_hash, role, timezone, holiday_region, active, created_at) VALUES(?,?,?,?,?,?,1,?)')
        ->execute(['Admin', 'admin@example.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'Europe/Berlin', 'DE-BY-KF', '2026-01-01 00:00:00']);
    $adminId = (int)$pdo->lastInsertId();

    $service->updateEmployee($employeeId, 'Test Mitarbeiter', 'test@example.local', '', 'employee', 'Europe/Berlin', $adminId);

    $savedHash = (string)$pdo->query('SELECT password_hash FROM employee WHERE id=' . $employeeId)->fetchColumn();
    assertSameValue($originalHash, $savedHash, 'Ein leeres Passwort darf den vorhandenen Hash nicht verändern');
};

$tests['Mitarbeiter löschen entfernt zugehörige Daten'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00');
    $pdo->prepare('INSERT INTO employee(name, email, password_hash, role, timezone, holiday_region, active, created_at) VALUES(?,?,?,?,?,?,1,?)')
        ->execute(['Admin', 'admin@example.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'Europe/Berlin', 'DE-BY-KF', '2026-01-01 00:00:00']);
    $adminId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO work_session(employee_id, started_at, ended_at, source) VALUES(?,?,?,?)')
        ->execute([$employeeId, '2026-07-30 05:30:00', '2026-07-30 08:00:00', 'test']);
    $workId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO break_session(work_session_id, started_at, ended_at) VALUES(?,?,?)')
        ->execute([$workId, '2026-07-30 06:30:00', '2026-07-30 07:00:00']);
    $pdo->prepare('INSERT INTO absence(employee_id, type, start_date, end_date, note, created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$employeeId, 'VACATION', '2026-08-03', '2026-08-04', '', '2026-07-30 10:00:00']);

    $service->deleteEmployee($employeeId, $adminId);

    assertSameValue(0, (int)$pdo->query('SELECT COUNT(*) FROM employee WHERE id=' . $employeeId)->fetchColumn(), 'Der Mitarbeiter wurde nicht gelöscht');
    assertSameValue(0, (int)$pdo->query('SELECT COUNT(*) FROM work_session')->fetchColumn(), 'Arbeitszeiten wurden nicht mitgelöscht');
    assertSameValue(0, (int)$pdo->query('SELECT COUNT(*) FROM break_session')->fetchColumn(), 'Pausen wurden nicht mitgelöscht');
    assertSameValue(0, (int)$pdo->query('SELECT COUNT(*) FROM absence')->fetchColumn(), 'Abwesenheiten wurden nicht mitgelöscht');
};

$tests['Eigenes Admin-Konto kann nicht gelöscht oder herabgestuft werden'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = newTestContext('2026-07-30 10:00:00');
    $pdo->prepare("UPDATE employee SET role='admin' WHERE id=?")->execute([$employeeId]);

    expectRuntimeException(
        static fn() => $service->deleteEmployee($employeeId, $employeeId),
        'aktuell angemeldete Admin-Konto'
    );
    expectRuntimeException(
        static fn() => $service->updateEmployee($employeeId, 'Admin', 'test@example.local', '', 'employee', 'Europe/Berlin', $employeeId),
        'eigenen Admin-Konto'
    );
};

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo "[OK] {$name}\n";
}

echo "\n{$passed} Tests erfolgreich.\n";
