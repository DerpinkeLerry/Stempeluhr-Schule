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

        if ($code === 'NOT_PRESENT' || $code === 'HOLIDAY') {
            if (($status['work_start_allowed'] ?? true) === false) {
                return $button('work_start', 'Arbeitsbeginn ab 07:30 Uhr', 'btn-outline-success', true);
            }
            return $button('work_start', 'Arbeitsbeginn', 'btn-success');
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
        return '<div class="clock-hint">Heute ist eine Abwesenheit eingetragen.</div>';
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
        $week = $this->service->getCurrentWeekInfo();
        $timezoneOptions = $this->service->listTimezoneOptions();
        $this->render('dashboard', compact('employees', 'statuses', 'totals', 'week', 'timezoneOptions'), 'Stempeluhr - Übersicht');
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
        $this->render('employee', compact('employee', 'status', 'totals', 'sessions', 'absences'), 'Stempeluhr - ' . $employee['name']);
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
                (string)cfg('default_holiday_region', 'DE-BY-KF')
            );
            json_response(['ok' => true, 'employeeId' => $id]);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable) {
            json_response(['ok' => false, 'error' => 'Mitarbeiter konnte nicht gespeichert werden'], 500);
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
                (string)($_POST['note'] ?? '')
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
                (string)($_POST['note'] ?? '')
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
        $pdf->text(35, 96, 10, 'Kalenderwoche: KW ' . sprintf('%02d', $week['week']) . ' / ' . $week['year']);
        $pdf->text(35, 113, 10, 'Zeitraum: ' . $week['start_label'] . ' bis ' . $week['end_label']);
        $pdf->text(405, 96, 8, 'Erstellt: ' . $report['created_at']);

        $left = 35.0;
        $top = 145.0;
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
        $pdf->rect(330, $summaryTop, 230, 58, true, 0.94);
        $pdf->rect(330, $summaryTop, 230, 58);
        $pdf->text(342, $summaryTop + 22, 10, 'Arbeitszeit gesamt:', true);
        $pdf->text(495, $summaryTop + 22, 10, $this->pdfDuration((int)$employeeReport['work_seconds']), true);
        $pdf->text(342, $summaryTop + 43, 9, 'Pausen gesamt:');
        $pdf->text(495, $summaryTop + 43, 9, $this->pdfDuration((int)$employeeReport['break_seconds']));

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
