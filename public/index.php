<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/TimeClockService.php';
require_once __DIR__ . '/../app/SimplePdf.php';
require_once __DIR__ . '/../app/Controller.php';

start_session();

try {
    $controller = new Controller();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>Die Datenbank konnte nicht gestartet werden.</h2>';
    echo '<p>Prüfe, ob PDO_SQLite in PHP aktiviert ist und der Ordner data beschreibbar ist.</p>';
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = rawurldecode($requestPath);
$base = base_path();
if ($base !== '' && str_starts_with($requestPath, $base)) {
    $requestPath = substr($requestPath, strlen($base));
}
$path = '/' . trim($requestPath, '/');
if ($path === '/index.php' || $path === '//') {
    $path = '/';
}

switch ($path) {
    case '/':
        $controller->pageDashboard();
        break;
    case '/login':
        $controller->pageLogin();
        break;
    case '/logout':
        $controller->logout();
        break;
    case '/me':
        $controller->pageMe();
        break;
    case '/employee':
        $controller->pageEmployee();
        break;
    case '/holidays':
        $controller->pageHolidays();
        break;
    case '/vacation-calendar':
        $controller->pageVacationCalendar();
        break;
    case '/api/status':
        $controller->apiStatus();
        break;
    case '/api/action':
        $controller->apiAction();
        break;
    case '/api/employee/create':
        $controller->apiEmployeeCreate();
        break;
    case '/api/employee/update':
        $controller->apiEmployeeUpdate();
        break;
    case '/api/employee/delete':
        $controller->apiEmployeeDelete();
        break;
    case '/api/schedule/update':
        $controller->apiScheduleUpdate();
        break;
    case '/api/vacation/update':
        $controller->apiVacationUpdate();
        break;
    case '/api/vacation-request/create':
        $controller->apiVacationRequestCreate();
        break;
    case '/api/vacation-request/decision':
        $controller->apiVacationRequestDecision();
        break;
    case '/api/absence/create':
        $controller->apiAbsenceCreate();
        break;
    case '/api/absence/update':
        $controller->apiAbsenceUpdate();
        break;
    case '/api/absence/delete':
        $controller->apiAbsenceDelete();
        break;
    case '/reports/week.pdf':
        $controller->weekReportPdf();
        break;
    default:
        http_response_code(404);
        echo '404 - Seite nicht gefunden';
}
