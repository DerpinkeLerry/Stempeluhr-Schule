<?php
declare(strict_types=1);
$isLoggedIn = !empty($_SESSION['user_id']);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= h(url('/assets/app.css')) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="<?= h(url('/')) ?>">Stempeluhr</a>
        <?php if ($isLoggedIn): ?>
            <div class="navbar-nav me-auto">
                <a class="nav-link" href="<?= h(url('/me')) ?>">Meine Zeit</a>
                <?php if ($isAdmin): ?>
                    <a class="nav-link" href="<?= h(url('/')) ?>">Mitarbeiter</a>
                <?php endif; ?>
                <a class="nav-link" href="<?= h(url('/holidays')) ?>">Feiertage</a>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="small text-light"><?= h($_SESSION['name'] ?? '') ?></span>
                <form method="post" action="<?= h(url('/logout')) ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <button class="btn btn-sm btn-outline-light">Logout</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</nav>

<main class="container py-4">
    <?php foreach ($flash as $message): ?>
        <div class="alert alert-<?= h($message['type']) ?>"><?= h($message['text']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>

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
