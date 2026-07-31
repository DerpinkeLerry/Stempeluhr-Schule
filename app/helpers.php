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
