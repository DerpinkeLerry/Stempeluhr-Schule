<?php
declare(strict_types=1);

final class Controller
{
    private TimeClockService $service;

    public function __construct()
    {
        $this->service = new TimeClockService(db());
    }

    public function render(string $view, array $vars = [], string $title = 'Stempeluhr'): void
    {
        start_session();
        $flash = $_SESSION['flash'] ?? [];
        $_SESSION['flash'] = [];
        $pendingVacationRequestCount = 0;
        if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
            $pendingVacationRequestCount = $this->service->countPendingVacationRequests();
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/views/' . $view . '.php';
        $content = ob_get_clean();
        require __DIR__ . '/views/layout.php';
    }

    private function employeeStatusPriority(array $status): int
    {
        $code = (string)($status['status'] ?? 'UNKNOWN');

        if ($code === 'INACTIVE') {
            return 3;
        }
        if (!empty($status['stale_session'])) {
            return 2;
        }

        return match ($code) {
            'WORKING' => 0,
            'ON_BREAK' => 1,
            default => 2,
        };
    }

    private function compareEmployeeStatus(array $leftEmployee, array $leftStatus, array $rightEmployee, array $rightStatus): int
    {
        $priorityComparison = $this->employeeStatusPriority($leftStatus) <=> $this->employeeStatusPriority($rightStatus);
        if ($priorityComparison !== 0) {
            return $priorityComparison;
        }

        $nameComparison = strnatcasecmp((string)($leftEmployee['name'] ?? ''), (string)($rightEmployee['name'] ?? ''));
        if ($nameComparison !== 0) {
            return $nameComparison;
        }

        return (int)($leftEmployee['id'] ?? 0) <=> (int)($rightEmployee['id'] ?? 0);
    }

    private function postedScheduleHours(string $prefix = 'schedule_day_'): array
    {
        $hours = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $hours[$weekday] = $_POST[$prefix . $weekday] ?? 0;
        }
        return $hours;
    }

    public function renderStatusBadge(array $status, bool $large = false): string
    {
        $classes = [
            'WORKING' => 'status-working',
            'ON_BREAK' => 'status-break',
            'NOT_PRESENT' => 'status-away',
            'HOLIDAY' => 'status-holiday',
            'VACATION' => 'status-vacation',
            'SICK' => 'status-sick',
            'SCHOOL' => 'status-school',
            'OTHER' => 'status-other',
            'INACTIVE' => 'status-away',
        ];
        $code = $status['status'] ?? 'UNKNOWN';
        $class = !empty($status['stale_session']) ? 'status-stale' : ($classes[$code] ?? 'status-away');
        $size = $large ? ' status-big' : '';
        return '<span class="status-badge ' . h($class . $size) . '">' . h($status['label'] ?? 'Unbekannt') . '</span>';
    }

    public function renderActionButtons(int $employeeId, array $status): string
    {
        $code = $status['status'] ?? 'UNKNOWN';
        $icons = [
            'work_start' => '<path d="m5 12 4 4L19 6"/>',
            'break_start' => '<path d="M7 8h8v8a4 4 0 0 1-4 4H9a4 4 0 0 1-4-4V8h2ZM15 10h2a3 3 0 0 1 0 6h-2M5 4h10"/>',
            'break_end' => '<path d="M12 5v14M5 12h14"/>',
            'work_end' => '<path d="M6 6l12 12M18 6 6 18"/>',
        ];
        $button = function (string $action, string $text, string $class, bool $disabled = false) use ($employeeId, $icons): string {
            $icon = $icons[$action] ?? $icons['work_start'];
            return '<button class="btn btn-lg ' . h($class) . ' tc-action" data-action="' . h($action) . '" data-employee-id="' . $employeeId . '"' . ($disabled ? ' disabled' : '') . '>'
                . '<svg viewBox="0 0 24 24" aria-hidden="true">' . $icon . '</svg><span>' . h($text) . '</span></button>';
        };

        if (in_array($code, ['NOT_PRESENT', 'HOLIDAY', 'VACATION', 'SICK', 'SCHOOL', 'OTHER'], true)) {
            if (($status['work_start_allowed'] ?? true) === false) {
                $availableAt = (string)($status['work_start_available_at'] ?? '07:30');
                return $button('work_start', 'Arbeitsbeginn ab ' . $availableAt . ' Uhr', 'btn-outline-success', true);
            }
            $text = in_array($code, ['VACATION', 'SICK', 'SCHOOL', 'OTHER'], true)
                ? 'Trotz Abwesenheit einstempeln'
                : 'Arbeitsbeginn';
            return $button('work_start', $text, 'btn-success');
        }
        if ($code === 'WORKING') {
            if (!empty($status['stale_session'])) {
                return $button('work_end', 'Vergessenen Feierabend korrigieren', 'btn-danger');
            }
            return $button('break_start', 'Pause starten', 'btn-warning') . $button('work_end', 'Feierabend', 'btn-danger');
        }
        if ($code === 'ON_BREAK') {
            return $button('break_end', 'Pause beenden', 'btn-outline-warning') . $button('work_end', 'Feierabend', 'btn-danger');
        }
        return '<div class="clock-hint">Diese Aktion ist derzeit nicht verfügbar.</div>';
    }

    public function pageDashboard(): void
    {
        require_auth();
        if (($_SESSION['role'] ?? '') !== 'admin') {
            header('Location: ' . url('/me'));
            exit;
        }

        $employees = $this->service->listEmployees();
        $statuses = [];
        $totals = [];
        foreach ($employees as $employee) {
            $id = (int)$employee['id'];
            $statuses[$id] = $this->service->getLiveStatus($id);
            $totals[$id] = $this->service->getTodayTotals($id);
        }
        usort($employees, fn(array $left, array $right): int => $this->compareEmployeeStatus(
            $left,
            $statuses[(int)$left['id']] ?? [],
            $right,
            $statuses[(int)$right['id']] ?? []
        ));
        $week = $this->service->getCurrentWeekInfo();
        $timezoneOptions = $this->service->listTimezoneOptions();
        $currentAdminId = (int)($_SESSION['user_id'] ?? 0);
        $this->render('dashboard', compact('employees', 'statuses', 'totals', 'week', 'timezoneOptions', 'currentAdminId'), 'Stempeluhr - Übersicht');
    }

    public function pageEmployee(): void
    {
        require_admin();
        $id = (int)($_GET['id'] ?? 0);
        $employee = $this->service->getEmployee($id);
        if (!$employee) {
            flash('Mitarbeiter wurde nicht gefunden', 'danger');
            header('Location: ' . url('/'));
            exit;
        }

        $status = $this->service->getLiveStatus($id);
        $totals = $this->service->getTodayTotals($id);
        $sessions = $this->service->listRecentSessions($id, 20);
        $absences = $this->service->listAbsences($id, 30);
        $timezone = new DateTimeZone((string)$employee['timezone']);
        $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $vacationYear = (int)($_GET['vacation_year'] ?? (new DateTimeImmutable('now', $timezone))->format('Y'));
        if ($vacationYear < 1970 || $vacationYear > 2200) {
            $vacationYear = (int)(new DateTimeImmutable('now', $timezone))->format('Y');
        }
        $schedule = $this->service->getSchedule($id, $today);
        $vacation = $this->service->getVacationAccount($id, $vacationYear);
        $this->render('employee', compact('employee', 'status', 'totals', 'sessions', 'absences', 'schedule', 'vacation', 'vacationYear', 'today'), 'Stempeluhr - ' . $employee['name']);
    }

    public function pageHolidays(): void
    {
        require_auth();
        $region = (string)cfg('default_holiday_region', 'DE-BY-KF');
        $year = (int)($_GET['year'] ?? date('Y'));
        if ($year < 2025 || $year > 2035) {
            $year = (int)date('Y');
        }
        $holidays = $this->service->listHolidaysForYear($region, $year);
        $this->render('holidays', compact('region', 'year', 'holidays'), 'Stempeluhr - Feiertage');
    }

    public function pageVacationCalendar(): void
    {
        $currentUserId = require_auth(true);
        $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
        $berlinNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $currentYear = (int)$berlinNow->format('Y');
        $year = (int)($_GET['year'] ?? $currentYear);
        if ($year < 1970 || $year > 2200) {
            $year = $currentYear;
        }

        // Der Urlaubsplan ist bewusst eine reine Jahresübersicht. Frühere
        // view-/month-Parameter werden ignoriert, damit es nur eine eindeutige
        // Kalenderdarstellung und stabile Links gibt.
        $periodStart = sprintf('%04d-01-01', $year);
        $periodEnd = sprintf('%04d-12-31', $year);
        $employees = $this->service->listActiveEmployeesForVacationCalendar();
        $vacations = $this->service->listVacationAbsencesForPeriod($periodStart, $periodEnd);
        $vacationsByEmployee = [];
        foreach ($vacations as $vacation) {
            $vacationsByEmployee[(int)$vacation['employee_id']][] = $vacation;
        }

        $region = (string)cfg('default_holiday_region', 'DE-BY-KF');
        $holidays = $this->service->listHolidaysForYear($region, $year);
        $holidaysByDay = [];
        foreach ($holidays as $holiday) {
            $holidaysByDay[(string)$holiday['day']] = (string)$holiday['name'];
        }

        $today = $berlinNow->format('Y-m-d');

        $vacationAccounts = [];
        if ($isAdmin) {
            foreach ($employees as $employee) {
                $vacationAccounts[(int)$employee['id']] = $this->service->getVacationAccount((int)$employee['id'], $year);
            }
            $requestStatus = strtoupper((string)($_GET['request_status'] ?? 'PENDING'));
            if (!in_array($requestStatus, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', 'ALL'], true)) {
                $requestStatus = 'PENDING';
            }
            $requestsPerPage = 30;
            $requestPage = max(1, (int)($_GET['requests_page'] ?? 1));
            $requestCount = $this->service->countVacationRequestsForAdmin($requestStatus);
            $requestPageCount = max(1, (int)ceil($requestCount / $requestsPerPage));
            $requestPage = min($requestPage, $requestPageCount);
            $requests = $this->service->listVacationRequestsForAdmin(
                $requestStatus,
                $requestsPerPage,
                ($requestPage - 1) * $requestsPerPage
            );
            $ownVacationAccount = null;
        } else {
            $requestStatus = 'ALL';
            $requestCount = 0;
            $requestPage = 1;
            $requestPageCount = 1;
            $requests = $this->service->listVacationRequestsForEmployee($currentUserId);
            $ownVacationAccount = $this->service->getVacationAccount($currentUserId, $year);
            $editableVacations = $this->service->listEditableVacationAbsencesForEmployee($currentUserId, $today);
        }
        if ($isAdmin) {
            $editableVacations = [];
        }

        $this->render('vacation_calendar', compact(
            'isAdmin',
            'currentUserId',
            'year',
            'periodStart',
            'periodEnd',
            'employees',
            'vacations',
            'vacationsByEmployee',
            'holidaysByDay',
            'today',
            'vacationAccounts',
            'ownVacationAccount',
            'editableVacations',
            'requests',
            'requestStatus',
            'requestCount',
            'requestPage',
            'requestPageCount'
        ), 'Stempeluhr - Urlaubskalender');
    }

    public function pageMe(): void
    {
        $id = require_auth();
        $employee = $this->service->getEmployee($id);
        if (!$employee) {
            $this->logoutNow();
        }
        $status = $this->service->getLiveStatus($id);
        $totals = $this->service->getTodayTotals($id);
        $breaks = $this->service->listTodayBreaks($id);
        $this->render('me', compact('employee', 'status', 'totals', 'breaks'), 'Meine Stempeluhr');
    }

    public function pageLogin(): void
    {
        start_session();
        if (!empty($_SESSION['user_id'])) {
            header('Location: ' . url('/'));
            exit;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            verify_csrf();
            $email = (string)($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $user = $this->service->getEmployeeForLogin($email);

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];
                header('Location: ' . url($user['role'] === 'admin' ? '/' : '/me'));
                exit;
            }
            flash('E-Mail oder Passwort ist falsch', 'danger');
            header('Location: ' . url('/login'));
            exit;
        }

        $this->render('login', [], 'Login');
    }

    public function logout(): never
    {
        require_post();
        verify_csrf();
        $this->logoutNow();
    }

    public function apiStatus(): never
    {
        $currentId = require_auth(true);
        $requestedId = (int)($_GET['employeeId'] ?? 0);

        if ($requestedId > 0) {
            if (($_SESSION['role'] ?? '') !== 'admin' && $requestedId !== $currentId) {
                json_response(['ok' => false, 'error' => 'Keine Berechtigung'], 403);
            }
            json_response([
                'ok' => true,
                'employeeId' => $requestedId,
                'status' => $this->service->getLiveStatus($requestedId),
                'totals' => $this->service->getTodayTotals($requestedId),
                'breaks' => $this->service->listTodayBreaks($requestedId),
            ]);
        }

        require_admin(true);
        $items = [];
        foreach ($this->service->listEmployees() as $employee) {
            $id = (int)$employee['id'];
            $items[] = [
                'employee' => $employee,
                'status' => $this->service->getLiveStatus($id),
                'totals' => $this->service->getTodayTotals($id),
            ];
        }
        usort($items, fn(array $left, array $right): int => $this->compareEmployeeStatus(
            $left['employee'],
            $left['status'],
            $right['employee'],
            $right['status']
        ));
        json_response(['ok' => true, 'items' => $items]);
    }

    public function apiEmployeeCreate(): never
    {
        require_post();
        verify_csrf();
        require_admin(true);

        try {
            $id = $this->service->createEmployee(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['email'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['role'] ?? 'employee'),
                (string)($_POST['timezone'] ?? 'Europe/Berlin'),
                (string)cfg('default_holiday_region', 'DE-BY-KF'),
                (string)($_POST['personnel_number'] ?? ''),
                (string)($_POST['department'] ?? ''),
                (string)($_POST['phone'] ?? ''),
                TimeClockService::DEFAULT_WEEKLY_HOURS,
                isset($_POST['is_trainee']),
                false,
                (float)($_POST['vacation_entitlement'] ?? cfg('default_vacation_entitlement', 30)),
                $this->postedScheduleHours()
            );
            json_response(['ok' => true, 'employeeId' => $id]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Mitarbeiter konnte nicht gespeichert werden'], 500);
        }
    }

    public function apiEmployeeUpdate(): never
    {
        require_post();
        verify_csrf();
        $actingAdminId = require_admin(true);

        try {
            $employeeId = (int)($_POST['employeeId'] ?? 0);
            $this->service->updateEmployee(
                $employeeId,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['email'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['role'] ?? 'employee'),
                (string)($_POST['timezone'] ?? 'Europe/Berlin'),
                $actingAdminId,
                (string)($_POST['personnel_number'] ?? ''),
                (string)($_POST['department'] ?? ''),
                (string)($_POST['phone'] ?? ''),
                null,
                isset($_POST['is_trainee']),
                false,
                isset($_POST['active']),
                isset($_POST['login_enabled'])
            );

            if ($employeeId === $actingAdminId) {
                $employee = $this->service->getEmployee($employeeId);
                if ($employee) {
                    $_SESSION['name'] = $employee['name'];
                }
            }

            json_response(['ok' => true]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Mitarbeiter konnte nicht aktualisiert werden'], 500);
        }
    }

    public function apiEmployeeDelete(): never
    {
        require_post();
        verify_csrf();
        $actingAdminId = require_admin(true);

        try {
            $this->service->deleteEmployee((int)($_POST['employeeId'] ?? 0), $actingAdminId);
            json_response(['ok' => true]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Mitarbeiter konnte nicht deaktiviert werden'], 500);
        }
    }

    public function apiScheduleUpdate(): never
    {
        require_post();
        verify_csrf();
        require_admin(true);

        try {
            $this->service->updateSchedule(
                (int)($_POST['employeeId'] ?? 0),
                (string)($_POST['effective_from'] ?? ''),
                $this->postedScheduleHours('day_')
            );
            json_response(['ok' => true]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Arbeitszeitmodell konnte nicht gespeichert werden'], 500);
        }
    }

    public function apiAction(): never
    {
        require_post();
        verify_csrf();
        $employeeId = require_auth(true);
        $action = (string)($_POST['action'] ?? '');

        try {
            $result = [];
            if ($action === 'work_start') {
                $result = $this->service->startWork($employeeId);
            } elseif ($action === 'work_end') {
                $result = $this->service->endWork($employeeId);
            } elseif ($action === 'break_start') {
                $result = $this->service->startBreak($employeeId);
            } elseif ($action === 'break_end') {
                $this->service->endBreak($employeeId);
            } else {
                throw new RuntimeException('Unbekannte Aktion');
            }
            json_response(['ok' => true] + $result);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Aktion konnte nicht gespeichert werden'], 500);
        }
    }

    public function apiVacationUpdate(): never
    {
        require_post();
        verify_csrf();
        require_admin(true);

        try {
            $this->service->updateVacationAccount(
                (int)($_POST['employeeId'] ?? 0),
                (int)($_POST['year'] ?? 0),
                (float)($_POST['entitlement_days'] ?? 0),
                (float)($_POST['carryover_days'] ?? 0),
                (float)($_POST['adjustment_days'] ?? 0),
                (string)($_POST['note'] ?? '')
            );
            json_response(['ok' => true]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Urlaubskonto konnte nicht gespeichert werden'], 500);
        }
    }

    public function apiVacationRequestCreate(): never
    {
        require_post();
        verify_csrf();
        $employeeId = require_auth(true);

        try {
            $requestId = $this->service->createVacationRequest(
                $employeeId,
                (string)($_POST['start_date'] ?? ''),
                (string)($_POST['end_date'] ?? ''),
                (string)($_POST['note'] ?? ''),
                (string)($_POST['portion'] ?? 'FULL')
            );
            json_response(['ok' => true, 'requestId' => $requestId]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Urlaubsantrag konnte nicht gespeichert werden'], 500);
        }
    }

    public function apiVacationRequestChangeCreate(): never
    {
        require_post();
        verify_csrf();
        $employeeId = require_auth(true);

        try {
            $requestId = $this->service->createVacationChangeRequest(
                $employeeId,
                (int)($_POST['target_absence_id'] ?? 0),
                (string)($_POST['request_type'] ?? ''),
                (string)($_POST['start_date'] ?? ''),
                (string)($_POST['end_date'] ?? ''),
                (string)($_POST['portion'] ?? 'FULL'),
                (string)($_POST['note'] ?? '')
            );
            json_response(['ok' => true, 'requestId' => $requestId]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Der Änderungsantrag konnte nicht gespeichert werden'], 500);
        }
    }

    public function apiVacationRequestDecision(): never
    {
        require_post();
        verify_csrf();
        $adminId = require_admin(true);

        try {
            $result = $this->service->decideVacationRequest(
                (int)($_POST['requestId'] ?? 0),
                $adminId,
                (string)($_POST['decision'] ?? ''),
                (string)($_POST['decision_note'] ?? '')
            );
            json_response(['ok' => true] + $result);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Urlaubsantrag konnte nicht bearbeitet werden'], 500);
        }
    }

    public function apiAbsenceCreate(): never
    {
        require_post();
        verify_csrf();
        require_admin(true);

        try {
            $id = $this->service->createAbsence(
                (int)($_POST['employeeId'] ?? 0),
                (string)($_POST['type'] ?? 'OTHER'),
                (string)($_POST['start_date'] ?? ''),
                (string)($_POST['end_date'] ?? ''),
                (string)($_POST['note'] ?? ''),
                (string)($_POST['portion'] ?? 'FULL')
            );
            json_response(['ok' => true, 'absenceId' => $id]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Abwesenheit konnte nicht gespeichert werden'], 500);
        }
    }

    public function apiAbsenceUpdate(): never
    {
        require_post();
        verify_csrf();
        require_admin(true);

        try {
            $this->service->updateAbsence(
                (int)($_POST['absenceId'] ?? 0),
                (string)($_POST['type'] ?? 'OTHER'),
                (string)($_POST['start_date'] ?? ''),
                (string)($_POST['end_date'] ?? ''),
                (string)($_POST['note'] ?? ''),
                (string)($_POST['portion'] ?? 'FULL')
            );
            json_response(['ok' => true]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Abwesenheit konnte nicht geändert werden'], 500);
        }
    }

    public function apiAbsenceDelete(): never
    {
        require_post();
        verify_csrf();
        require_admin(true);

        try {
            $this->service->deleteAbsence((int)($_POST['absenceId'] ?? 0));
            json_response(['ok' => true]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Abwesenheit konnte nicht gelöscht werden'], 500);
        }
    }

    public function timeReportPdf(): never
    {
        require_post();
        verify_csrf();
        require_admin();

        try {
            $ids = $_POST['employee_ids'] ?? [];
            $type = strtolower(trim((string)($_POST['report_type'] ?? 'week')));
            $reference = match ($type) {
                'month' => (string)($_POST['report_month'] ?? ''),
                'year' => (string)($_POST['report_year'] ?? ''),
                default => (string)($_POST['report_week'] ?? ''),
            };
            $report = $this->service->buildTimeReport(is_array($ids) ? $ids : [], $type, $reference);
            $renderer = new TimeReportPdfRenderer();
            $data = $renderer->render($report);
            $file = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$report['period']['filename']) ?: 'Arbeitszeiten.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $file . '"');
            header('Content-Length: ' . strlen($data));
            header('Cache-Control: no-store');
            echo $data;
            exit;
        } catch (RuntimeException $e) {
            http_response_code(400);
            echo h($e->getMessage());
            exit;
        } catch (Throwable) {
            http_response_code(500);
            echo 'PDF konnte nicht erstellt werden';
            exit;
        }
    }

    public function weekReportPdf(): never
    {
        $_POST['report_type'] = 'week';
        $this->timeReportPdf();
    }

    private function logoutNow(): never
    {
        start_session();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: ' . url('/login'));
        exit;
    }
}
