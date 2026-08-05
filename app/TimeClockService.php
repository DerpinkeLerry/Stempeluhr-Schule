<?php
declare(strict_types=1);

final class TimeClockService
{
    private const BASE_BREAK_SECONDS = 30 * 60;

    private $clock;
    private array $workRuleCache = [];

    public function __construct(private PDO $pdo, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function listEmployees(): array
    {
        $year = (int)$this->now()->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y');
        $st = $this->pdo->prepare(
            'SELECT e.*,
                    COALESCE(v.entitlement_days, 0) AS vacation_entitlement_days,
                    COALESCE(v.carryover_days, 0) AS vacation_carryover_days,
                    COALESCE(v.adjustment_days, 0) AS vacation_adjustment_days
             FROM employee e
             LEFT JOIN vacation_account v ON v.employee_id=e.id AND v.year=?
             ORDER BY e.active DESC, e.name'
        );
        $st->execute([$year]);
        return $st->fetchAll();
    }

    public function listActiveEmployeesForVacationCalendar(): array
    {
        return $this->pdo->query(
            "SELECT id, name, email, department, personnel_number, timezone, holiday_region
             FROM employee
             WHERE active=1
             ORDER BY name COLLATE NOCASE, id"
        )->fetchAll();
    }

    public function listVacationAbsencesForPeriod(string $startDate, string $endDate): array
    {
        if (!$this->validDate($startDate) || !$this->validDate($endDate) || $endDate < $startDate) {
            throw new RuntimeException('Der Kalenderzeitraum ist ungültig');
        }

        $st = $this->pdo->prepare(
            "SELECT a.*, e.name AS employee_name, e.department, e.personnel_number
             FROM absence a
             JOIN employee e ON e.id=a.employee_id
             WHERE e.active=1 AND a.type='VACATION' AND a.start_date<=? AND a.end_date>=?
             ORDER BY e.name COLLATE NOCASE, a.start_date, a.id"
        );
        $st->execute([$endDate, $startDate]);
        return $st->fetchAll();
    }

    public function listEditableVacationAbsencesForEmployee(int $employeeId, string $fromDate): array
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee || (int)$employee['active'] !== 1) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }
        if (!$this->validDate($fromDate)) {
            throw new RuntimeException('Das Startdatum ist ungültig');
        }

        $st = $this->pdo->prepare(
            "SELECT a.*,
                    MAX(CASE WHEN vr.status='PENDING' AND vr.request_type IN ('CHANGE', 'DELETE') THEN vr.id END) AS pending_change_request_id
             FROM absence a
             LEFT JOIN vacation_request vr ON vr.target_absence_id=a.id
             WHERE a.employee_id=? AND a.type='VACATION' AND a.start_date>=?
             GROUP BY a.id
             ORDER BY a.start_date, a.end_date, a.id"
        );
        $st->execute([$employeeId, $fromDate]);
        $vacations = $st->fetchAll();
        foreach ($vacations as &$vacation) {
            $vacation['vacation_days'] = $this->calculateVacationDaysForRange(
                $employeeId,
                (string)$vacation['start_date'],
                (string)$vacation['end_date'],
                (string)$vacation['portion']
            );
        }
        unset($vacation);
        return $vacations;
    }

    public function countPendingVacationRequests(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM vacation_request WHERE status='PENDING'")->fetchColumn();
    }

    public function countVacationRequestsForAdmin(string $status = 'ALL'): int
    {
        $status = strtoupper(trim($status));
        if ($status !== 'ALL' && !in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
            $status = 'PENDING';
        }
        if ($status === 'ALL') {
            return (int)$this->pdo->query('SELECT COUNT(*) FROM vacation_request')->fetchColumn();
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM vacation_request WHERE status=?');
        $st->execute([$status]);
        return (int)$st->fetchColumn();
    }

    public function listVacationRequestsForAdmin(string $status = 'PENDING', int $limit = 50, int $offset = 0): array
    {
        $status = strtoupper(trim($status));
        if ($status !== 'ALL' && !in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
            $status = 'PENDING';
        }
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = $status === 'ALL' ? '' : 'WHERE vr.status=:status';
        $sql = "SELECT vr.*, e.name AS employee_name, e.department, e.personnel_number,
                       d.name AS decided_by_name
                FROM vacation_request vr
                JOIN employee e ON e.id=vr.employee_id
                LEFT JOIN employee d ON d.id=vr.decided_by
                $where
                ORDER BY CASE vr.status WHEN 'PENDING' THEN 0 ELSE 1 END,
                         vr.requested_at DESC, vr.id DESC
                LIMIT :limit OFFSET :offset";
        $st = $this->pdo->prepare($sql);
        if ($status !== 'ALL') {
            $st->bindValue(':status', $status, PDO::PARAM_STR);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        $requests = $st->fetchAll();
        foreach ($requests as &$request) {
            $this->appendVacationRequestDayValues($request);
        }
        unset($request);
        return $requests;
    }

    public function listVacationRequestsForEmployee(int $employeeId): array
    {
        if (!$this->getEmployee($employeeId)) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }
        $st = $this->pdo->prepare(
            "SELECT vr.*, d.name AS decided_by_name
             FROM vacation_request vr
             LEFT JOIN employee d ON d.id=vr.decided_by
             WHERE vr.employee_id=?
             ORDER BY vr.requested_at DESC, vr.id DESC"
        );
        $st->execute([$employeeId]);
        $requests = $st->fetchAll();
        foreach ($requests as &$request) {
            $this->appendVacationRequestDayValues($request);
        }
        unset($request);
        return $requests;
    }

    public function getEmployee(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM employee WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function getEmployeeForLogin(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM employee WHERE email=? AND active=1 AND login_enabled=1 LIMIT 1');
        $st->execute([strtolower(trim($email))]);
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

    public function createEmployee(
        string $name,
        string $email,
        string $password,
        string $role,
        string $timezone,
        string $region,
        string $personnelNumber = '',
        string $department = '',
        string $phone = '',
        float $weeklyHours = 38.0,
        bool $isTrainee = false,
        bool $specialTime = false,
        float $vacationEntitlement = 30.0
    ): int {
        $name = trim($name);
        $email = strtolower(trim($email));
        $personnelNumber = trim($personnelNumber);
        $department = trim($department);
        $phone = trim($phone);
        $role = $role === 'admin' ? 'admin' : 'employee';
        $region = trim($region) !== '' ? trim($region) : (string)cfg('default_holiday_region', 'DE-BY-KF');

        if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Name oder E-Mail ist ungültig');
        }
        if ($personnelNumber === '' || strlen($personnelNumber) > 50) {
            throw new RuntimeException('Eine gültige Personalnummer ist erforderlich');
        }
        if (strlen($password) < 6) {
            throw new RuntimeException('Das Passwort braucht mindestens 6 Zeichen');
        }
        if ($weeklyHours < 0 || $weeklyHours > 168) {
            throw new RuntimeException('Die Wochenstunden sind ungültig');
        }
        if ($vacationEntitlement < 0 || $vacationEntitlement > 365) {
            throw new RuntimeException('Der Urlaubsanspruch ist ungültig');
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new RuntimeException('Die Zeitzone ist ungültig');
        }

        $now = $this->nowUtc();
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO employee(personnel_number, name, email, password_hash, role, timezone, holiday_region, department, phone, weekly_hours, is_trainee, special_time, active, login_enabled, must_change_password, created_at, updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,1,1,0,?,?)'
            );
            $st->execute([
                $personnelNumber === '' ? null : $personnelNumber,
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $timezone,
                $region,
                $department,
                $phone,
                $weeklyHours,
                $isTrainee ? 1 : 0,
                $specialTime ? 1 : 0,
                $now,
                $now,
            ]);
            $employeeId = (int)$this->pdo->lastInsertId();
            $this->insertScheduleVersion($employeeId, '1970-01-01', self::defaultScheduleMinutes($weeklyHours), 'web');
            $year = (int)$this->now()->setTimezone(new DateTimeZone($timezone))->format('Y');
            $this->upsertVacationAccountInternal($employeeId, $year, $vacationEntitlement, 0.0, 0.0, '', 'web');
            $this->pdo->commit();
            return $employeeId;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('E-Mail oder Personalnummer gibt es schon');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateEmployee(
        int $employeeId,
        string $name,
        string $email,
        string $password,
        string $role,
        string $timezone,
        int $actingAdminId,
        string $personnelNumber = '',
        string $department = '',
        string $phone = '',
        ?float $weeklyHours = null,
        bool $isTrainee = false,
        bool $specialTime = false,
        bool $active = true,
        bool $loginEnabled = true
    ): void {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            throw new RuntimeException('Mitarbeiter wurde nicht gefunden');
        }

        $name = trim($name);
        $email = strtolower(trim($email));
        $personnelNumber = trim($personnelNumber);
        $department = trim($department);
        $phone = trim($phone);
        $weeklyHours ??= (float)$employee['weekly_hours'];

        if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Name oder E-Mail ist ungültig');
        }
        if ($personnelNumber !== '' && strlen($personnelNumber) > 50) {
            throw new RuntimeException('Die Personalnummer ist ungültig');
        }
        if (!in_array($role, ['admin', 'employee'], true)) {
            throw new RuntimeException('Die Rolle ist ungültig');
        }
        if ($password !== '' && strlen($password) < 6) {
            throw new RuntimeException('Das neue Passwort braucht mindestens 6 Zeichen');
        }
        if ($weeklyHours < 0 || $weeklyHours > 168) {
            throw new RuntimeException('Die Wochenstunden sind ungültig');
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new RuntimeException('Die Zeitzone ist ungültig');
        }

        if ($employeeId === $actingAdminId && ($role !== 'admin' || !$active || !$loginEnabled)) {
            throw new RuntimeException('Das eigene Admin-Konto muss aktiv und anmeldbar bleiben');
        }
        if (
            $employee['role'] === 'admin'
            && (int)$employee['active'] === 1
            && ($role !== 'admin' || !$active)
            && $this->countActiveAdmins() <= 1
        ) {
            throw new RuntimeException('Der letzte aktive Admin kann nicht deaktiviert oder herabgestuft werden');
        }

        $fields = [
            'personnel_number=?', 'name=?', 'email=?', 'role=?', 'timezone=?',
            'department=?', 'phone=?', 'weekly_hours=?', 'is_trainee=?', 'special_time=?',
            'active=?', 'login_enabled=?', 'updated_at=?'
        ];
        $values = [
            $personnelNumber === '' ? null : $personnelNumber,
            $name,
            $email,
            $role,
            $timezone,
            $department,
            $phone,
            $weeklyHours,
            $isTrainee ? 1 : 0,
            $specialTime ? 1 : 0,
            $active ? 1 : 0,
            $loginEnabled ? 1 : 0,
            $this->nowUtc(),
        ];
        if ($password !== '') {
            $fields[] = 'password_hash=?';
            $fields[] = 'must_change_password=0';
            $values[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $values[] = $employeeId;

        try {
            $st = $this->pdo->prepare('UPDATE employee SET ' . implode(', ', $fields) . ' WHERE id=?');
            $st->execute($values);
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('E-Mail oder Personalnummer gibt es schon');
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
            throw new RuntimeException('Das aktuell angemeldete Admin-Konto kann nicht deaktiviert werden');
        }
        if ($employee['role'] === 'admin' && (int)$employee['active'] === 1 && $this->countActiveAdmins() <= 1) {
            throw new RuntimeException('Der letzte aktive Admin kann nicht deaktiviert werden');
        }

        $st = $this->pdo->prepare('UPDATE employee SET active=0, login_enabled=0, updated_at=? WHERE id=?');
        $st->execute([$this->nowUtc(), $employeeId]);
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
        $rule = $this->getWorkRule((int)$localNow->format('N'));
        if (!$this->isWorkStartAllowedByRule($localNow, $rule)) {
            throw new RuntimeException('Arbeitsbeginn ist frühestens ab ' . $rule['earliest_start'] . ' Uhr möglich');
        }

        $today = $localNow->format('Y-m-d');
        $nowUtc = $now->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            if ($this->getOpenWorkSession($employeeId)) {
                throw new RuntimeException('Du bist schon eingestempelt');
            }

            $absenceOverridden = $this->removeAbsenceForDay($employeeId, $today);

            $st = $this->pdo->prepare('INSERT INTO work_session(employee_id, started_at, source, created_at, updated_at) VALUES(?,?,?,?,?)');
            $st->execute([$employeeId, $nowUtc, trim($source) === '' ? 'web' : trim($source), $nowUtc, $nowUtc]);
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
                 END,
                 updated_at=?
                 WHERE work_session_id=?'
            );
            $st->execute([$endAt, $endAt, $endAt, $now, $work['id']]);

            $st = $this->pdo->prepare('UPDATE work_session SET ended_at=?, updated_at=? WHERE id=? AND ended_at IS NULL');
            $st->execute([$endAt, $now, $work['id']]);
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

            $now = $this->nowUtc();
            $st = $this->pdo->prepare('INSERT INTO break_session(work_session_id, started_at, source, created_at, updated_at) VALUES(?,?,?,?,?)');
            $st->execute([$work['id'], $now, 'web', $now, $now]);
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

        $now = $this->nowUtc();
        $st = $this->pdo->prepare('UPDATE break_session SET ended_at=?, updated_at=? WHERE work_session_id=? AND ended_at IS NULL');
        $st->execute([$now, $now, $work['id']]);
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
        if ((int)$employee['active'] !== 1) {
            return ['status' => 'INACTIVE', 'label' => 'Inaktiv'];
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
        $rule = $this->getWorkRule((int)$localNow->format('N'));
        $allowed = $this->isWorkStartAllowedByRule($localNow, $rule);
        $absence = $this->findAbsence($employeeId, $today);
        if ($absence) {
            $labels = ['VACATION' => 'Urlaub', 'SICK' => 'Krank', 'SCHOOL' => 'Schule', 'OTHER' => 'Abwesend'];
            $label = $labels[$absence['type']] ?? 'Abwesend';
            if (($absence['portion'] ?? 'FULL') === 'AM') {
                $label .= ' vormittags';
            } elseif (($absence['portion'] ?? 'FULL') === 'PM') {
                $label .= ' nachmittags';
            }
            return [
                'status' => $absence['type'],
                'label' => $label,
                'work_start_allowed' => $allowed,
                'work_start_available_at' => $rule['earliest_start'],
            ];
        }

        if ($this->isHoliday($employee['holiday_region'], $today)) {
            return [
                'status' => 'HOLIDAY',
                'label' => 'Feiertag',
                'work_start_allowed' => $allowed,
                'work_start_available_at' => $rule['earliest_start'],
            ];
        }

        return [
            'status' => 'NOT_PRESENT',
            'label' => 'Nicht da',
            'work_start_allowed' => $allowed,
            'work_start_available_at' => $rule['earliest_start'],
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
        $rule = $this->getWorkRule((int)$localNow->format('N'));
        $breakAllowanceSeconds = (int)$rule['base_break_minutes'] * 60;
        if ($firstTodayStart !== null) {
            $localWorkStart = $this->parseUtc($firstTodayStart)->setTimezone(new DateTimeZone($employee['timezone']));
            $breakAllowanceSeconds = $this->calculateBreakAllowanceForRule($localWorkStart, $rule);
        }

        return [
            'gross_seconds' => $gross,
            'break_seconds' => $breakSeconds,
            'net_seconds' => self::calculateNetSeconds($gross, $breakSeconds),
            'break_allowance_seconds' => $breakAllowanceSeconds,
            'break_bonus_seconds' => max(0, $breakAllowanceSeconds - ((int)$rule['base_break_minutes'] * 60)),
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

    public function getSchedule(int $employeeId, ?string $date = null): array
    {
        if (!$this->getEmployee($employeeId)) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }
        $date ??= $this->now()->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        if (!$this->validDate($date)) {
            throw new RuntimeException('Das Gültigkeitsdatum ist ungültig');
        }

        $st = $this->pdo->prepare(
            'SELECT * FROM employee_schedule
             WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?)
             ORDER BY weekday, valid_from DESC, id DESC'
        );
        $st->execute([$employeeId, $date, $date]);
        $byDay = [];
        foreach ($st->fetchAll() as $row) {
            $weekday = (int)$row['weekday'];
            if (!isset($byDay[$weekday])) {
                $byDay[$weekday] = $row;
            }
        }
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $byDay[$weekday] ??= [
                'weekday' => $weekday,
                'target_minutes' => 0,
                'planned_start' => '',
                'planned_end' => '',
                'valid_from' => $date,
                'valid_to' => null,
            ];
        }
        ksort($byDay);
        return $byDay;
    }

    public function updateSchedule(int $employeeId, string $effectiveFrom, array $hoursByWeekday): void
    {
        if (!$this->getEmployee($employeeId)) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }
        if (!$this->validDate($effectiveFrom)) {
            throw new RuntimeException('Das Gültigkeitsdatum ist ungültig');
        }

        $minutesByWeekday = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $hours = isset($hoursByWeekday[$weekday]) ? (float)$hoursByWeekday[$weekday] : 0.0;
            if ($hours < 0 || $hours > 24) {
                throw new RuntimeException('Die Sollstunden pro Tag müssen zwischen 0 und 24 liegen');
            }
            $minutesByWeekday[$weekday] = (int)round($hours * 60);
        }

        $now = $this->nowUtc();
        $previousDay = (new DateTimeImmutable($effectiveFrom . ' 00:00:00', new DateTimeZone('UTC')))
            ->modify('-1 day')->format('Y-m-d');

        $this->pdo->beginTransaction();
        try {
            $deleteFuture = $this->pdo->prepare('DELETE FROM employee_schedule WHERE employee_id=? AND valid_from>=?');
            $deleteFuture->execute([$employeeId, $effectiveFrom]);

            $close = $this->pdo->prepare(
                'UPDATE employee_schedule SET valid_to=?, updated_at=?
                 WHERE employee_id=? AND valid_from<? AND (valid_to IS NULL OR valid_to>=?)'
            );
            $close->execute([$previousDay, $now, $employeeId, $effectiveFrom, $effectiveFrom]);

            $this->insertScheduleVersion($employeeId, $effectiveFrom, $minutesByWeekday, 'web');
            $weeklyHours = array_sum($minutesByWeekday) / 60;
            $updateEmployee = $this->pdo->prepare('UPDATE employee SET weekly_hours=?, updated_at=? WHERE id=?');
            $updateEmployee->execute([$weeklyHours, $now, $employeeId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getVacationAccount(int $employeeId, int $year, ?string $asOfDate = null): array
    {
        if ($year < 1970 || $year > 2200) {
            throw new RuntimeException('Das Urlaubsjahr ist ungültig');
        }
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }

        $account = $this->ensureVacationAccountRecord($employeeId, $year, $employee);
        $usage = $this->calculateVacationUsageBreakdown($employeeId, $year, (string)$employee['holiday_region']);
        $state = $this->vacationCapacityState(
            (float)$account['entitlement_days'],
            (float)$account['carryover_days'],
            (float)$account['adjustment_days'],
            (float)$usage['early_days'],
            (float)$usage['late_days']
        );

        $asOfDate = $asOfDate !== null && $this->validDate($asOfDate)
            ? $asOfDate
            : $this->now()->setTimezone(new DateTimeZone((string)$employee['timezone']))->format('Y-m-d');
        $carryoverExpiry = sprintf('%04d-03-31', $year);
        $carryoverAvailable = $asOfDate <= $carryoverExpiry;

        $account['used_days'] = $usage['total_days'];
        $account['used_until_march_days'] = $usage['early_days'];
        $account['used_after_march_days'] = $usage['late_days'];
        $account['carryover_used_days'] = $state['carryover_used_days'];
        $account['carryover_remaining_days'] = $state['carryover_remaining_days'];
        $account['expired_carryover_days'] = $carryoverAvailable ? 0.0 : $state['carryover_remaining_days'];
        $account['entitlement_remaining_days'] = $state['entitlement_remaining_days'];
        $account['total_days'] = $state['total_days'];
        $account['remaining_days'] = $state['entitlement_remaining_days']
            + ($carryoverAvailable ? $state['carryover_remaining_days'] : 0.0);
        $account['carryover_expiry'] = $carryoverExpiry;
        $account['carryover_available'] = $carryoverAvailable;
        return $account;
    }

    public function updateVacationAccount(
        int $employeeId,
        int $year,
        float $entitlementDays,
        float $carryoverDays,
        float $adjustmentDays,
        string $note = ''
    ): void {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }
        if ($year < 1970 || $year > 2200) {
            throw new RuntimeException('Das Urlaubsjahr ist ungültig');
        }
        if ($entitlementDays < 0 || $entitlementDays > 365 || abs($carryoverDays) > 365 || abs($adjustmentDays) > 365) {
            throw new RuntimeException('Die Urlaubswerte sind ungültig');
        }

        $current = $this->ensureVacationAccountRecord($employeeId, $year, $employee);
        if (!empty($current['carryover_automatic'])) {
            $carryoverDays = (float)$current['carryover_days'];
        }
        $usage = $this->calculateVacationUsageBreakdown($employeeId, $year, (string)$employee['holiday_region']);
        $this->assertVacationUsageFitsAccount(
            $year,
            $entitlementDays,
            $carryoverDays,
            $adjustmentDays,
            (float)$usage['early_days'],
            (float)$usage['late_days']
        );

        $this->upsertVacationAccountInternal(
            $employeeId,
            $year,
            $entitlementDays,
            $carryoverDays,
            $adjustmentDays,
            trim($note),
            'web'
        );
    }

    public function createAbsence(
        int $employeeId,
        string $type,
        string $startDate,
        string $endDate,
        string $note = '',
        string $portion = 'FULL',
        string $source = 'web'
    ): int {
        $this->validateAbsenceInput($employeeId, $type, $portion, $startDate, $endDate);
        $this->assertNoAbsenceOverlap($employeeId, $startDate, $endDate);
        if ($type === 'VACATION') {
            if ($this->calculateVacationDaysForRange($employeeId, $startDate, $endDate, $portion) <= 0) {
                throw new RuntimeException('Der Zeitraum enthält keinen geplanten Arbeitstag');
            }
            $this->assertVacationBalanceAvailable($employeeId, [
                ['start_date' => $startDate, 'end_date' => $endDate, 'portion' => $portion],
            ]);
        }

        $now = $this->nowUtc();
        $st = $this->pdo->prepare('INSERT INTO absence(employee_id, type, portion, start_date, end_date, note, source, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute([$employeeId, $type, $portion, $startDate, $endDate, trim($note), trim($source) ?: 'web', $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateAbsence(
        int $absenceId,
        string $type,
        string $startDate,
        string $endDate,
        string $note = '',
        string $portion = 'FULL'
    ): void {
        if ($absenceId < 1) {
            throw new RuntimeException('Abwesenheit nicht gefunden');
        }
        $st = $this->pdo->prepare('SELECT employee_id FROM absence WHERE id=?');
        $st->execute([$absenceId]);
        $employeeId = (int)($st->fetchColumn() ?: 0);
        if ($employeeId < 1) {
            throw new RuntimeException('Abwesenheit nicht gefunden');
        }

        $this->validateAbsenceInput($employeeId, $type, $portion, $startDate, $endDate);
        $this->assertNoAbsenceOverlap($employeeId, $startDate, $endDate, $absenceId);
        if ($type === 'VACATION') {
            if ($this->calculateVacationDaysForRange($employeeId, $startDate, $endDate, $portion) <= 0) {
                throw new RuntimeException('Der Zeitraum enthält keinen geplanten Arbeitstag');
            }
            $this->assertVacationBalanceAvailable($employeeId, [
                ['start_date' => $startDate, 'end_date' => $endDate, 'portion' => $portion],
            ], $absenceId);
        }

        $st = $this->pdo->prepare('UPDATE absence SET type=?, portion=?, start_date=?, end_date=?, note=?, updated_at=? WHERE id=?');
        $st->execute([$type, $portion, $startDate, $endDate, trim($note), $this->nowUtc(), $absenceId]);
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

    public function createVacationRequest(
        int $employeeId,
        string $startDate,
        string $endDate,
        string $note = '',
        string $portion = 'FULL'
    ): int {
        $employee = $this->getEmployee($employeeId);
        if (!$employee || (int)$employee['active'] !== 1) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }
        $this->validateAbsenceInput($employeeId, 'VACATION', $portion, $startDate, $endDate);
        if ($this->calculateVacationDaysForRange($employeeId, $startDate, $endDate, $portion) <= 0) {
            throw new RuntimeException('Der Zeitraum enthält keinen geplanten Arbeitstag');
        }
        $localToday = $this->now()->setTimezone(new DateTimeZone((string)$employee['timezone']))->format('Y-m-d');
        if ($startDate < $localToday) {
            throw new RuntimeException('Urlaub kann nur für heute oder einen zukünftigen Zeitraum beantragt werden');
        }
        $this->assertNoAbsenceOverlap($employeeId, $startDate, $endDate);
        $this->assertNoPendingVacationRequestOverlap($employeeId, $startDate, $endDate);
        $this->assertVacationPlanAvailableWithPendingRequests($employeeId, [
            'request_type' => 'CREATE',
            'target_absence_id' => null,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'portion' => $portion,
        ]);

        $now = $this->nowUtc();
        $st = $this->pdo->prepare(
            "INSERT INTO vacation_request(
                employee_id, request_type, start_date, end_date, portion, note, status,
                requested_at, created_at, updated_at
             ) VALUES(?,'CREATE',?,?,?,?, 'PENDING',?,?,?)"
        );
        $st->execute([$employeeId, $startDate, $endDate, $portion, trim($note), $now, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createVacationChangeRequest(
        int $employeeId,
        int $targetAbsenceId,
        string $requestType,
        string $startDate = '',
        string $endDate = '',
        string $portion = 'FULL',
        string $note = ''
    ): int {
        $requestType = strtoupper(trim($requestType));
        if (!in_array($requestType, ['CHANGE', 'DELETE'], true)) {
            throw new RuntimeException('Die gewünschte Änderung ist ungültig');
        }

        $employee = $this->getEmployee($employeeId);
        if (!$employee || (int)$employee['active'] !== 1) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }
        if ($targetAbsenceId < 1) {
            throw new RuntimeException('Bitte wählen Sie einen bestehenden Urlaub aus');
        }

        $targetSt = $this->pdo->prepare(
            "SELECT * FROM absence WHERE id=? AND employee_id=? AND type='VACATION'"
        );
        $targetSt->execute([$targetAbsenceId, $employeeId]);
        $target = $targetSt->fetch();
        if (!$target) {
            throw new RuntimeException('Der ausgewählte Urlaub wurde nicht gefunden');
        }

        $localToday = $this->now()->setTimezone(new DateTimeZone((string)$employee['timezone']))->format('Y-m-d');
        if ((string)$target['start_date'] < $localToday) {
            throw new RuntimeException('Bereits begonnener oder vergangener Urlaub kann nicht mehr per Antrag geändert werden');
        }

        $pendingTarget = $this->pdo->prepare(
            "SELECT COUNT(*) FROM vacation_request
             WHERE target_absence_id=? AND status='PENDING' AND request_type IN ('CHANGE', 'DELETE')"
        );
        $pendingTarget->execute([$targetAbsenceId]);
        if ((int)$pendingTarget->fetchColumn() > 0) {
            throw new RuntimeException('Für diesen Urlaub gibt es bereits einen offenen Änderungsantrag');
        }

        if ($requestType === 'DELETE') {
            $startDate = (string)$target['start_date'];
            $endDate = (string)$target['end_date'];
            $portion = (string)$target['portion'];
        } else {
            $this->validateAbsenceInput($employeeId, 'VACATION', $portion, $startDate, $endDate);
            if ($startDate < $localToday) {
                throw new RuntimeException('Der neue Urlaubszeitraum muss heute oder in der Zukunft beginnen');
            }
            if ($this->calculateVacationDaysForRange($employeeId, $startDate, $endDate, $portion) <= 0) {
                throw new RuntimeException('Der neue Zeitraum enthält keinen geplanten Arbeitstag');
            }
            if (
                $startDate === (string)$target['start_date']
                && $endDate === (string)$target['end_date']
                && $portion === (string)$target['portion']
            ) {
                throw new RuntimeException('Der neue Zeitraum entspricht bereits dem bestehenden Urlaub');
            }

            $this->assertNoAbsenceOverlap($employeeId, $startDate, $endDate, $targetAbsenceId);
            $this->assertNoPendingVacationRequestOverlap($employeeId, $startDate, $endDate);
            $this->assertVacationPlanAvailableWithPendingRequests($employeeId, [
                'request_type' => 'CHANGE',
                'target_absence_id' => $targetAbsenceId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'portion' => $portion,
            ]);
        }

        $now = $this->nowUtc();
        $st = $this->pdo->prepare(
            "INSERT INTO vacation_request(
                employee_id, request_type, target_absence_id,
                original_start_date, original_end_date, original_portion, original_note,
                start_date, end_date, portion, note, status,
                requested_at, created_at, updated_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,'PENDING',?,?,?)"
        );
        $st->execute([
            $employeeId,
            $requestType,
            $targetAbsenceId,
            (string)$target['start_date'],
            (string)$target['end_date'],
            (string)$target['portion'],
            (string)$target['note'],
            $startDate,
            $endDate,
            $portion,
            trim($note),
            $now,
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function decideVacationRequest(
        int $requestId,
        int $adminId,
        string $decision,
        string $decisionNote = ''
    ): array {
        $decision = strtoupper(trim($decision));
        if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw new RuntimeException('Die Entscheidung ist ungültig');
        }
        $admin = $this->getEmployee($adminId);
        if (!$admin || (int)$admin['active'] !== 1 || (string)$admin['role'] !== 'admin') {
            throw new RuntimeException('Admin-Konto nicht gefunden');
        }

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "SELECT vr.*, e.name AS employee_name, e.active AS employee_active
                 FROM vacation_request vr
                 JOIN employee e ON e.id=vr.employee_id
                 WHERE vr.id=?"
            );
            $st->execute([$requestId]);
            $request = $st->fetch();
            if (!$request) {
                throw new RuntimeException('Urlaubsantrag nicht gefunden');
            }
            if ((string)$request['status'] !== 'PENDING') {
                throw new RuntimeException('Dieser Urlaubsantrag wurde bereits bearbeitet');
            }

            $requestType = strtoupper((string)($request['request_type'] ?? 'CREATE'));
            if (!in_array($requestType, ['CREATE', 'CHANGE', 'DELETE'], true)) {
                throw new RuntimeException('Der Antragstyp ist ungültig');
            }

            $absenceId = null;
            $action = 'created';
            if ($decision === 'APPROVED') {
                if ((int)$request['employee_active'] !== 1) {
                    throw new RuntimeException('Der Mitarbeiter ist nicht mehr aktiv; der Antrag kann nur noch abgelehnt werden');
                }

                if ($requestType === 'CREATE') {
                    $absenceId = $this->createAbsence(
                        (int)$request['employee_id'],
                        'VACATION',
                        (string)$request['start_date'],
                        (string)$request['end_date'],
                        (string)$request['note'],
                        (string)$request['portion'],
                        'vacation_request'
                    );
                } else {
                    $targetAbsenceId = (int)($request['target_absence_id'] ?? 0);
                    $targetSt = $this->pdo->prepare(
                        "SELECT * FROM absence WHERE id=? AND employee_id=? AND type='VACATION'"
                    );
                    $targetSt->execute([$targetAbsenceId, (int)$request['employee_id']]);
                    $target = $targetSt->fetch();
                    if (!$target) {
                        throw new RuntimeException('Der zugehörige Urlaub existiert nicht mehr; der Antrag kann nur noch abgelehnt werden');
                    }

                    $snapshotMatches = (string)$target['start_date'] === (string)($request['original_start_date'] ?? '')
                        && (string)$target['end_date'] === (string)($request['original_end_date'] ?? '')
                        && (string)$target['portion'] === (string)($request['original_portion'] ?? '');
                    if (!$snapshotMatches) {
                        throw new RuntimeException('Der Urlaub wurde zwischenzeitlich geändert; der Antrag kann nur noch abgelehnt werden');
                    }

                    if ($requestType === 'CHANGE') {
                        $this->updateAbsence(
                            $targetAbsenceId,
                            'VACATION',
                            (string)$request['start_date'],
                            (string)$request['end_date'],
                            (string)$target['note'],
                            (string)$request['portion']
                        );
                        $absenceId = $targetAbsenceId;
                        $action = 'changed';
                    } else {
                        $this->deleteAbsence($targetAbsenceId);
                        $action = 'deleted';
                    }
                }
            }

            $now = $this->nowUtc();
            $update = $this->pdo->prepare(
                'UPDATE vacation_request
                 SET status=?, decided_at=?, decided_by=?, decision_note=?, absence_id=?, updated_at=?
                 WHERE id=? AND status=\'PENDING\''
            );
            $update->execute([$decision, $now, $adminId, trim($decisionNote), $absenceId, $now, $requestId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Der Urlaubsantrag konnte nicht mehr bearbeitet werden');
            }
            $this->pdo->commit();

            return [
                'status' => $decision,
                'absence_id' => $absenceId,
                'employee_name' => (string)$request['employee_name'],
                'request_type' => $requestType,
                'action' => $decision === 'APPROVED' ? $action : 'rejected',
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getCurrentWeekInfo(): array
    {
        $period = self::reportPeriod('week');
        return [
            'start_date' => $period['start_date'],
            'end_date' => $period['end_date'],
            'start_label' => $period['start_label'],
            'end_label' => $period['end_label'],
            'week' => $period['week'],
            'year' => $period['year'],
        ];
    }

    public static function reportPeriod(string $type, string $reference = '', ?DateTimeImmutable $now = null): array
    {
        $timezone = new DateTimeZone('Europe/Berlin');
        $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $type = strtolower(trim($type));
        if (!in_array($type, ['week', 'month', 'year'], true)) {
            throw new RuntimeException('Der Zeitraumtyp ist ungültig');
        }

        if ($type === 'week') {
            $reference = trim($reference) !== '' ? trim($reference) : $now->format('o-\WW');
            if (!preg_match('/^(\d{4})-W(\d{2})$/', $reference, $matches)) {
                throw new RuntimeException('Die Kalenderwoche ist ungültig');
            }
            $year = (int)$matches[1];
            $week = (int)$matches[2];
            if ($year < 1970 || $year > 2200 || $week < 1 || $week > 53) {
                throw new RuntimeException('Die Kalenderwoche ist ungültig');
            }
            $start = (new DateTimeImmutable('now', $timezone))->setISODate($year, $week)->setTime(0, 0);
            if ($start->format('o-\WW') !== sprintf('%04d-W%02d', $year, $week)) {
                throw new RuntimeException('Die Kalenderwoche existiert nicht');
            }
            $end = $start->modify('+6 days');
            return [
                'type' => 'week',
                'reference' => sprintf('%04d-W%02d', $year, $week),
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'start_label' => $start->format('d.m.Y'),
                'end_label' => $end->format('d.m.Y'),
                'range_label' => $start->format('d.m.Y') . ' bis ' . $end->format('d.m.Y'),
                'title' => 'KW ' . sprintf('%02d', $week) . ' / ' . $year,
                'filename' => sprintf('Arbeitszeiten_KW_%02d_%d.pdf', $week, $year),
                'week' => $week,
                'year' => $year,
            ];
        }

        if ($type === 'month') {
            $reference = trim($reference) !== '' ? trim($reference) : $now->format('Y-m');
            if (!preg_match('/^(\d{4})-(\d{2})$/', $reference, $matches)) {
                throw new RuntimeException('Der Monat ist ungültig');
            }
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            if ($year < 1970 || $year > 2200 || $month < 1 || $month > 12) {
                throw new RuntimeException('Der Monat ist ungültig');
            }
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $timezone);
            $end = $start->modify('last day of this month');
            $monthName = self::germanMonthName($month);
            return [
                'type' => 'month',
                'reference' => sprintf('%04d-%02d', $year, $month),
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'start_label' => $start->format('d.m.Y'),
                'end_label' => $end->format('d.m.Y'),
                'range_label' => $start->format('d.m.Y') . ' bis ' . $end->format('d.m.Y'),
                'title' => $monthName . ' ' . $year,
                'filename' => sprintf('Arbeitszeiten_Monat_%04d-%02d.pdf', $year, $month),
                'month' => $month,
                'year' => $year,
            ];
        }

        $reference = trim($reference) !== '' ? trim($reference) : $now->format('Y');
        if (!preg_match('/^\d{4}$/', $reference)) {
            throw new RuntimeException('Das Jahr ist ungültig');
        }
        $year = (int)$reference;
        if ($year < 1970 || $year > 2200) {
            throw new RuntimeException('Das Jahr ist ungültig');
        }
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $timezone);
        $end = new DateTimeImmutable(sprintf('%04d-12-31 00:00:00', $year), $timezone);
        return [
            'type' => 'year',
            'reference' => (string)$year,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'start_label' => $start->format('d.m.Y'),
            'end_label' => $end->format('d.m.Y'),
            'range_label' => $start->format('d.m.Y') . ' bis ' . $end->format('d.m.Y'),
            'title' => 'Kalenderjahr ' . $year,
            'filename' => sprintf('Arbeitszeiten_Jahr_%d.pdf', $year),
            'year' => $year,
        ];
    }

    public function buildWeekReport(array $employeeIds): array
    {
        return $this->buildTimeReport($employeeIds, 'week');
    }

    public function buildTimeReport(array $employeeIds, string $type, string $reference = ''): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn(int $id): bool => $id > 0)));
        if (!$employeeIds) {
            throw new RuntimeException('Bitte mindestens einen Mitarbeiter auswählen');
        }
        if (count($employeeIds) > 100) {
            throw new RuntimeException('Es wurden zu viele Mitarbeiter ausgewählt');
        }

        $period = self::reportPeriod($type, $reference);
        $marks = implode(',', array_fill(0, count($employeeIds), '?'));
        $st = $this->pdo->prepare("SELECT * FROM employee WHERE id IN ($marks) ORDER BY name");
        $st->execute($employeeIds);
        $employees = $st->fetchAll();
        if (!$employees) {
            throw new RuntimeException('Keine Mitarbeiter gefunden');
        }

        $reportTimezone = new DateTimeZone('Europe/Berlin');
        $periodStart = new DateTimeImmutable($period['start_date'] . ' 00:00:00', $reportTimezone);
        $periodEndExclusive = (new DateTimeImmutable($period['end_date'] . ' 00:00:00', $reportTimezone))->modify('+1 day');
        $reports = [];
        foreach ($employees as $employee) {
            $reports[] = $this->buildEmployeeTimeReport($employee, $periodStart, $periodEndExclusive, $period['type']);
        }

        return [
            'type' => $period['type'],
            'period' => $period,
            'employees' => $reports,
            'created_at' => $this->now()->setTimezone($reportTimezone)->format('d.m.Y H:i'),
        ];
    }

    private function buildEmployeeTimeReport(
        array $employee,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEndExclusive,
        string $reportType
    ): array {
        $employeeId = (int)$employee['id'];
        $reportTimezone = new DateTimeZone('Europe/Berlin');
        $utc = new DateTimeZone('UTC');
        $rangeStart = $periodStart->setTimezone($utc)->format('Y-m-d H:i:s');
        $rangeEnd = $periodEndExclusive->setTimezone($utc)->format('Y-m-d H:i:s');
        $nowUtc = $this->nowUtc();

        $sessionsSt = $this->pdo->prepare(
            'SELECT * FROM work_session WHERE employee_id=? AND started_at < ? AND COALESCE(ended_at, ?) > ? ORDER BY started_at'
        );
        $sessionsSt->execute([$employeeId, $rangeEnd, $nowUtc, $rangeStart]);
        $sessions = $sessionsSt->fetchAll();

        $breaksBySession = [];
        if ($sessions) {
            $sessionIds = array_map(static fn(array $row): int => (int)$row['id'], $sessions);
            $sessionMarks = implode(',', array_fill(0, count($sessionIds), '?'));
            $breakSt = $this->pdo->prepare("SELECT * FROM break_session WHERE work_session_id IN ($sessionMarks) ORDER BY started_at");
            $breakSt->execute($sessionIds);
            foreach ($breakSt->fetchAll() as $break) {
                $breaksBySession[(int)$break['work_session_id']][] = $break;
            }
        }

        $startDate = $periodStart->format('Y-m-d');
        $endDate = $periodEndExclusive->modify('-1 day')->format('Y-m-d');
        $absenceSt = $this->pdo->prepare(
            'SELECT * FROM absence WHERE employee_id=? AND start_date<=? AND end_date>=? ORDER BY start_date, id'
        );
        $absenceSt->execute([$employeeId, $endDate, $startDate]);
        $absences = $absenceSt->fetchAll();

        $scheduleRows = $this->loadScheduleRowsForPeriod($employeeId, $startDate, $endDate);
        $holidayRows = $this->loadHolidaysForPeriod((string)$employee['holiday_region'], $startDate, $endDate);
        $dayNames = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        $absenceLabels = ['VACATION' => 'Urlaub', 'SICK' => 'Krank', 'SCHOOL' => 'Schule', 'OTHER' => 'Sonstiges'];

        $days = [];
        $totals = [
            'work_seconds' => 0,
            'break_seconds' => 0,
            'planned_seconds' => 0,
            'vacation_days' => 0.0,
            'sick_days' => 0.0,
            'holiday_days' => 0,
            'presence_days' => 0,
        ];

        for ($dayStart = $periodStart; $dayStart < $periodEndExclusive; $dayStart = $dayStart->modify('+1 day')) {
            $dayEnd = $dayStart->modify('+1 day');
            $date = $dayStart->format('Y-m-d');
            $weekday = (int)$dayStart->format('N');
            $isWeekend = $weekday >= 6;
            $isHoliday = isset($holidayRows[$date]);
            $dayStartUtc = $dayStart->setTimezone($utc)->format('Y-m-d H:i:s');
            $dayEndUtc = $dayEnd->setTimezone($utc)->format('Y-m-d H:i:s');
            $plannedSeconds = $this->scheduledMinutesFromRows($scheduleRows, $weekday, $date) * 60;
            $firstStart = null;
            $lastEnd = null;
            $hasOpenSession = false;
            $hasWorkSession = false;
            $grossSeconds = 0;
            $breakSeconds = 0;

            foreach ($sessions as $session) {
                $sessionEnd = $this->effectiveSessionEndUtc($session, (string)$employee['timezone'], $nowUtc);
                $seconds = $this->overlapSeconds((string)$session['started_at'], $sessionEnd, $dayStartUtc, $dayEndUtc);
                if ($seconds < 1) {
                    continue;
                }
                $hasWorkSession = true;
                $grossSeconds += $seconds;

                $startTs = max(strtotime($session['started_at'] . ' UTC'), strtotime($dayStartUtc . ' UTC'));
                $endTs = min(strtotime($sessionEnd . ' UTC'), strtotime($dayEndUtc . ' UTC'));
                $firstStart = $firstStart === null ? $startTs : min($firstStart, $startTs);
                $lastEnd = $lastEnd === null ? $endTs : max($lastEnd, $endTs);
                if (!$session['ended_at'] && !$this->isStaleWorkSession($session, (string)$employee['timezone']) && $endTs === strtotime($nowUtc . ' UTC')) {
                    $hasOpenSession = true;
                }

                foreach ($breaksBySession[(int)$session['id']] ?? [] as $break) {
                    $breakEnd = $break['ended_at'] ?: $sessionEnd;
                    if ($breakEnd > $sessionEnd) {
                        $breakEnd = $sessionEnd;
                    }
                    $breakSeconds += $this->overlapSeconds((string)$break['started_at'], (string)$breakEnd, $dayStartUtc, $dayEndUtc);
                }
            }

            $recordedWorkSeconds = self::calculateNetSeconds($grossSeconds, $breakSeconds);
            $workSeconds = $recordedWorkSeconds;
            $noteParts = [];
            $absenceType = null;
            $absenceCredit = 0;
            $vacationFraction = 0.0;
            $sickFraction = 0.0;

            foreach ($absences as $absence) {
                if ($absence['start_date'] > $date || $absence['end_date'] < $date) {
                    continue;
                }
                $absenceType ??= (string)$absence['type'];
                $label = $absenceLabels[$absence['type']] ?? 'Abwesend';
                $portion = (string)($absence['portion'] ?? 'FULL');
                if ($portion === 'AM') {
                    $label .= ' vormittags';
                } elseif ($portion === 'PM') {
                    $label .= ' nachmittags';
                }
                $noteParts[] = $label;

                $fraction = $portion === 'FULL' ? 1.0 : 0.5;
                if (!$isWeekend && !$isHoliday && $plannedSeconds > 0) {
                    if ($absence['type'] === 'VACATION') {
                        $vacationFraction = max($vacationFraction, $fraction);
                    } elseif ($absence['type'] === 'SICK') {
                        $sickFraction = max($sickFraction, $fraction);
                    }
                }

                if (!in_array($absence['type'], ['VACATION', 'SICK'], true)) {
                    continue;
                }
                if ($absence['credit_minutes_override'] !== null) {
                    $credit = (int)$absence['credit_minutes_override'] * 60;
                } elseif ($portion === 'FULL') {
                    $credit = $plannedSeconds;
                } else {
                    $credit = (int)round($plannedSeconds / 2);
                }
                if ($portion === 'FULL' && $hasWorkSession) {
                    $credit = 0;
                }
                $absenceCredit = max($absenceCredit, $credit);
            }

            $holidayCredit = 0;
            $holidayDay = 0;
            if (!$hasWorkSession && $absenceType === null && !(int)$employee['special_time'] && $isHoliday) {
                $holidayCredit = $plannedSeconds;
                $holidayDay = $plannedSeconds > 0 ? 1 : 0;
                $noteParts[] = (string)$holidayRows[$date];
            }

            $workSeconds += max($absenceCredit, $holidayCredit);
            $differenceSeconds = $workSeconds - $plannedSeconds;
            $presenceDay = $recordedWorkSeconds > 0 ? 1 : 0;
            $day = [
                'date_iso' => $date,
                'day' => $dayNames[$weekday],
                'date' => $dayStart->format('d.m.Y'),
                'date_short' => $dayStart->format('d.m.'),
                'month' => (int)$dayStart->format('n'),
                'start' => $firstStart === null ? '-' : (new DateTimeImmutable('@' . $firstStart))->setTimezone($reportTimezone)->format('H:i'),
                'end' => $lastEnd === null ? '-' : ($hasOpenSession ? 'offen' : (new DateTimeImmutable('@' . $lastEnd))->setTimezone($reportTimezone)->format('H:i')),
                'break_seconds' => $breakSeconds,
                'recorded_work_seconds' => $recordedWorkSeconds,
                'work_seconds' => $workSeconds,
                'planned_seconds' => $plannedSeconds,
                'difference_seconds' => $differenceSeconds,
                'note' => implode(', ', array_unique($noteParts)),
                'absence_type' => $absenceType,
                'vacation_days' => $vacationFraction,
                'sick_days' => $sickFraction,
                'holiday_days' => $holidayDay,
                'presence_days' => $presenceDay,
                'is_weekend' => $isWeekend,
                'is_holiday' => $isHoliday,
            ];
            $days[] = $day;

            $totals['work_seconds'] += $workSeconds;
            $totals['break_seconds'] += $breakSeconds;
            $totals['planned_seconds'] += $plannedSeconds;
            $totals['vacation_days'] += $vacationFraction;
            $totals['sick_days'] += $sickFraction;
            $totals['holiday_days'] += $holidayDay;
            $totals['presence_days'] += $presenceDay;
        }

        $months = [];
        if ($reportType === 'year') {
            for ($month = 1; $month <= 12; $month++) {
                $months[$month] = [
                    'month' => $month,
                    'name' => self::germanMonthName($month),
                    'work_seconds' => 0,
                    'break_seconds' => 0,
                    'planned_seconds' => 0,
                    'difference_seconds' => 0,
                    'vacation_days' => 0.0,
                    'sick_days' => 0.0,
                    'holiday_days' => 0,
                    'presence_days' => 0,
                ];
            }
            foreach ($days as $day) {
                $month = (int)$day['month'];
                $months[$month]['work_seconds'] += (int)$day['work_seconds'];
                $months[$month]['break_seconds'] += (int)$day['break_seconds'];
                $months[$month]['planned_seconds'] += (int)$day['planned_seconds'];
                $months[$month]['vacation_days'] += (float)$day['vacation_days'];
                $months[$month]['sick_days'] += (float)$day['sick_days'];
                $months[$month]['holiday_days'] += (int)$day['holiday_days'];
                $months[$month]['presence_days'] += (int)$day['presence_days'];
            }
            foreach ($months as &$month) {
                $month['difference_seconds'] = $month['work_seconds'] - $month['planned_seconds'];
            }
            unset($month);
            $months = array_values($months);
        }

        return [
            'employee' => $employee,
            'days' => $days,
            'months' => $months,
            'work_seconds' => $totals['work_seconds'],
            'break_seconds' => $totals['break_seconds'],
            'planned_seconds' => $totals['planned_seconds'],
            'difference_seconds' => $totals['work_seconds'] - $totals['planned_seconds'],
            'vacation_days' => $totals['vacation_days'],
            'sick_days' => $totals['sick_days'],
            'holiday_days' => $totals['holiday_days'],
            'presence_days' => $totals['presence_days'],
        ];
    }

    private function loadScheduleRowsForPeriod(int $employeeId, string $startDate, string $endDate): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, weekday, target_minutes, valid_from, valid_to
             FROM employee_schedule
             WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?)
             ORDER BY weekday, valid_from DESC, id DESC'
        );
        $st->execute([$employeeId, $endDate, $startDate]);
        $rows = [];
        foreach ($st->fetchAll() as $row) {
            $rows[(int)$row['weekday']][] = $row;
        }
        return $rows;
    }

    private function scheduledMinutesFromRows(array $rows, int $weekday, string $date): int
    {
        foreach ($rows[$weekday] ?? [] as $row) {
            if ((string)$row['valid_from'] <= $date && ($row['valid_to'] === null || (string)$row['valid_to'] >= $date)) {
                return (int)$row['target_minutes'];
            }
        }
        return (int)$this->getWorkRule($weekday)['default_target_minutes'];
    }

    private function loadHolidaysForPeriod(string $region, string $startDate, string $endDate): array
    {
        $region = trim($region) !== '' ? trim($region) : (string)cfg('default_holiday_region', 'DE-BY-KF');
        $st = $this->pdo->prepare(
            'SELECT day, name FROM public_holiday WHERE region=? AND day>=? AND day<=? ORDER BY day'
        );
        $st->execute([$region, $startDate, $endDate]);
        $holidays = [];
        foreach ($st->fetchAll() as $holiday) {
            $holidays[(string)$holiday['day']] = (string)$holiday['name'];
        }
        return $holidays;
    }

    private static function germanMonthName(int $month): string
    {
        return [
            1 => 'Januar',
            2 => 'Februar',
            3 => 'März',
            4 => 'April',
            5 => 'Mai',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'August',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Dezember',
        ][$month] ?? '';
    }

    public function listHolidaysForYear(string $region, int $year): array
    {
        $region = trim($region) !== '' ? trim($region) : (string)cfg('default_holiday_region', 'DE-BY-KF');
        $st = $this->pdo->prepare('SELECT day, name, region FROM public_holiday WHERE substr(day,1,4)=? AND region=? ORDER BY day');
        $st->execute([(string)$year, $region]);
        return $st->fetchAll();
    }

    private function removeAbsenceForDay(int $employeeId, string $day): bool
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM absence WHERE employee_id=? AND portion='FULL' AND start_date<=? AND end_date>=? ORDER BY id"
        );
        $st->execute([$employeeId, $day, $day]);
        $absences = $st->fetchAll();
        if (!$absences) {
            return false;
        }

        $now = $this->nowUtc();
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

            $update = $this->pdo->prepare('UPDATE absence SET start_date=?, end_date=?, updated_at=? WHERE id=?');
            $update->execute([$ranges[0]['start_date'], $ranges[0]['end_date'], $now, $absenceId]);

            if (isset($ranges[1])) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO absence(employee_id, type, portion, start_date, end_date, note, source, credit_minutes_override, created_at, updated_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?)'
                );
                $insert->execute([
                    $employeeId,
                    $absence['type'],
                    'FULL',
                    $ranges[1]['start_date'],
                    $ranges[1]['end_date'],
                    $absence['note'],
                    $absence['source'],
                    $absence['credit_minutes_override'],
                    $absence['created_at'],
                    $now,
                ]);
            }
        }

        return true;
    }

    private function validateAbsenceInput(
        int $employeeId,
        string $type,
        string $portion,
        string $startDate,
        string $endDate
    ): void {
        if (!in_array($type, ['VACATION', 'SICK', 'SCHOOL', 'OTHER'], true)) {
            throw new RuntimeException('Die Art ist ungültig');
        }
        if (!in_array($portion, ['FULL', 'AM', 'PM'], true)) {
            throw new RuntimeException('Der Tagesanteil ist ungültig');
        }
        if ($portion !== 'FULL' && $type !== 'VACATION') {
            throw new RuntimeException('Halbe Tage sind nur für Urlaub vorgesehen');
        }
        if (!$this->validDate($startDate) || !$this->validDate($endDate) || $endDate < $startDate) {
            throw new RuntimeException('Der Zeitraum ist ungültig');
        }
        if ($portion !== 'FULL' && $startDate !== $endDate) {
            throw new RuntimeException('Ein halber Urlaubstag muss auf genau ein Datum fallen');
        }
        if (!$this->getEmployee($employeeId)) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }
    }

    private function getWorkRule(int $weekday): array
    {
        $weekday = max(1, min(7, $weekday));
        if (isset($this->workRuleCache[$weekday])) {
            return $this->workRuleCache[$weekday];
        }
        $st = $this->pdo->prepare('SELECT * FROM work_rule WHERE weekday=?');
        $st->execute([$weekday]);
        $rule = $st->fetch();
        if (!$rule) {
            $rule = [
                'weekday' => $weekday,
                'earliest_start' => '07:30',
                'break_bonus_until' => '08:00',
                'forgotten_end' => $weekday === 5 ? '12:00' : '17:00',
                'base_break_minutes' => $weekday === 5 ? 0 : 30,
                'default_target_minutes' => match ($weekday) {
                    1, 2, 3, 4 => 510,
                    5 => 240,
                    default => 0,
                },
            ];
        }
        return $this->workRuleCache[$weekday] = $rule;
    }

    private function isWorkStartAllowedByRule(DateTimeImmutable $localNow, array $rule): bool
    {
        return $localNow->format('H:i') >= (string)$rule['earliest_start'];
    }

    private function calculateBreakAllowanceForRule(DateTimeImmutable $localWorkStart, array $rule): int
    {
        [$hour, $minute] = array_map('intval', explode(':', (string)$rule['break_bonus_until']));
        $bonusUntil = $localWorkStart->setTime($hour, $minute, 0);
        $earlyStartBonus = max(0, $bonusUntil->getTimestamp() - $localWorkStart->getTimestamp());
        return ((int)$rule['base_break_minutes'] * 60) + $earlyStartBonus;
    }

    private function getScheduledMinutesForDate(int $employeeId, string $date): int
    {
        $weekday = (int)(new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->format('N');
        $st = $this->pdo->prepare(
            'SELECT target_minutes FROM employee_schedule
             WHERE employee_id=? AND weekday=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?)
             ORDER BY valid_from DESC, id DESC LIMIT 1'
        );
        $st->execute([$employeeId, $weekday, $date, $date]);
        $minutes = $st->fetchColumn();
        if ($minutes !== false) {
            return (int)$minutes;
        }
        return (int)$this->getWorkRule($weekday)['default_target_minutes'];
    }

    private function insertScheduleVersion(int $employeeId, string $validFrom, array $minutesByWeekday, string $source): void
    {
        $now = $this->nowUtc();
        $st = $this->pdo->prepare(
            'INSERT INTO employee_schedule(employee_id, valid_from, valid_to, weekday, target_minutes, planned_start, planned_end, source, created_at, updated_at)
             VALUES(?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)'
        );
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $minutes = max(0, min(1440, (int)($minutesByWeekday[$weekday] ?? 0)));
            $plannedStart = $minutes > 0 ? '08:00' : '';
            $plannedEnd = $minutes > 0 ? self::minutesToTime((8 * 60) + $minutes) : '';
            $st->execute([$employeeId, $validFrom, $weekday, $minutes, $plannedStart, $plannedEnd, $source, $now, $now]);
        }
    }

    private static function defaultScheduleMinutes(float $weeklyHours): array
    {
        $weeklyMinutes = max(0, (int)round($weeklyHours * 60));
        if ($weeklyMinutes === 0) {
            return [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
        }
        $weights = [1 => 510, 2 => 510, 3 => 510, 4 => 510, 5 => 240];
        $weightTotal = array_sum($weights);
        $result = [];
        $assigned = 0;
        foreach ($weights as $weekday => $weight) {
            $minutes = (int)round($weeklyMinutes * ($weight / $weightTotal));
            $result[$weekday] = $minutes;
            $assigned += $minutes;
        }
        $result[5] += $weeklyMinutes - $assigned;
        $result[6] = 0;
        $result[7] = 0;
        return $result;
    }

    private static function minutesToTime(int $minutesFromMidnight): string
    {
        $minutesFromMidnight = max(0, min(1439, $minutesFromMidnight));
        return sprintf('%02d:%02d', intdiv($minutesFromMidnight, 60), $minutesFromMidnight % 60);
    }

    private function upsertVacationAccountInternal(
        int $employeeId,
        int $year,
        float $entitlementDays,
        float $carryoverDays,
        float $adjustmentDays,
        string $note,
        string $source
    ): void {
        $now = $this->nowUtc();
        $st = $this->pdo->prepare(
            'INSERT INTO vacation_account(employee_id, year, entitlement_days, carryover_days, adjustment_days, note, source, created_at, updated_at)
             VALUES(?,?,?,?,?,?,?,?,?)
             ON CONFLICT(employee_id, year) DO UPDATE SET
                entitlement_days=excluded.entitlement_days,
                carryover_days=excluded.carryover_days,
                adjustment_days=excluded.adjustment_days,
                note=excluded.note,
                source=excluded.source,
                updated_at=excluded.updated_at'
        );
        $st->execute([$employeeId, $year, $entitlementDays, $carryoverDays, $adjustmentDays, $note, $source, $now, $now]);
    }

    private function ensureVacationAccountRecord(int $employeeId, int $year, array $employee): array
    {
        $st = $this->pdo->prepare('SELECT * FROM vacation_account WHERE employee_id=? AND year=?');
        $st->execute([$employeeId, $year]);
        $account = $st->fetch() ?: null;

        $previous = null;
        if ($year > 1970) {
            $previousSt = $this->pdo->prepare('SELECT 1 FROM vacation_account WHERE employee_id=? AND year=?');
            $previousSt->execute([$employeeId, $year - 1]);
            $hasImmediatePrevious = (bool)$previousSt->fetchColumn();

            if (!$hasImmediatePrevious) {
                $olderSt = $this->pdo->prepare('SELECT MAX(year) FROM vacation_account WHERE employee_id=? AND year<?');
                $olderSt->execute([$employeeId, $year]);
                $latestOlderYear = (int)($olderSt->fetchColumn() ?: 0);
                if ($latestOlderYear > 0) {
                    // Fill skipped years one by one so entitlement and carryover
                    // continue consistently even when an admin jumps ahead.
                    $this->ensureVacationAccountRecord($employeeId, $year - 1, $employee);
                    $hasImmediatePrevious = true;
                }
            }

            if ($hasImmediatePrevious) {
                $previous = $this->ensureVacationAccountRecord($employeeId, $year - 1, $employee);
            }
        }

        $automaticCarryover = null;
        if ($previous !== null) {
            $previousUsage = $this->calculateVacationUsageBreakdown(
                $employeeId,
                $year - 1,
                (string)$employee['holiday_region']
            );
            $previousState = $this->vacationCapacityState(
                (float)$previous['entitlement_days'],
                (float)$previous['carryover_days'],
                (float)$previous['adjustment_days'],
                (float)$previousUsage['early_days'],
                (float)$previousUsage['late_days']
            );
            // At the end of a year any older carryover has already expired.
            // Only the unused entitlement of the immediately previous year is transferred.
            $automaticCarryover = max(0.0, (float)$previousState['entitlement_remaining_days']);
        }

        if ($account === null) {
            $entitlement = $previous !== null ? (float)$previous['entitlement_days'] : 0.0;
            $carryover = $automaticCarryover ?? 0.0;
            $this->upsertVacationAccountInternal(
                $employeeId,
                $year,
                $entitlement,
                $carryover,
                0.0,
                '',
                'system'
            );
            $st->execute([$employeeId, $year]);
            $account = $st->fetch();
        } elseif ($automaticCarryover !== null && abs((float)$account['carryover_days'] - $automaticCarryover) > 0.0001) {
            $update = $this->pdo->prepare('UPDATE vacation_account SET carryover_days=?, updated_at=? WHERE id=?');
            $update->execute([$automaticCarryover, $this->nowUtc(), (int)$account['id']]);
            $account['carryover_days'] = $automaticCarryover;
        }

        $account['carryover_automatic'] = $automaticCarryover !== null;
        return $account;
    }

    private function appendVacationRequestDayValues(array &$request): void
    {
        $employeeId = (int)$request['employee_id'];
        $requestType = strtoupper((string)($request['request_type'] ?? 'CREATE'));
        $request['request_type'] = in_array($requestType, ['CREATE', 'CHANGE', 'DELETE'], true)
            ? $requestType
            : 'CREATE';
        $request['requested_days'] = $this->calculateVacationDaysForRange(
            $employeeId,
            (string)$request['start_date'],
            (string)$request['end_date'],
            (string)$request['portion']
        );

        $originalStart = (string)($request['original_start_date'] ?? '');
        $originalEnd = (string)($request['original_end_date'] ?? '');
        $originalPortion = (string)($request['original_portion'] ?? 'FULL');
        $request['original_days'] = $originalStart !== '' && $originalEnd !== ''
            ? $this->calculateVacationDaysForRange($employeeId, $originalStart, $originalEnd, $originalPortion)
            : 0.0;
    }

    private function assertNoPendingVacationRequestOverlap(
        int $employeeId,
        string $startDate,
        string $endDate,
        int $excludeRequestId = 0
    ): void {
        $sql = "SELECT COUNT(*) FROM vacation_request
                WHERE employee_id=? AND status='PENDING'
                  AND request_type IN ('CREATE', 'CHANGE')
                  AND start_date<=? AND end_date>=?";
        $params = [$employeeId, $endDate, $startDate];
        if ($excludeRequestId > 0) {
            $sql .= ' AND id<>?';
            $params[] = $excludeRequestId;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        if ((int)$st->fetchColumn() > 0) {
            throw new RuntimeException('Für diesen Zeitraum gibt es bereits einen offenen Urlaubs- oder Änderungsantrag');
        }
    }

    private function assertVacationPlanAvailableWithPendingRequests(int $employeeId, ?array $candidate = null): void
    {
        $pending = $this->pdo->prepare(
            "SELECT request_type, target_absence_id, start_date, end_date, portion
             FROM vacation_request
             WHERE employee_id=? AND status='PENDING'"
        );
        $pending->execute([$employeeId]);
        $requests = $pending->fetchAll();
        if ($candidate !== null) {
            $requests[] = $candidate;
        }

        $ranges = [];
        $excludeAbsenceIds = [];
        foreach ($requests as $request) {
            $type = strtoupper((string)($request['request_type'] ?? 'CREATE'));
            $targetAbsenceId = (int)($request['target_absence_id'] ?? 0);
            // A pending deletion must not free vacation capacity before it is
            // actually approved. A pending change, however, replaces its
            // existing absence in the simulated plan.
            if ($type === 'CHANGE' && $targetAbsenceId > 0) {
                $excludeAbsenceIds[$targetAbsenceId] = $targetAbsenceId;
            }
            if (in_array($type, ['CREATE', 'CHANGE'], true)) {
                $ranges[] = [
                    'start_date' => (string)$request['start_date'],
                    'end_date' => (string)$request['end_date'],
                    'portion' => (string)$request['portion'],
                ];
            }
        }

        if ($ranges === []) {
            return;
        }
        $this->assertVacationBalanceAvailable($employeeId, $ranges, array_values($excludeAbsenceIds));
    }

    private function assertNoAbsenceOverlap(int $employeeId, string $startDate, string $endDate, int $excludeAbsenceId = 0): void
    {
        $sql = 'SELECT COUNT(*) FROM absence WHERE employee_id=? AND start_date<=? AND end_date>=?';
        $params = [$employeeId, $endDate, $startDate];
        if ($excludeAbsenceId > 0) {
            $sql .= ' AND id<>?';
            $params[] = $excludeAbsenceId;
        }
        $check = $this->pdo->prepare($sql);
        $check->execute($params);
        if ((int)$check->fetchColumn() > 0) {
            throw new RuntimeException('In dem Zeitraum gibt es schon eine Abwesenheit');
        }
    }

    private function calculateVacationDaysForRange(int $employeeId, string $startDate, string $endDate, string $portion): float
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee || !$this->validDate($startDate) || !$this->validDate($endDate) || $endDate < $startDate) {
            return 0.0;
        }
        $byYear = $this->vacationUsageForRangeByYear(
            $employeeId,
            (string)$employee['holiday_region'],
            $startDate,
            $endDate,
            $portion
        );
        return array_sum(array_map(static fn(array $usage): float => (float)$usage['total_days'], $byYear));
    }

    private function assertVacationBalanceAvailable(int $employeeId, array $ranges, int|array $excludeAbsenceIds = []): void
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) {
            throw new RuntimeException('Mitarbeiter nicht gefunden');
        }

        $requestedByYear = [];
        foreach ($ranges as $range) {
            $startDate = (string)($range['start_date'] ?? '');
            $endDate = (string)($range['end_date'] ?? '');
            $portion = (string)($range['portion'] ?? 'FULL');
            if (!$this->validDate($startDate) || !$this->validDate($endDate) || $endDate < $startDate) {
                throw new RuntimeException('Der Urlaubszeitraum ist ungültig');
            }
            foreach ($this->vacationUsageForRangeByYear(
                $employeeId,
                (string)$employee['holiday_region'],
                $startDate,
                $endDate,
                $portion
            ) as $year => $usage) {
                $requestedByYear[$year]['early_days'] = ($requestedByYear[$year]['early_days'] ?? 0.0) + (float)$usage['early_days'];
                $requestedByYear[$year]['late_days'] = ($requestedByYear[$year]['late_days'] ?? 0.0) + (float)$usage['late_days'];
            }
        }

        if ($requestedByYear === []) {
            throw new RuntimeException('Der Zeitraum enthält keinen geplanten Arbeitstag');
        }

        foreach ($requestedByYear as $year => $requested) {
            $year = (int)$year;
            $account = $this->ensureVacationAccountRecord($employeeId, $year, $employee);
            $approved = $this->calculateVacationUsageBreakdown(
                $employeeId,
                $year,
                (string)$employee['holiday_region'],
                $excludeAbsenceIds
            );
            $this->assertVacationUsageFitsAccount(
                $year,
                (float)$account['entitlement_days'],
                (float)$account['carryover_days'],
                (float)$account['adjustment_days'],
                (float)$approved['early_days'] + (float)$requested['early_days'],
                (float)$approved['late_days'] + (float)$requested['late_days']
            );
        }
    }

    private function assertVacationUsageFitsAccount(
        int $year,
        float $entitlementDays,
        float $carryoverDays,
        float $adjustmentDays,
        float $earlyDays,
        float $lateDays
    ): void {
        $state = $this->vacationCapacityState(
            $entitlementDays,
            $carryoverDays,
            $adjustmentDays,
            $earlyDays,
            $lateDays
        );
        $availableEntitlement = $entitlementDays + $adjustmentDays;
        $availableUntilMarch = $availableEntitlement + max(0.0, $carryoverDays);
        $epsilon = 0.0001;

        if ($earlyDays > $availableUntilMarch + $epsilon || (float)$state['entitlement_used_days'] > $availableEntitlement + $epsilon) {
            $available = max(0.0, $availableEntitlement)
                + min(max(0.0, $carryoverDays), max(0.0, $earlyDays));
            $used = $earlyDays + $lateDays;
            throw new RuntimeException(sprintf(
                'Nicht genügend Urlaub für %d: %.1f Tage geplant, %.1f Tage verfügbar. Resturlaub aus dem Vorjahr verfällt nach dem 31.03.',
                $year,
                $used,
                $available
            ));
        }
    }

    private function vacationCapacityState(
        float $entitlementDays,
        float $carryoverDays,
        float $adjustmentDays,
        float $earlyDays,
        float $lateDays
    ): array {
        $usableCarryover = max(0.0, $carryoverDays);
        $carryoverUsed = min($usableCarryover, max(0.0, $earlyDays));
        $entitlementUsed = max(0.0, $earlyDays - $carryoverUsed) + max(0.0, $lateDays);
        $entitlementTotal = $entitlementDays + $adjustmentDays;

        return [
            'total_days' => $entitlementTotal + $usableCarryover,
            'carryover_used_days' => $carryoverUsed,
            'carryover_remaining_days' => max(0.0, $usableCarryover - $carryoverUsed),
            'entitlement_used_days' => $entitlementUsed,
            'entitlement_remaining_days' => $entitlementTotal - $entitlementUsed,
        ];
    }

    private function vacationUsageForRangeByYear(
        int $employeeId,
        string $region,
        string $startDate,
        string $endDate,
        string $portion
    ): array {
        $current = new DateTimeImmutable($startDate . ' 00:00:00', new DateTimeZone('UTC'));
        $last = new DateTimeImmutable($endDate . ' 00:00:00', new DateTimeZone('UTC'));
        $byYear = [];
        while ($current <= $last) {
            $day = $current->format('Y-m-d');
            $isWeekday = (int)$current->format('N') <= 5;
            if ($isWeekday && $this->getScheduledMinutesForDate($employeeId, $day) > 0 && !$this->isHoliday($region, $day)) {
                $year = (int)$current->format('Y');
                $fraction = $portion === 'FULL' ? 1.0 : 0.5;
                $bucket = $day <= sprintf('%04d-03-31', $year) ? 'early_days' : 'late_days';
                $byYear[$year][$bucket] = ($byYear[$year][$bucket] ?? 0.0) + $fraction;
                $byYear[$year]['total_days'] = ($byYear[$year]['total_days'] ?? 0.0) + $fraction;
                $byYear[$year]['early_days'] = $byYear[$year]['early_days'] ?? 0.0;
                $byYear[$year]['late_days'] = $byYear[$year]['late_days'] ?? 0.0;
            }
            $current = $current->modify('+1 day');
        }
        return $byYear;
    }

    private function calculateVacationUsageBreakdown(
        int $employeeId,
        int $year,
        string $region,
        int|array $excludeAbsenceIds = []
    ): array {
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $sql = "SELECT * FROM absence
                WHERE employee_id=? AND type='VACATION' AND start_date<=? AND end_date>=?";
        $params = [$employeeId, $yearEnd, $yearStart];
        $excludeAbsenceIds = is_array($excludeAbsenceIds) ? $excludeAbsenceIds : [$excludeAbsenceIds];
        $excludeAbsenceIds = array_values(array_unique(array_filter(
            array_map('intval', $excludeAbsenceIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($excludeAbsenceIds !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeAbsenceIds), '?')) . ')';
            array_push($params, ...$excludeAbsenceIds);
        }
        $sql .= ' ORDER BY start_date, id';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        $fractions = [];
        foreach ($st->fetchAll() as $absence) {
            $start = max((string)$absence['start_date'], $yearStart);
            $end = min((string)$absence['end_date'], $yearEnd);
            $current = new DateTimeImmutable($start . ' 00:00:00', new DateTimeZone('UTC'));
            $last = new DateTimeImmutable($end . ' 00:00:00', new DateTimeZone('UTC'));
            while ($current <= $last) {
                $day = $current->format('Y-m-d');
                $isWeekday = (int)$current->format('N') <= 5;
                if ($isWeekday && $this->getScheduledMinutesForDate($employeeId, $day) > 0 && !$this->isHoliday($region, $day)) {
                    $portion = (string)($absence['portion'] ?? 'FULL');
                    $fraction = $portion === 'FULL' ? 1.0 : 0.5;
                    $fractions[$day] = max($fractions[$day] ?? 0.0, $fraction);
                }
                $current = $current->modify('+1 day');
            }
        }

        $early = 0.0;
        $late = 0.0;
        $cutoff = sprintf('%04d-03-31', $year);
        foreach ($fractions as $day => $fraction) {
            if ($day <= $cutoff) {
                $early += $fraction;
            } else {
                $late += $fraction;
            }
        }
        return [
            'early_days' => $early,
            'late_days' => $late,
            'total_days' => $early + $late,
        ];
    }

    private function findAbsence(int $employeeId, string $day): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM absence WHERE employee_id=? AND start_date<=? AND end_date>=? ORDER BY id DESC LIMIT 1');
        $st->execute([$employeeId, $day, $day]);
        return $st->fetch() ?: null;
    }

    private function isHoliday(string $region, string $day): bool
    {
        $region = trim($region) !== '' ? trim($region) : (string)cfg('default_holiday_region', 'DE-BY-KF');
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
        $rule = $this->getWorkRule((int)$started->format('N'));
        [$hour, $minute] = array_map('intval', explode(':', (string)$rule['forgotten_end']));
        $end = $started->setTime($hour, $minute, 0);
        if ($end < $started) {
            $end = $started;
        }
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
