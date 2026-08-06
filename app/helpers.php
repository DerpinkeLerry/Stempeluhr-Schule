<?php
declare(strict_types=1);

function cfg(string $key, mixed $default = null): mixed
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config[$key] ?? $default;
}

function base_path(): string
{
    static $path;
    if ($path !== null) {
        return $path;
    }

    if (PHP_SAPI === 'cli-server') {
        $path = '';
        return $path;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $lastSlash = strrpos($script, '/');

    if ($lastSlash === false || $lastSlash === 0) {
        $path = '';
        return $path;
    }

    $path = rtrim(substr($script, 0, $lastSlash), '/');
    return $path;
}

function url(string $path): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name((string)cfg('session_name', 'stempeluhr'));
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => base_path() === '' ? '/' : base_path() . '/',
    ]);
    session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
}

function csrf_token(): string
{
    start_session();
    return (string)$_SESSION['csrf'];
}

function verify_csrf(): void
{
    start_session();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($token) || !hash_equals((string)$_SESSION['csrf'], $token)) {
        json_response(['ok' => false, 'error' => 'Ungültige Anfrage'], 403);
    }
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST erforderlich'], 405);
    }
}

function require_auth(bool $api = false): int
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        if ($api) {
            json_response(['ok' => false, 'error' => 'Nicht eingeloggt'], 401);
        }
        header('Location: ' . url('/login'));
        exit;
    }
    return (int)$_SESSION['user_id'];
}

function require_admin(bool $api = false): int
{
    $id = require_auth($api);
    if (($_SESSION['role'] ?? '') !== 'admin') {
        if ($api) {
            json_response(['ok' => false, 'error' => 'Keine Berechtigung'], 403);
        }
        http_response_code(403);
        exit('Keine Berechtigung');
    }
    return $id;
}

function flash(string $text, string $type = 'success'): void
{
    start_session();
    $_SESSION['flash'][] = ['text' => $text, 'type' => $type];
}

function seconds_to_hhmmss(int $seconds): string
{
    $seconds = max(0, $seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function signed_seconds_to_hhmmss(int $seconds): string
{
    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);
    return $sign . sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function utc_to_local(?string $utc, string $timezone, string $format = 'd.m.Y H:i'): string
{
    if (!$utc) {
        return 'läuft';
    }
    $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone($timezone))->format($format);
}

/**
 * Splits a date range into contiguous Monday-to-Friday segments for the
 * vacation planning board. Dates contained in $excludedDates (for example
 * public holidays) are left blank as well.
 *
 * @param array<string, mixed> $excludedDates Date map keyed by YYYY-MM-DD.
 * @return array<int, array{start_date:string,end_date:string,start_day:int,end_day:int,span:int}>
 */
function vacation_calendar_workday_segments(string $startDate, string $endDate, array $excludedDates = []): array
{
    $timezone = new DateTimeZone('UTC');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $timezone);

    if (
        $start === false
        || $end === false
        || $start->format('Y-m-d') !== $startDate
        || $end->format('Y-m-d') !== $endDate
        || $start > $end
    ) {
        return [];
    }

    $segments = [];
    $segmentStart = null;
    $previousDate = null;

    for ($current = $start; $current <= $end; $current = $current->modify('+1 day')) {
        $date = $current->format('Y-m-d');
        $isWeekday = (int)$current->format('N') <= 5;
        $isExcluded = array_key_exists($date, $excludedDates);
        $isVisibleWorkday = $isWeekday && !$isExcluded;

        if ($isVisibleWorkday) {
            if ($segmentStart === null) {
                $segmentStart = $current;
            }
            $previousDate = $current;
            continue;
        }

        if ($segmentStart !== null && $previousDate !== null) {
            $segments[] = [
                'start_date' => $segmentStart->format('Y-m-d'),
                'end_date' => $previousDate->format('Y-m-d'),
                'start_day' => (int)$segmentStart->format('j'),
                'end_day' => (int)$previousDate->format('j'),
                'span' => ((int)$segmentStart->diff($previousDate)->format('%a')) + 1,
            ];
            $segmentStart = null;
            $previousDate = null;
        }
    }

    if ($segmentStart !== null && $previousDate !== null) {
        $segments[] = [
            'start_date' => $segmentStart->format('Y-m-d'),
            'end_date' => $previousDate->format('Y-m-d'),
            'start_day' => (int)$segmentStart->format('j'),
            'end_day' => (int)$previousDate->format('j'),
            'span' => ((int)$segmentStart->diff($previousDate)->format('%a')) + 1,
        ];
    }

    return $segments;
}

/**
 * Combines adjacent visible vacation segments of the same employee and portion
 * into one visual bar. The original segments are retained in `children` so an
 * administrator can still edit every underlying database entry separately.
 *
 * Segments are only joined when their calendar days touch directly. Weekends
 * and public holidays therefore remain visible gaps in the annual board.
 *
 * @param array<int, array<string, mixed>> $segments
 * @return array<int, array<string, mixed>>
 */
function vacation_calendar_merge_visual_segments(array $segments): array
{
    $buckets = [];

    foreach ($segments as $segment) {
        $startDay = (int)($segment['start_day'] ?? 0);
        $endDay = (int)($segment['end_day'] ?? 0);
        if ($startDay < 1 || $endDay < $startDay) {
            continue;
        }

        $employeeId = (int)($segment['employee_id'] ?? 0);
        $portion = (string)($segment['portion'] ?? 'FULL');
        $bucketKey = $employeeId . '|' . $portion;
        $buckets[$bucketKey][] = $segment;
    }

    $groups = [];

    foreach ($buckets as $bucketSegments) {
        usort($bucketSegments, static function (array $a, array $b): int {
            $startCompare = (int)$a['start_day'] <=> (int)$b['start_day'];
            if ($startCompare !== 0) {
                return $startCompare;
            }

            $endCompare = (int)$a['end_day'] <=> (int)$b['end_day'];
            if ($endCompare !== 0) {
                return $endCompare;
            }

            return (int)($a['vacation']['id'] ?? 0) <=> (int)($b['vacation']['id'] ?? 0);
        });

        $current = null;

        foreach ($bucketSegments as $segment) {
            if ($current === null) {
                $current = $segment;
                $current['children'] = [$segment];
                continue;
            }

            $touchesDirectly = (int)$segment['start_day'] === ((int)$current['end_day'] + 1);
            if ($touchesDirectly) {
                $current['end_day'] = (int)$segment['end_day'];
                $current['span'] = ((int)$current['end_day'] - (int)$current['start_day']) + 1;
                $current['visible_end_date'] = (string)($segment['visible_end_date'] ?? $segment['end_date'] ?? '');
                $current['children'][] = $segment;
                continue;
            }

            $groups[] = $current;
            $current = $segment;
            $current['children'] = [$segment];
        }

        if ($current !== null) {
            $groups[] = $current;
        }
    }

    usort($groups, static function (array $a, array $b): int {
        $startCompare = (int)$a['start_day'] <=> (int)$b['start_day'];
        if ($startCompare !== 0) {
            return $startCompare;
        }

        $endCompare = (int)$b['end_day'] <=> (int)$a['end_day'];
        if ($endCompare !== 0) {
            return $endCompare;
        }

        return strcmp((string)($a['employee']['name'] ?? ''), (string)($b['employee']['name'] ?? ''));
    });

    return array_values($groups);
}


/**
 * Places every employee on one stable lane within a calendar month.
 *
 * Only employees who actually have vacation segments in the month consume a
 * lane. Their relative order follows $employeeOrder, so the planning board
 * remains compact while names never jump above or below one another merely
 * because a vacation starts on a different day.
 *
 * @param array<int, array<string, mixed>> $segments
 * @param array<int, int> $employeeOrder Employee IDs in the desired order.
 * @return array{segments:array<int, array<string, mixed>>,track_count:int,employee_tracks:array<int, int>}
 */
function vacation_calendar_assign_employee_tracks(array $segments, array $employeeOrder): array
{
    $employeeRank = [];
    foreach (array_values($employeeOrder) as $index => $employeeId) {
        $employeeRank[(int)$employeeId] = $index;
    }

    $presentEmployees = [];
    foreach ($segments as $segment) {
        $employeeId = (int)($segment['employee_id'] ?? 0);
        if ($employeeId < 1) {
            continue;
        }

        $presentEmployees[$employeeId] = (string)($segment['employee']['name'] ?? '');
    }

    $employeeIds = array_keys($presentEmployees);
    usort($employeeIds, static function (int $leftId, int $rightId) use ($employeeRank, $presentEmployees): int {
        $leftRank = $employeeRank[$leftId] ?? PHP_INT_MAX;
        $rightRank = $employeeRank[$rightId] ?? PHP_INT_MAX;
        $rankCompare = $leftRank <=> $rightRank;
        if ($rankCompare !== 0) {
            return $rankCompare;
        }

        $nameCompare = strcasecmp($presentEmployees[$leftId] ?? '', $presentEmployees[$rightId] ?? '');
        if ($nameCompare !== 0) {
            return $nameCompare;
        }

        return $leftId <=> $rightId;
    });

    $employeeTracks = [];
    foreach ($employeeIds as $index => $employeeId) {
        $employeeTracks[$employeeId] = $index + 1;
    }

    $trackedSegments = [];
    foreach ($segments as $segment) {
        $employeeId = (int)($segment['employee_id'] ?? 0);
        if (!isset($employeeTracks[$employeeId])) {
            continue;
        }

        $segment['track'] = $employeeTracks[$employeeId];
        $trackedSegments[] = $segment;
    }

    usort($trackedSegments, static function (array $left, array $right): int {
        $trackCompare = (int)$left['track'] <=> (int)$right['track'];
        if ($trackCompare !== 0) {
            return $trackCompare;
        }

        $startCompare = (int)($left['start_day'] ?? 0) <=> (int)($right['start_day'] ?? 0);
        if ($startCompare !== 0) {
            return $startCompare;
        }

        return (int)($left['end_day'] ?? 0) <=> (int)($right['end_day'] ?? 0);
    });

    return [
        'segments' => array_values($trackedSegments),
        'track_count' => max(1, count($employeeTracks)),
        'employee_tracks' => $employeeTracks,
    ];
}
