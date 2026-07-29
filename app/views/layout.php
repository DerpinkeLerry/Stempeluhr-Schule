<?php
declare(strict_types=1);
$isLoggedIn = !empty($_SESSION['user_id']);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$currentPath = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$base = base_path();
if ($base !== '' && str_starts_with($currentPath, $base)) {
    $currentPath = substr($currentPath, strlen($base));
}
$isActive = static fn(string $path): string => $currentPath === $path ? ' active' : '';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#182f50">
    <title><?= h($title) ?></title>
    <link rel="icon" href="<?= h(url('/assets/wepro-favicon.svg')) ?>" type="image/svg+xml">
    <link href="<?= h(url('/assets/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= h(url('/assets/app.css')) ?>" rel="stylesheet">
</head>
<body class="<?= $isLoggedIn ? 'is-authenticated' : 'is-guest' ?>">
<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
    <div class="container app-navbar-inner">
        <a class="navbar-brand" href="<?= h(url('/')) ?>" aria-label="WEPRO Zeiterfassung Startseite">
            <img src="<?= h(url('/assets/wepro-logo.svg')) ?>" alt="WEPRO Zeiterfassung">
        </a>
        <?php if ($isLoggedIn): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNavigation" aria-controls="appNavigation" aria-expanded="false" aria-label="Navigation öffnen">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="appNavigation">
                <div class="navbar-nav me-auto app-nav-links">
                    <a class="nav-link<?= $isActive('/me') ?>" href="<?= h(url('/me')) ?>">Meine Zeit</a>
                    <?php if ($isAdmin): ?>
                        <a class="nav-link<?= $isActive('/') ?>" href="<?= h(url('/')) ?>">Mitarbeiter</a>
                    <?php endif; ?>
                    <a class="nav-link<?= $isActive('/holidays') ?>" href="<?= h(url('/holidays')) ?>">Feiertage</a>
                </div>
                <div class="user-area">
                    <div class="user-avatar" aria-hidden="true"><?= h(strtoupper(substr((string)($_SESSION['name'] ?? 'W'), 0, 1))) ?></div>
                    <div class="user-copy">
                        <span><?= h($_SESSION['name'] ?? '') ?></span>
                        <small><?= $isAdmin ? 'Administration' : 'Mitarbeiter' ?></small>
                    </div>
                    <form method="post" action="<?= h(url('/logout')) ?>">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <button class="btn btn-sm btn-outline-light logout-button">Abmelden</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</nav>

<main class="container app-shell py-4 py-lg-5">
    <?php foreach ($flash as $message): ?>
        <div class="alert alert-<?= h($message['type']) ?> app-alert" role="alert"><?= h($message['text']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>

<footer class="app-footer">
    <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <span>WEPRO GmbH · Kaufbeuren</span>
        <span>Digitale Zeiterfassung</span>
    </div>
</footer>

<script>
window.STEMPELUHR = {
    basePath: <?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= h(url('/assets/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= h(url('/assets/app.js')) ?>"></script>
</body>
</html>
