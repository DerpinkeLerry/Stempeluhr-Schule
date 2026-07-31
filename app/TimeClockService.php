<?php
declare(strict_types=1);

final class TimeClockService
{
    private const BASE_BREAK_SECONDS = 30 * 60;

    private $clock;

    public function __construct(private PDO $pdo, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function listEmployees(): array
    {
        return $this->pdo->query('SELECT id, name, email, role, timezone, holiday_region, active FROM employee ORDER BY active DESC, name')->fetchAll();
    }

    public function getEmployee(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, email, role, timezone, holiday_region, active FROM employee WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function getEmployeeForLogin(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM employee WHERE email=? AND active=1 LIMIT 1');
        $st->execute([trim($email)]);
        return $st->fetch() ?: null;
    }

    public function listTimezoneOptions(): array
    {
        $groups = [];
        $now = $this->now();
        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $parts = explode('/', $identifier, 2);
            $group = count($parts) === 2 ? $parts[0] : 'Weitere';
            $label = str_replace('_', ' ', $identifier);
            $offset = $now->setTimezone(new DateTimeZone($identifier))->format('P');
            $groups[$group][] = [
                'value' => $identifier,
                'label' => $label . ' (UTC' . $offset . ')',
            ];
        }
        ksort($groups);
        return $groups;
    }

    public static function isWorkStartAllowed(DateTimeImmutable $localNow): bool
    {
        return $localNow->format('H:i:s') >= '07:30:00';
    }

    public static function calculateNetSeconds(int $grossSeconds, int $breakSeconds): int
    {
        return max(0, $grossSeconds - $breakSeconds);
    }

    public static function calculateBreakAllowanceSeconds(DateTimeImmutable $localWorkStart): int
    {
        $eightOClock = $localWorkStart->setTime(8, 0, 0);
        $earlyStartBonus = max(0, $eightOClock->getTimestamp() - $localWorkStart->getTimestamp());

        if ((int)$localWorkStart->format('N') === 5) {
            return $earlyStartBonus;
        }

        return self::BASE_BREAK_SECONDS + $earlyStartBonus;
    }

    public static function calculateAbsenceCreditSeconds(int $isoWeekday): int
    {
        return match ($isoWeekday) {
            1, 2, 3, 4 => 8 * 3600 + 30 * 60,
            5 => 4 * 3600,
            default => 0,
        };
    }

    public static function absenceRangesAfterWorkedDay(string $startDate, string $endDate, string $workedDay): array
    {
        if ($workedDay < $startDate || $workedDay > $endDate) {
            return [['start_date' => $startDate, 'end_date' => $endDate]];
        }

        if ($startDate === $workedDay && $endDate === $workedDay) {
            return [];
        }

        $workedDate = new DateTimeImmutable($workedDay . ' 00:00:00', new DateTimeZone('UTC'));
        $ranges = [];
        if ($startDate < $workedDay) {
            $ranges[] = [
                'start_date' => $startDate,
                'end_date' => $workedDate->modify('-1 day')->format('Y-m-d'),
            ];
        }
        if ($endDate > $workedDay) {
            $ranges[] = [
                'start_date' => $workedDate->modify('+1 day')->format('Y-m-d'),
                'end_date' => $endDate,
            ];
        }

        return $ranges;
    }

    public static function forgottenSessionEndLocal(DateTimeImmutable $started): DateTimeImmutable
    {
        $hour = (int)$started->format('N') === 5 ? 12 : 17;
        $end = $started->setTime($hour, 0, 0);
        return $end < $started ? $started : $end;
    }

    public function createEmployee(string $name, string $email, string $password, string $role, string $timezone, string $region): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $role = $role === 'admin' ? 'admin' : 'employee';
        $region = (string)cfg('default_holiday_region', 'DE-BY-KF');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Name oder E-Mail ist ungültig');
        }
        if (strlen($password) < 6) {
            throw new RuntimeException('Das Passwort braucht mindestens 6 Zeichen');
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new RuntimeException('Die Zeitzone ist ungültig');
        }

        try {
            $st = $this->pdo->prepare('INSERT INTO employee(name, email, password_hash, role, timezone, holiday_region, active, created_at) VALUES(?,?,?,?,?,?,1,?)');
            $st->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $timezone, $region, $this->nowUtc()]);
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('Die E-Mail gibt es schon');
            }
            throw $e;
        }
        return (int)$this->pdo->lastInsertId();
    }

    public function updateEmployee(
        int $employeeId,
        string $name,
        string $email,
        string $password,
        string $role,
        string $timezone,
        int $actingAdminId
    ): void {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            throw new RuntimeException('Mitarbeiter wurde nicht gefunden');
        }

        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Name oder E-Mail ist ungültig');
        }
        if (!in_array($role, ['admin', 'employee'], true)) {
            throw new RuntimeException('Die Rolle ist ungültig');
        }
        if ($password !== '' && strlen($password) < 6) {
            throw new RuntimeException('Das neue Passwort braucht mindestens 6 Zeichen');
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new RuntimeException('Die Zeitzone ist ungültig');
        }

        if ($employeeId === $actingAdminId && $role !== 'admin') {
            throw new RuntimeException('Das eigene Admin-Konto kann nicht zum Mitarbeiter herabgestuft werden');
        }
        if (
            $employee['role'] === 'admin'
            && (int)$employee['active'] === 1
            && $role !== 'admin'
            && $this->countActiveAdmins() <= 1
        ) {
            throw new RuntimeException('Der letzte aktive Admin kann nicht herabgestuft werden');
        }

        $fields = ['name=?', 'email=?', 'role=?', 'timezone=?'];
        $values = [$name, $email, $role, $timezone];
        if ($password !== '') {
            $fields[] = 'password_hash=?';
            $values[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $values[] = $employeeId;

        try {
            $st = $this->pdo->prepare('UPDATE employee SET ' . implode(', ', $fields) . ' WHERE id=?');
            $st->execute($values);
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('Die E-Mail gibt es schon');
            }
            throw $e;
        }
    }

    public function deleteEmployee(int $employeeId, int $actingAdminId): void
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            throw new RuntimeException('Mitarbeiter wurde nicht gefunden');
        }
        if ($employeeId === $actingAdminId) {
            throw new RuntimeException('Das aktuell angemeldete Admin-Konto kann nicht gelöscht werden');
        }
        if ($employee['role'] === 'admin' && (int)$employee['active'] === 1 && $this->countActiveAdmins() <= 1) {
            throw new RuntimeException('Der letzte aktive Admin kann nicht gelöscht werden');
        }

        $st = $this->pdo->prepare('DELETE FROM employee WHERE id=?');
        $st->execute([$employeeId]);
    }

    private function countActiveAdmins(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM employee WHERE role='admin' AND active=1")->fetchColumn();
    }

    public function getOpenWorkSession(int $employeeId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM work_session WHERE employee_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
        $st->execute([$employeeId]);
        return $st->fetch() ?: null;
    }

    public function getOpenBreak(int $workSessionId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM break_session WHERE work_session_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
        $st->execute([$workSessionId]);
        return $st->fetch() ?: null;
    }

    public function startWork(int $employeeId, string $source = 'web'): array
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee || !(int)$employee['active']) {
            throw new RuntimeException('Benutzer ist nicht aktiv');
        }

        $now = $this->now();
        $localNow = $now->setTimezone(new DateTimeZone($employee['timezone']));
        if (!self::isWorkStartAllowed($localNow)) {
            throw new RuntimeException('Arbeitsbeginn ist frühestens ab 07:30 Uhr möglich');
        }

        $today = $localNow->format('Y-m-d');

        $this->pdo->beginTransaction();
        try {
            if ($this->getOpenWorkSession($employeeId)) {
                throw new RuntimeException('Du bist schon eingestempelt');
            }

            $absenceOverridden = $this->removeAbsenceForDay($employeeId, $today);

            $st = $this->pdo->prepare('INSERT INTO work_session(employee_id, started_at, source) VALUES(?,?,?)');
            $st->execute([$employeeId, $now->format('Y-m-d H:i:s'), $source]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return [
                'work_session_id' => $id,
                'absence_overridden' => $absenceOverridden,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof PDOException && str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('Du bist schon eingestempelt');
            }
            throw $e;
        }
    }

    public function endWork(int $employeeId): array
    {
        $this->pdo->beginTransaction();
        try {
            $work = $this->getOpenWorkSession($employeeId);
            if (!$work) {
                throw new RuntimeException('Du bist nicht eingestempelt');
            }

            $employee = $this->getEmployee($employeeId);
            if (!$employee) {
                throw new RuntimeException('Mitarbeiter wurde nicht gefunden');
            }

            $now = $this->nowUtc();
            $endAt = $now;
            $warning = null;
            if ($this->isStaleWorkSession($work, $employee['timezone'])) {
                $endAt = $this->forgottenSessionEndUtc($work, $employee['timezone']);
                $localEnd = $this->parseUtc($endAt)->setTimezone(new DateTimeZone($employee['timezone']));
                $localStart = $this->parseUtc($work['started_at'])->setTimezone(new DateTimeZone($employee['timezone']));
                $warning = sprintf(
                    'Du hast vergessen, dich am %s auszustempeln. Die Arbeitszeit wurde automatisch auf %s Uhr beendet.',
                    $localStart->format('d.m.Y'),
                    $localEnd->format('H:i')
                );
            }

            $st = $this->pdo->prepare(
                'UPDATE break_session
                 SET ended_at = CASE
                     WHEN started_at >= ? THEN started_at
                     WHEN ended_at IS NULL OR ended_at > ? THEN ?
                     ELSE ended_at
                 END
                 WHERE work_session_id=?'
            );
            $st->execute([$endAt, $endAt, $endAt, $work['id']]);

            $st = $this->pdo->prepare('UPDATE work_session SET ended_at=? WHERE id=? AND ended_at IS NULL');
            $st->execute([$endAt, $work['id']]);
            if ($st->rowCount() !== 1) {
                throw new RuntimeException('Feierabend konnte nicht gespeichert werden');
            }
            $this->pdo->commit();
            return $warning === null ? [] : ['warning' => $warning, 'corrected_at' => $endAt];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function startBreak(int $employeeId): array
    {
        $this->pdo->beginTransaction();
        try {
            $work = $this->getOpenWorkSession($employeeId);
            if (!$work) {
                throw new RuntimeException('Du bist nicht am Arbeiten');
            }
            $employee = $this->getEmployee($employeeId);
            if (!$employee) {
                throw new RuntimeException('Mitarbeiter wurde nicht gefunden');
            }
            if ($this->isStaleWorkSession($work, $employee['timezone'])) {
                throw new RuntimeException('Du hast das Ausstempeln vergessen. Bitte zuerst den vergessenen Feierabend korrigieren.');
            }
            if ($this->getOpenBreak((int)$work['id'])) {
                throw new RuntimeException('Die Pause läuft schon');
            }

            $st = $this->pdo->prepare('INSERT INTO break_session(work_session_id, started_at) VALUES(?,?)');
            $st->execute([$work['id'], $this->nowUtc()]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return ['break_id' => $id];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof PDOException && str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('Die Pause läuft schon');
            }
            throw $e;
        }
    }

    public function endBreak(int $employeeId): void
    {
        $work = $this->getOpenWorkSession($employeeId);
        if (!$work) {
            throw new RuntimeException('Du bist nicht am Arbeiten');
        }

        $st = $this->pdo->prepare('UPDATE break_session SET ended_at=? WHERE work_session_id=? AND ended_at IS NULL');
        $st->execute([$this->nowUtc(), $work['id']]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('Es läuft keine Pause');
        }
    }

    public function getLiveStatus(int $employeeId): array
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            return ['status' => 'UNKNOWN', 'label' => 'Unbekannt'];
        }

        $work = $this->getOpenWorkSession($employeeId);
        if ($work) {
            if ($this->isStaleWorkSession($work, $employee['timezone'])) {
                return [
                    'status' => 'WORKING',
                    'label' => 'Ausstempeln vergessen',
                    'stale_session' => true,
                ];
            }
            if ($this->getOpenBreak((int)$work['id'])) {
                return ['status' => 'ON_BREAK', 'label' => 'Pause', 'stale_session' => false];
            }
            return ['status' => 'WORKING', 'label' => 'Arbeitet', 'stale_session' => false];
        }

        $localNow = $this->now()->setTimezone(new DateTimeZone($employee['timezone']));
        $today = $localNow->format('Y-m-d');
        $absence = $this->findAbsence($employeeId, $today);
        if ($absence) {
            $labels = ['VACATION' => 'Urlaub', 'SICK' => 'Krank', 'SCHOOL' => 'Schule', 'OTHER' => 'Abwesend'];
            return [
                'status' => $absence['type'],
                'label' => $labels[$absence['type']] ?? 'Abwesend',
                'work_start_allowed' => self::isWorkStartAllowed($localNow),
                'work_start_available_at' => '07:30',
            ];
        }

        if ($this->isHoliday($employee['holiday_region'], $today)) {
            return [
                'status' => 'HOLIDAY',
                'label' => 'Feiertag',
                'work_start_allowed' => self::isWorkStartAllowed($localNow),
                'work_start_available_at' => '07:30',
            ];
        }

        return [
            'status' => 'NOT_PRESENT',
            'label' => 'Nicht da',
            'work_start_allowed' => self::isWorkStartAllowed($localNow),
            'work_start_available_at' => '07:30',
        ];
    }

    public function getTodayTotals(int $employeeId): array
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            return [
                'gross_seconds' => 0,
                'break_seconds' => 0,
                'net_seconds' => 0,
                'break_allowance_seconds' => self::BASE_BREAK_SECONDS,
                'break_bonus_seconds' => 0,
                'break_remaining_seconds' => self::BASE_BREAK_SECONDS,
            ];
        }

        [$start, $end] = $this->dayRangeUtc($employee['timezone']);
        $now = $this->nowUtc();
        $st = $this->pdo->prepare('SELECT * FROM work_session WHERE employee_id=? AND started_at < ? AND COALESCE(ended_at, ?) > ? ORDER BY started_at');
        $st->execute([$employeeId, $end, $now, $start]);
        $sessions = $st->fetchAll();

        $gross = 0;
        $breakSeconds = 0;
        $firstTodayStart = null;
        foreach ($sessions as $session) {
            if ($firstTodayStart === null && $session['started_at'] >= $start && $session['started_at'] < $end) {
                $firstTodayStart = (string)$session['started_at'];
            }
            $sessionEnd = $this->effectiveSessionEndUtc($session, $employee['timezone'], $now);
            $gross += $this->overlapSeconds($session['started_at'], $sessionEnd, $start, $end);

            $bs = $this->pdo->prepare('SELECT * FROM break_session WHERE work_session_id=? AND started_at < ? AND COALESCE(ended_at, ?) > ?');
            $bs->execute([$session['id'], $end, $now, $start]);
            foreach ($bs->fetchAll() as $break) {
                $breakEnd = $break['ended_at'] ?: $sessionEnd;
                if ($breakEnd > $sessionEnd) {
                    $breakEnd = $sessionEnd;
                }
                $breakSeconds += $this->overlapSeconds($break['started_at'], $breakEnd, $start, $end);
            }
        }

        $localNow = $this->now()->setTimezone(new DateTimeZone($employee['timezone']));
        $breakAllowanceSeconds = (int)$localNow->format('N') === 5 ? 0 : self::BASE_BREAK_SECONDS;
        if ($firstTodayStart !== null) {
            $localWorkStart = $this->parseUtc($firstTodayStart)->setTimezone(new DateTimeZone($employee['timezone']));
            $breakAllowanceSeconds = self::calculateBreakAllowanceSeconds($localWorkStart);
        }

        return [
            'gross_seconds' => $gross,
            'break_seconds' => $breakSeconds,
            'net_seconds' => self::calculateNetSeconds($gross, $breakSeconds),
            'break_allowance_seconds' => $breakAllowanceSeconds,
            'break_bonus_seconds' => max(0, $breakAllowanceSeconds - self::BASE_BREAK_SECONDS),
            'break_remaining_seconds' => $breakAllowanceSeconds - $breakSeconds,
        ];
    }

    public function listTodayBreaks(int $employeeId): array
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            return [];
        }
        [$start, $end] = $this->dayRangeUtc($employee['timezone']);
        $now = $this->nowUtc();
        $st = $this->pdo->prepare('SELECT b.*, w.started_at AS work_started_at, w.ended_at AS work_ended_at FROM break_session b JOIN work_session w ON w.id=b.work_session_id WHERE w.employee_id=? AND b.started_at < ? AND COALESCE(b.ended_at, ?) > ? ORDER BY b.started_at DESC');
        $st->execute([$employeeId, $end, $now, $start]);
        $rows = $st->fetchAll();
        $visibleRows = [];
        foreach ($rows as $row) {
            $work = [
                'started_at' => $row['work_started_at'],
                'ended_at' => $row['work_ended_at'],
            ];
            $sessionEnd = $this->effectiveSessionEndUtc($work, $employee['timezone'], $now);
            $breakEnd = $row['ended_at'] ?: $sessionEnd;
            if ($breakEnd > $sessionEnd) {
                $breakEnd = $sessionEnd;
            }
            $row['duration_seconds'] = $this->overlapSeconds($row['started_at'], $breakEnd, $start, $end);
            unset($row['work_started_at'], $row['work_ended_at']);
            if ($row['duration_seconds'] > 0) {
                $visibleRows[] = $row;
            }
        }
        return $visibleRows;
    }

    public function listRecentSessions(int $employeeId, int $limit = 14): array
    {
        $limit = max(1, min(50, $limit));
        $st = $this->pdo->prepare("SELECT * FROM work_session WHERE employee_id=? ORDER BY started_at DESC LIMIT {$limit}");
        $st->execute([$employeeId]);
        $sessions = $st->fetchAll();
        $now = $this->nowUtc();
        $employee = $this->getEmployee($employeeId);

        foreach ($sessions as &$session) {
            $end = $employee
                ? $this->effectiveSessionEndUtc($session, $employee['timezone'], $now)
                : ($session['ended_at'] ?: $now);
            $gross = $this->overlapSeconds($session['started_at'], $end, $session['started_at'], $end);
            $bs = $this->pdo->prepare('SELECT started_at, ended_at FROM break_session WHERE work_session_id=?');
            $bs->execute([$session['id']]);
            $breakSeconds = 0;
            foreach ($bs->fetchAll() as $break) {
                $breakEnd = $break['ended_at'] ?: $end;
                if ($breakEnd > $end) {
                    $breakEnd = $end;
                }
                $breakSeconds += $this->overlapSeconds($break['started_at'], $breakEnd, $session['started_at'], $end);
            }
            $session['gross_seconds'] = $gross;
            $session['break_seconds'] = $breakSeconds;
            $session['net_seconds'] = self::calculateNetSeconds($gross, $breakSeconds);
        }
        return $sessions;
    }

    public function listAbsences(int $employeeId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $st = $this->pdo->prepare("SELECT * FROM absence WHERE employee_id=? ORDER BY start_date DESC LIMIT {$limit}");
        $st->execute([$employeeId]);
        return $st->fetchAll();
    }

    public function createAbsence(int $employeeId, string $type, string $startDate, string $endDate, string $note = ''): int
    {
        $types = ['VACATION', 'SICK', 'SCHOOL', 'OTHER'];
        if (!in_array($type, $types, true)) {
            throw new RuntimeException('Die Art ist ungültig');
        }
        if (!$this->validDate($startDate) || !$this->validDate($endDate) || $endDate < $startDate) {
            throw new RuntimeException('Der Zeitraum ist ungültig');
        }
        if (!$this->getEmployee($employeeId)) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM absence WHERE employee_id=? AND start_date<=? AND end_date>=?');
        $check->execute([$employeeId, $endDate, $startDate]);
        if ((int)$check->fetchColumn() > 0) {
            throw new RuntimeException('In dem Zeitraum gibt es schon eine Abwesenheit');
        }

        $st = $this->pdo->prepare('INSERT INTO absence(employee_id, type, start_date, end_date, note, created_at) VALUES(?,?,?,?,?,?)');
        $st->execute([$employeeId, $type, $startDate, $endDate, trim($note), $this->nowUtc()]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateAbsence(int $absenceId, string $type, string $startDate, string $endDate, string $note = ''): void
    {
        $types = ['VACATION', 'SICK', 'SCHOOL', 'OTHER'];
        if (!in_array($type, $types, true)) {
            throw new RuntimeException('Die Art ist ungültig');
        }
        if (!$this->validDate($startDate) || !$this->validDate($endDate) || $endDate < $startDate) {
            throw new RuntimeException('Der Zeitraum ist ungültig');
        }

        $st = $this->pdo->prepare('SELECT employee_id FROM absence WHERE id=?');
        $st->execute([$absenceId]);
        $employeeId = (int)($st->fetchColumn() ?: 0);
        if ($employeeId < 1) {
            throw new RuntimeException('Abwesenheit nicht gefunden');
        }

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM absence WHERE employee_id=? AND id<>? AND start_date<=? AND end_date>=?');
        $check->execute([$employeeId, $absenceId, $endDate, $startDate]);
        if ((int)$check->fetchColumn() > 0) {
            throw new RuntimeException('In dem Zeitraum gibt es schon eine Abwesenheit');
        }

        $st = $this->pdo->prepare('UPDATE absence SET type=?, start_date=?, end_date=?, note=? WHERE id=?');
        $st->execute([$type, $startDate, $endDate, trim($note), $absenceId]);
    }

    public function deleteAbsence(int $absenceId): void
    {
        if ($absenceId < 1) {
            throw new RuntimeException('Abwesenheit nicht gefunden');
        }
        $st = $this->pdo->prepare('DELETE FROM absence WHERE id=?');
        $st->execute([$absenceId]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('Abwesenheit nicht gefunden');
        }
    }

    public function getCurrentWeekInfo(): array
    {
        $timezone = new DateTimeZone('Europe/Berlin');
        $now = $this->now()->setTimezone($timezone);
        $start = $now->setTime(0, 0)->modify('-' . ((int)$now->format('N') - 1) . ' days');
        $end = $start->modify('+6 days');

        return [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'start_label' => $start->format('d.m.Y'),
            'end_label' => $end->format('d.m.Y'),
            'week' => (int)$start->format('W'),
            'year' => (int)$start->format('o'),
        ];
    }

    public function buildWeekReport(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), fn(int $id): bool => $id > 0)));
        if (!$employeeIds) {
            throw new RuntimeException('Bitte mindestens einen Mitarbeiter auswählen');
        }
        if (count($employeeIds) > 100) {
            throw new RuntimeException('Es wurden zu viele Mitarbeiter ausgewählt');
        }

        $marks = implode(',', array_fill(0, count($employeeIds), '?'));
        $st = $this->pdo->prepare("SELECT id, name, email, timezone FROM employee WHERE active=1 AND id IN ($marks) ORDER BY name");
        $st->execute($employeeIds);
        $employees = $st->fetchAll();
        if (!$employees) {
            throw new RuntimeException('Keine Mitarbeiter gefunden');
        }

        $week = $this->getCurrentWeekInfo();
        $timezone = new DateTimeZone('Europe/Berlin');
        $utc = new DateTimeZone('UTC');
        $weekStart = new DateTimeImmutable($week['start_date'] . ' 00:00:00', $timezone);
        $weekEnd = $weekStart->modify('+7 days');
        $rangeStart = $weekStart->setTimezone($utc)->format('Y-m-d H:i:s');
        $rangeEnd = $weekEnd->setTimezone($utc)->format('Y-m-d H:i:s');
        $nowUtc = $this->nowUtc();
        $dayNames = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $absenceLabels = ['VACATION' => 'Urlaub', 'SICK' => 'Krank', 'SCHOOL' => 'Schule', 'OTHER' => 'Sonstiges'];
        $reports = [];

        foreach ($employees as $employee) {
            $sessionsSt = $this->pdo->prepare('SELECT * FROM work_session WHERE employee_id=? AND started_at < ? AND COALESCE(ended_at, ?) > ? ORDER BY started_at');
            $sessionsSt->execute([(int)$employee['id'], $rangeEnd, $nowUtc, $rangeStart]);
            $sessions = $sessionsSt->fetchAll();

            $breaksBySession = [];
            if ($sessions) {
                $sessionIds = array_map(fn(array $row): int => (int)$row['id'], $sessions);
                $sessionMarks = implode(',', array_fill(0, count($sessionIds), '?'));
                $breakSt = $this->pdo->prepare("SELECT * FROM break_session WHERE work_session_id IN ($sessionMarks) ORDER BY started_at");
                $breakSt->execute($sessionIds);
                foreach ($breakSt->fetchAll() as $break) {
                    $breaksBySession[(int)$break['work_session_id']][] = $break;
                }
            }

            $absenceSt = $this->pdo->prepare('SELECT * FROM absence WHERE employee_id=? AND start_date<=? AND end_date>=? ORDER BY start_date');
            $absenceSt->execute([(int)$employee['id'], $week['end_date'], $week['start_date']]);
            $absences = $absenceSt->fetchAll();
            $days = [];
            $weekWork = 0;
            $weekBreak = 0;

            for ($i = 0; $i < 7; $i++) {
                $dayStart = $weekStart->modify('+' . $i . ' days');
                $dayEnd = $dayStart->modify('+1 day');
                $dayStartUtc = $dayStart->setTimezone($utc)->format('Y-m-d H:i:s');
                $dayEndUtc = $dayEnd->setTimezone($utc)->format('Y-m-d H:i:s');
                $firstStart = null;
                $lastEnd = null;
                $hasOpenSession = false;
                $hasWorkSession = false;
                $workSeconds = 0;
                $breakSeconds = 0;

                foreach ($sessions as $session) {
                    $sessionEnd = $this->effectiveSessionEndUtc($session, $employee['timezone'], $nowUtc);
                    $seconds = $this->overlapSeconds($session['started_at'], $sessionEnd, $dayStartUtc, $dayEndUtc);
                    if ($seconds < 1) {
                        continue;
                    }
                    $hasWorkSession = true;
                    $workSeconds += $seconds;

                    $startTs = max(strtotime($session['started_at'] . ' UTC'), strtotime($dayStartUtc . ' UTC'));
                    $endTs = min(strtotime($sessionEnd . ' UTC'), strtotime($dayEndUtc . ' UTC'));
                    $firstStart = $firstStart === null ? $startTs : min($firstStart, $startTs);
                    $lastEnd = $lastEnd === null ? $endTs : max($lastEnd, $endTs);
                    if (!$session['ended_at'] && !$this->isStaleWorkSession($session, $employee['timezone']) && $endTs === strtotime($nowUtc . ' UTC')) {
                        $hasOpenSession = true;
                    }

                    foreach ($breaksBySession[(int)$session['id']] ?? [] as $break) {
                        $breakEnd = $break['ended_at'] ?: $sessionEnd;
                        if ($breakEnd > $sessionEnd) {
                            $breakEnd = $sessionEnd;
                        }
                        $breakSeconds += $this->overlapSeconds($break['started_at'], $breakEnd, $dayStartUtc, $dayEndUtc);
                    }
                }

                $workSeconds = self::calculateNetSeconds($workSeconds, $breakSeconds);

                $noteParts = [];
                $creditAbsence = false;
                $absenceType = null;
                if (!$hasWorkSession) {
                    foreach ($absences as $absence) {
                        $date = $dayStart->format('Y-m-d');
                        if ($absence['start_date'] <= $date && $absence['end_date'] >= $date) {
                            $noteParts[] = $absenceLabels[$absence['type']] ?? 'Abwesend';
                            if ($absenceType === null) {
                                $absenceType = (string)$absence['type'];
                            }
                            if (in_array($absence['type'], ['VACATION', 'SICK'], true)) {
                                $creditAbsence = true;
                            }
                        }
                    }
                }

                if ($creditAbsence) {
                    $workSeconds = max(
                        $workSeconds,
                        self::calculateAbsenceCreditSeconds((int)$dayStart->format('N'))
                    );
                }

                $days[] = [
                    'day' => $dayNames[$i],
                    'date' => $dayStart->format('d.m.Y'),
                    'start' => $firstStart === null ? '-' : (new DateTimeImmutable('@' . $firstStart))->setTimezone($timezone)->format('H:i'),
                    'end' => $lastEnd === null ? '-' : ($hasOpenSession ? 'offen' : (new DateTimeImmutable('@' . $lastEnd))->setTimezone($timezone)->format('H:i')),
                    'break_seconds' => $breakSeconds,
                    'work_seconds' => $workSeconds,
                    'note' => implode(', ', array_unique($noteParts)),
                    'absence_type' => $absenceType,
                ];
                $weekWork += $workSeconds;
                $weekBreak += $breakSeconds;
            }

            $reports[] = [
                'employee' => $employee,
                'days' => $days,
                'work_seconds' => $weekWork,
                'break_seconds' => $weekBreak,
            ];
        }

        return ['week' => $week, 'employees' => $reports, 'created_at' => $this->now()->setTimezone($timezone)->format('d.m.Y H:i')];
    }

    public function listHolidaysForYear(string $region, int $year): array
    {
        $region = (string)cfg('default_holiday_region', 'DE-BY-KF');
        $st = $this->pdo->prepare('SELECT day, name, region FROM public_holiday WHERE substr(day,1,4)=? AND region=? ORDER BY day');
        $st->execute([(string)$year, $region]);
        return $st->fetchAll();
    }

    private function removeAbsenceForDay(int $employeeId, string $day): bool
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM absence WHERE employee_id=? AND start_date<=? AND end_date>=? ORDER BY id'
        );
        $st->execute([$employeeId, $day, $day]);
        $absences = $st->fetchAll();
        if (!$absences) {
            return false;
        }

        foreach ($absences as $absence) {
            $absenceId = (int)$absence['id'];
            $ranges = self::absenceRangesAfterWorkedDay(
                (string)$absence['start_date'],
                (string)$absence['end_date'],
                $day
            );

            if (!$ranges) {
                $delete = $this->pdo->prepare('DELETE FROM absence WHERE id=?');
                $delete->execute([$absenceId]);
                continue;
            }

            $update = $this->pdo->prepare('UPDATE absence SET start_date=?, end_date=? WHERE id=?');
            $update->execute([$ranges[0]['start_date'], $ranges[0]['end_date'], $absenceId]);

            if (isset($ranges[1])) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO absence(employee_id, type, start_date, end_date, note, created_at) VALUES(?,?,?,?,?,?)'
                );
                $insert->execute([
                    $employeeId,
                    $absence['type'],
                    $ranges[1]['start_date'],
                    $ranges[1]['end_date'],
                    $absence['note'],
                    $absence['created_at'],
                ]);
            }
        }

        return true;
    }

    private function findAbsence(int $employeeId, string $day): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM absence WHERE employee_id=? AND start_date<=? AND end_date>=? ORDER BY id DESC LIMIT 1');
        $st->execute([$employeeId, $day, $day]);
        return $st->fetch() ?: null;
    }

    private function isHoliday(string $region, string $day): bool
    {
        $region = (string)cfg('default_holiday_region', 'DE-BY-KF');
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM public_holiday WHERE day=? AND region=?');
        $st->execute([$day, $region]);
        return (int)$st->fetchColumn() > 0;
    }

    private function dayRangeUtc(string $timezone): array
    {
        $tz = new DateTimeZone($timezone);
        $localStart = $this->now()->setTimezone($tz)->setTime(0, 0);
        $localEnd = $localStart->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        return [
            $localStart->setTimezone($utc)->format('Y-m-d H:i:s'),
            $localEnd->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    private function overlapSeconds(string $start, string $end, string $rangeStart, string $rangeEnd): int
    {
        $startTime = max(strtotime($start . ' UTC'), strtotime($rangeStart . ' UTC'));
        $endTime = min(strtotime($end . ' UTC'), strtotime($rangeEnd . ' UTC'));
        return max(0, $endTime - $startTime);
    }

    private function validDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private function nowUtc(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('Die interne Uhr liefert keinen gültigen Zeitpunkt');
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function parseUtc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function isStaleWorkSession(array $work, string $timezone): bool
    {
        if (!empty($work['ended_at'])) {
            return false;
        }
        $tz = new DateTimeZone($timezone);
        $startedDay = $this->parseUtc((string)$work['started_at'])->setTimezone($tz)->format('Y-m-d');
        $today = $this->now()->setTimezone($tz)->format('Y-m-d');
        return $startedDay < $today;
    }

    private function forgottenSessionEndUtc(array $work, string $timezone): string
    {
        $tz = new DateTimeZone($timezone);
        $started = $this->parseUtc((string)$work['started_at'])->setTimezone($tz);
        $end = self::forgottenSessionEndLocal($started);
        return $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function effectiveSessionEndUtc(array $session, string $timezone, string $nowUtc): string
    {
        if (!empty($session['ended_at'])) {
            return (string)$session['ended_at'];
        }
        if ($this->isStaleWorkSession($session, $timezone)) {
            return $this->forgottenSessionEndUtc($session, $timezone);
        }
        return $nowUtc;
    }
}
