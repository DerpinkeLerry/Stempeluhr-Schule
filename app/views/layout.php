<?php
declare(strict_types=1);
$isLoggedIn = !empty($_SESSION['user_id']);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = base_path();
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}
$userName = trim((string)($_SESSION['name'] ?? ''));
$userInitials = '';
foreach (preg_split('/\s+/u', $userName, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $namePart) {
    $userInitials .= strtoupper(substr($namePart, 0, 1));
    if (strlen($userInitials) >= 2) break;
}
$userInitials = $userInitials !== '' ? $userInitials : 'U';
$navActive = static function (string $path) use ($requestPath): string {
    if ($path === '/') {
        return $requestPath === '/' || $requestPath === '/employee' ? ' active' : '';
    }
    return str_starts_with($requestPath, $path) ? ' active' : '';
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#152541">
    <title><?= h($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= h(url('/assets/app.css')) ?>" rel="stylesheet">
</head>
<body class="<?= $isLoggedIn ? 'app-authenticated' : 'app-login' ?>">
<?php if ($isLoggedIn): ?>
<header class="site-header">
    <nav class="navbar navbar-expand-lg wepro-navbar" aria-label="Hauptnavigation">
        <div class="container-xxl">
            <a class="wepro-brand" href="<?= h(url($isAdmin ? '/' : '/me')) ?>" aria-label="Wepro Zeiterfassung Startseite">
                <span class="wepro-wordmark" aria-hidden="true"><span>we</span><strong>pro</strong></span>
                <span class="wepro-brand-separator" aria-hidden="true"></span>
                <span class="wepro-product-name">Zeiterfassung</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Navigation öffnen">
                <span></span><span></span><span></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNavigation">
                <div class="navbar-nav mx-lg-auto">
                    <a class="nav-link<?= $navActive('/me') ?>" href="<?= h(url('/me')) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        Meine Zeit
                    </a>
                    <?php if ($isAdmin): ?>
                        <a class="nav-link<?= $navActive('/') ?>" href="<?= h(url('/')) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            Mitarbeiter
                        </a>
                    <?php endif; ?>
                    <a class="nav-link<?= $navActive('/holidays') ?>" href="<?= h(url('/holidays')) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg>
                        Feiertage
                    </a>
                    <a class="nav-link<?= $navActive('/vacation-calendar') ?>" href="<?= h(url('/vacation-calendar')) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5M4 19h16M8 16v-5M12 16V8M16 16v-3"/></svg>
                        Urlaubskalender
                        <?php if ($isAdmin && $pendingVacationRequestCount > 0): ?><span class="nav-count-badge"><?= min(99, (int)$pendingVacationRequestCount) ?></span><?php endif; ?>
                    </a>
                </div>

                <div class="navbar-user">
                    <div class="user-avatar" aria-hidden="true"><?= h($userInitials) ?></div>
                    <div class="user-meta">
                        <strong><?= h($userName) ?></strong>
                        <span><?= $isAdmin ? 'Administration' : 'Mitarbeiter' ?></span>
                    </div>
                    <form method="post" action="<?= h(url('/logout')) ?>">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <button class="logout-button" type="submit" aria-label="Abmelden" title="Abmelden">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>
</header>
<?php endif; ?>

<main class="<?= $isLoggedIn ? 'app-main' : 'login-main' ?>">
    <div class="<?= $isLoggedIn ? 'container-xxl' : 'container-fluid p-0' ?>">
        <?php if ($isLoggedIn): ?>
            <div class="flash-stack" aria-live="polite">
                <?php foreach ($flash as $message): ?>
                    <div class="alert alert-<?= h($message['type']) ?> alert-dismissible fade show app-flash-notification" role="alert" data-notification-delay="7000">
                        <span class="alert-mark" aria-hidden="true"></span>
                        <span class="flash-message"><?= h($message['text']) ?></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
                        <span class="notification-progress" aria-hidden="true"><span></span></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="page-enter">
            <?= $content ?>
        </div>
    </div>
</main>

<?php if ($isLoggedIn): ?>
<footer class="site-footer">
    <div class="container-xxl">
        <span>Wepro GmbH · Kaufbeuren</span>
        <span class="footer-claim">Wir helfen Helfern.</span>
    </div>
</footer>
<?php endif; ?>

<div class="toast-container position-fixed top-0 end-0 p-3" id="appToastContainer" aria-live="polite" aria-atomic="true"></div>

<script>
window.STEMPELUHR = {
    basePath: <?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h(url('/assets/app.js')) ?>"></script>
</body>
</html>
