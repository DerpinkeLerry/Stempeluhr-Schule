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
                (float)($_POST['weekly_hours'] ?? 38),
                isset($_POST['is_trainee']),
                isset($_POST['special_time']),
                (float)($_POST['vacation_entitlement'] ?? cfg('default_vacation_entitlement', 30))
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
                isset($_POST['weekly_hours']) ? (float)$_POST['weekly_hours'] : null,
                isset($_POST['is_trainee']),
                isset($_POST['special_time']),
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

    public function apiScheduleUpdate(): never
    {
        require_post();
        verify_csrf();
        require_admin(true);

        try {
            $hours = [];
            for ($weekday = 1; $weekday <= 7; $weekday++) {
                $hours[$weekday] = (float)($_POST['day_' . $weekday] ?? 0);
            }
            $this->service->updateSchedule(
                (int)($_POST['employeeId'] ?? 0),
                (string)($_POST['effective_from'] ?? ''),
                $hours
            );
            json_response(['ok' => true]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Arbeitszeitmodell konnte nicht gespeichert werden'], 500);
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

    public function weekReportPdf(): never
    {
        require_post();
        verify_csrf();
        require_admin();

        try {
            $ids = $_POST['employee_ids'] ?? [];
            $report = $this->service->buildWeekReport(is_array($ids) ? $ids : []);
            $pdf = new SimplePdf();
            $total = count($report['employees']);
            foreach ($report['employees'] as $index => $employeeReport) {
                $this->drawWeekReport($pdf, $report, $employeeReport, $index + 1, $total);
            }
            $file = sprintf('Arbeitszeiten_KW_%02d_%d.pdf', $report['week']['week'], $report['week']['year']);
            $data = $pdf->build();
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

    private function drawWeekReport(SimplePdf $pdf, array $report, array $employeeReport, int $page, int $total): void
    {
        $pdf->newPage();
        $week = $report['week'];
        $employee = $employeeReport['employee'];

        $pdf->text(35, 48, 18, 'Arbeitszeitnachweis', true);
        $pdf->text(35, 76, 11, 'Mitarbeiter: ' . $employee['name'], true);
        $meta = [];
        if (!empty($employee['personnel_number'])) $meta[] = 'Personalnr.: ' . $employee['personnel_number'];
        if (!empty($employee['department'])) $meta[] = 'Abteilung: ' . $employee['department'];
        $pdf->text(35, 94, 9, implode(' | ', $meta));
        $pdf->text(35, 110, 10, 'Kalenderwoche: KW ' . sprintf('%02d', $week['week']) . ' / ' . $week['year']);
        $pdf->text(35, 127, 10, 'Zeitraum: ' . $week['start_label'] . ' bis ' . $week['end_label']);
        $pdf->text(405, 96, 8, 'Erstellt: ' . $report['created_at']);

        $left = 35.0;
        $top = 158.0;
        $rowHeight = 34.0;
        $widths = [42.0, 62.0, 55.0, 55.0, 58.0, 72.0, 181.0];
        $headers = ['Tag', 'Datum', 'Beginn', 'Ende', 'Pause', 'Arbeitszeit', 'Bemerkung'];
        $tableWidth = array_sum($widths);
        $tableHeight = $rowHeight * 8;

        $pdf->rect($left, $top, $tableWidth, $rowHeight, true, 0.90);

        foreach ($employeeReport['days'] as $rowIndex => $day) {
            $color = match ($day['absence_type'] ?? null) {
                'SICK' => [1.00, 0.96, 0.72],
                'VACATION' => [0.82, 0.91, 1.00],
                'SCHOOL', 'OTHER' => [0.86, 0.95, 0.86],
                default => null,
            };
            if ($color !== null) {
                $rowTop = $top + $rowHeight * ($rowIndex + 1);
                $pdf->rectColor($left, $rowTop, $tableWidth, $rowHeight, $color[0], $color[1], $color[2]);
            }
        }

        $pdf->rect($left, $top, $tableWidth, $tableHeight);
        for ($i = 1; $i < 8; $i++) {
            $pdf->line($left, $top + $rowHeight * $i, $left + $tableWidth, $top + $rowHeight * $i);
        }

        $x = $left;
        foreach ($widths as $width) {
            $pdf->line($x, $top, $x, $top + $tableHeight);
            $x += $width;
        }
        $pdf->line($left + $tableWidth, $top, $left + $tableWidth, $top + $tableHeight);

        $x = $left;
        foreach ($headers as $i => $header) {
            $pdf->text($x + 4, $top + 21, 8.5, $header, true);
            $x += $widths[$i];
        }

        foreach ($employeeReport['days'] as $rowIndex => $day) {
            $values = [
                $day['day'],
                $day['date'],
                $day['start'],
                $day['end'],
                $this->pdfDuration((int)$day['break_seconds']),
                $this->pdfDuration((int)$day['work_seconds']),
                $this->shortPdfText((string)$day['note'], 34),
            ];
            $x = $left;
            $textTop = $top + $rowHeight * ($rowIndex + 1) + 21;
            foreach ($values as $i => $value) {
                $pdf->text($x + 4, $textTop, 8.5, $value === '' ? '-' : $value);
                $x += $widths[$i];
            }
        }

        $summaryTop = $top + $tableHeight + 28;
        $pdf->rect(300, $summaryTop, 260, 82, true, 0.94);
        $pdf->rect(300, $summaryTop, 260, 82);
        $pdf->text(312, $summaryTop + 20, 9, 'Sollzeit:', true);
        $pdf->text(495, $summaryTop + 20, 9, $this->pdfDuration((int)$employeeReport['planned_seconds']), true);
        $pdf->text(312, $summaryTop + 40, 9, 'Arbeitszeit:', true);
        $pdf->text(495, $summaryTop + 40, 9, $this->pdfDuration((int)$employeeReport['work_seconds']), true);
        $pdf->text(312, $summaryTop + 60, 9, 'Differenz:', true);
        $pdf->text(495, $summaryTop + 60, 9, $this->pdfSignedDuration((int)$employeeReport['difference_seconds']), true);
        $pdf->text(312, $summaryTop + 78, 8, 'Pausen: ' . $this->pdfDuration((int)$employeeReport['break_seconds']));

        $pdf->text(35, 565, 10, 'Die oben aufgeführten Arbeitszeiten wurden geprüft.');
        $pdf->line(120, 675, 560, 675);
        $pdf->text(120, 691, 8.5, 'Unterschrift Arbeitnehmer');
        $pdf->text(35, 810, 8, 'Stempeluhr - Wochenzettel');
        $pdf->text(495, 810, 8, 'Seite ' . $page . ' von ' . $total);
    }

    private function pdfDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private function pdfSignedDuration(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        return $sign . $this->pdfDuration(abs($seconds));
    }

    private function shortPdfText(string $text, int $length): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3) . '...' : $text;
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($chars) && count($chars) > $length) {
            return implode('', array_slice($chars, 0, $length - 3)) . '...';
        }
        return $text;
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
