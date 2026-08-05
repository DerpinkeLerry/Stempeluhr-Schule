<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/TimeClockService.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] PDO_SQLite ist in dieser PHP-Laufzeit nicht aktiviert.\n";
    exit(0);
}

function vacationTestContext(string $nowUtc = '2026-08-05 08:00:00'): array
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

    $insert = $pdo->prepare(
        'INSERT INTO employee(name, email, password_hash, role, timezone, holiday_region, weekly_hours, active, login_enabled, created_at, updated_at)
         VALUES(?,?,?,?,?,?,40,1,1,?,?)'
    );
    $insert->execute(['Mitarbeiter Test', 'employee@example.local', 'hash', 'employee', 'Europe/Berlin', 'DE-BY-KF', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
    $employeeId = (int)$pdo->lastInsertId();
    $insert->execute(['Admin Test', 'admin@example.local', 'hash', 'admin', 'Europe/Berlin', 'DE-BY-KF', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
    $adminId = (int)$pdo->lastInsertId();

    $service->updateSchedule($employeeId, '2026-01-01', [1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 8, 6 => 0, 7 => 0]);

    return [$pdo, $service, &$clock, $employeeId, $adminId];
}

function vacationAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(' (erwartet: %s, erhalten: %s)', var_export($expected, true), var_export($actual, true)));
    }
}

function vacationAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function vacationExpectException(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        vacationAssertTrue(str_contains($e->getMessage(), $messagePart), 'Unerwartete Fehlermeldung: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Erwartete RuntimeException wurde nicht ausgelöst');
}

$tests = [];

$tests['Resturlaub wird automatisch in das Folgejahr übertragen'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = vacationTestContext('2026-12-23 08:00:00');
    $service->updateVacationAccount($employeeId, 2026, 5, 0, 0, 'Basis');
    $service->createAbsence($employeeId, 'VACATION', '2026-12-21', '2026-12-22');

    $account = $service->getVacationAccount($employeeId, 2027, '2027-01-05');
    vacationAssertSame(5.0, (float)$account['entitlement_days'], 'Der Jahresanspruch muss ins neue Konto übernommen werden');
    vacationAssertSame(3.0, (float)$account['carryover_days'], 'Der ungenutzte Vorjahresanspruch wurde nicht übertragen');
    vacationAssertSame(8.0, (float)$account['remaining_days'], 'Vor dem 31.03. müssen Anspruch und Übertrag verfügbar sein');
    vacationAssertTrue((bool)$account['carryover_automatic'], 'Der Übertrag muss als automatisch markiert sein');
};

$tests['Übertrag verfällt nach dem 31.03. und kann später nicht verbraucht werden'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = vacationTestContext('2026-12-23 08:00:00');
    $service->updateVacationAccount($employeeId, 2026, 5, 0, 0);
    $service->createAbsence($employeeId, 'VACATION', '2026-12-21', '2026-12-22'); // 3 Tage Übertrag
    $service->getVacationAccount($employeeId, 2027, '2027-01-01');

    $service->createAbsence($employeeId, 'VACATION', '2027-01-04', '2027-01-04'); // 1 Tag Übertrag
    $service->createAbsence($employeeId, 'VACATION', '2027-04-05', '2027-04-09'); // 5 Tage Anspruch

    $afterCutoff = $service->getVacationAccount($employeeId, 2027, '2027-04-10');
    vacationAssertSame(0.0, (float)$afterCutoff['remaining_days'], 'Nach dem Stichtag darf nur der reguläre Anspruch verfügbar bleiben');
    vacationAssertSame(2.0, (float)$afterCutoff['expired_carryover_days'], 'Nicht genutzter Übertrag muss verfallen');
    vacationExpectException(
        static fn() => $service->createAbsence($employeeId, 'VACATION', '2027-04-12', '2027-04-12'),
        'Nicht genügend Urlaub'
    );
};

$tests['Speichern wird bei unzureichendem Urlaub blockiert'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = vacationTestContext('2026-08-05 08:00:00');
    $service->updateVacationAccount($employeeId, 2026, 1, 0, 0);
    $service->createAbsence($employeeId, 'VACATION', '2026-08-10', '2026-08-10');

    vacationExpectException(
        static fn() => $service->createAbsence($employeeId, 'VACATION', '2026-08-11', '2026-08-11'),
        'Nicht genügend Urlaub'
    );
    vacationExpectException(
        static fn() => $service->createVacationRequest($employeeId, '2026-08-12', '2026-08-12'),
        'Nicht genügend Urlaub'
    );
    vacationAssertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM absence WHERE type='VACATION'")->fetchColumn(), 'Der blockierte Urlaub darf nicht gespeichert werden');
    vacationAssertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM vacation_request')->fetchColumn(), 'Der blockierte Antrag darf nicht gespeichert werden');
};

$tests['Wochenenden werden auch bei hinterlegten Wochenendstunden nie als Urlaub berechnet'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = vacationTestContext('2026-08-05 08:00:00');
    $service->updateSchedule($employeeId, '2026-01-01', [1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 8, 6 => 8, 7 => 8]);
    $service->updateVacationAccount($employeeId, 2026, 6, 0, 0);

    $service->createAbsence($employeeId, 'VACATION', '2026-08-03', '2026-08-10');
    $account = $service->getVacationAccount($employeeId, 2026, '2026-08-10');

    vacationAssertSame(6.0, (float)$account['used_days'], 'Montag bis zum folgenden Montag darf nur sechs Werktage verbrauchen');
    vacationAssertSame(0.0, (float)$account['remaining_days'], 'Samstag und Sonntag dürfen das Urlaubskonto nicht zusätzlich belasten');
};

$tests['Genehmigte und abgelehnte Anträge bleiben dauerhaft im Archiv'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId, $adminId] = vacationTestContext('2026-08-05 08:00:00');
    $service->updateVacationAccount($employeeId, 2026, 10, 0, 0);

    $approvedRequestId = $service->createVacationRequest($employeeId, '2026-08-17', '2026-08-18', 'Familientermin');
    $approved = $service->decideVacationRequest($approvedRequestId, $adminId, 'APPROVED', 'Genehmigt');
    vacationAssertSame('APPROVED', $approved['status'], 'Der Antrag wurde nicht genehmigt');
    vacationAssertTrue((int)$approved['absence_id'] > 0, 'Bei Genehmigung muss ein Urlaubseintrag entstehen');

    $rejectedRequestId = $service->createVacationRequest($employeeId, '2026-08-24', '2026-08-24', 'Ein weiterer Tag');
    $service->decideVacationRequest($rejectedRequestId, $adminId, 'REJECTED', 'Besetzung nicht ausreichend');

    $absenceId = (int)$approved['absence_id'];
    $service->deleteAbsence($absenceId);

    vacationAssertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM vacation_request')->fetchColumn(), 'Anträge dürfen durch Entscheidungen oder Urlaubslöschung nicht verschwinden');
    $approvedRow = $pdo->query('SELECT status, absence_id, decision_note FROM vacation_request WHERE id=' . $approvedRequestId)->fetch();
    vacationAssertSame('APPROVED', $approvedRow['status'], 'Der genehmigte Antrag muss im Archiv genehmigt bleiben');
    vacationAssertSame(null, $approvedRow['absence_id'], 'Nach Urlaubslöschung soll nur die Verknüpfung entfernt werden');
    vacationAssertSame('Genehmigt', $approvedRow['decision_note'], 'Die Admin-Entscheidung muss gespeichert bleiben');

    $rejectedRow = $pdo->query('SELECT status, decision_note FROM vacation_request WHERE id=' . $rejectedRequestId)->fetch();
    vacationAssertSame('REJECTED', $rejectedRow['status'], 'Der abgelehnte Antrag muss im Archiv bleiben');
    vacationAssertSame('Besetzung nicht ausreichend', $rejectedRow['decision_note'], 'Die Ablehnungsbegründung muss gespeichert bleiben');
};

$tests['Antragsarchiv bleibt auch für deaktivierte Mitarbeiter sichtbar'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId, $adminId] = vacationTestContext('2026-08-05 08:00:00');
    $service->updateVacationAccount($employeeId, 2026, 10, 0, 0);
    $requestId = $service->createVacationRequest($employeeId, '2026-09-07', '2026-09-08');
    $service->decideVacationRequest($requestId, $adminId, 'REJECTED', 'Test');
    $service->deleteEmployee($employeeId, $adminId);

    $archive = $service->listVacationRequestsForAdmin('ALL');
    vacationAssertSame(1, count($archive), 'Anträge deaktivierter Mitarbeiter müssen im Admin-Archiv sichtbar bleiben');
    vacationAssertSame('Mitarbeiter Test', $archive[0]['employee_name'], 'Der Mitarbeitername muss im Archiv auflösbar bleiben');
};

$tests['Übersprungene Jahre werden für die Übertragskette automatisch angelegt'] = static function (): void {
    [$pdo, $service, &$clock, $employeeId] = vacationTestContext('2026-08-05 08:00:00');
    $service->updateVacationAccount($employeeId, 2026, 5, 0, 0);

    $account2028 = $service->getVacationAccount($employeeId, 2028, '2028-01-10');
    vacationAssertSame(5.0, (float)$account2028['entitlement_days'], 'Der Anspruch darf bei einem Jahressprung nicht verloren gehen');
    vacationAssertSame(5.0, (float)$account2028['carryover_days'], 'Der ungenutzte Anspruch des unmittelbaren Vorjahres muss übertragen werden');
    vacationAssertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM vacation_account WHERE employee_id=' . $employeeId . ' AND year=2027')->fetchColumn(), 'Das übersprungene Zwischenjahr wurde nicht angelegt');
};

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo "[OK] {$name}\n";
}

echo "\n{$passed} Urlaubsworkflow-Tests erfolgreich.\n";
