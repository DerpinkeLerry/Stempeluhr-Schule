<?php
declare(strict_types=1);
?>
<div class="login-layout">
    <section class="login-brand-panel">
        <div class="login-brand-content">
            <a class="wepro-brand login-brand" href="<?= h(url('/login')) ?>" aria-label="Wepro Zeiterfassung">
                <span class="wepro-wordmark" aria-hidden="true"><span>we</span><strong>pro</strong></span>
                <span class="wepro-brand-separator" aria-hidden="true"></span>
                <span class="wepro-product-name">Zeiterfassung</span>
            </a>
            <div class="login-message">
                <div class="eyebrow eyebrow-light">Digitale Arbeitszeiterfassung</div>
                <h1>Arbeitszeit.<br><span>Klar erfasst.</span></h1>
                <p>Eine reduzierte, zuverlässige Lösung für den täglichen Arbeitsablauf.</p>
            </div>
            <div class="login-features">
                <div><span>01</span><p><strong>Einfach stempeln</strong>Arbeitsbeginn, Pause und Feierabend mit einem Klick.</p></div>
                <div><span>02</span><p><strong>Alles im Blick</strong>Live-Status, Arbeitszeiten und Zeitnachweise zentral verwalten.</p></div>
                <div><span>03</span><p><strong>Sicher organisiert</strong>Klare Rollen, lokale Zeitzonen und nachvollziehbare Einträge.</p></div>
            </div>
        </div>
        <div class="login-line-art" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
        <div class="login-claim">Wir helfen Helfern.</div>
    </section>

    <section class="login-form-panel">
        <div class="login-form-wrap">
            <?php foreach ($flash as $message): ?>
                <div class="alert alert-<?= h($message['type']) ?> alert-dismissible fade show" role="alert">
                    <span class="alert-mark" aria-hidden="true"></span>
                    <span><?= h($message['text']) ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
                </div>
            <?php endforeach; ?>
            <div class="login-form-heading">
                <div class="eyebrow">Willkommen zurück</div>
                <h2>Anmelden</h2>
                <p>Bitte melden Sie sich mit Ihren persönlichen Zugangsdaten an.</p>
            </div>
            <form method="post" action="<?= h(url('/login')) ?>" class="login-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <div class="form-floating-brand">
                    <label class="form-label" for="loginEmail">E-Mail-Adresse</label>
                    <div class="input-with-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v14H3zM3 6l9 7 9-7"/></svg>
                        <input class="form-control" id="loginEmail" type="email" name="email" required autofocus autocomplete="email" placeholder="name@unternehmen.de">
                    </div>
                </div>
                <div class="form-floating-brand">
                    <label class="form-label" for="loginPassword">Passwort</label>
                    <div class="input-with-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        <input class="form-control" id="loginPassword" type="password" name="password" required autocomplete="current-password" placeholder="Passwort eingeben">
                    </div>
                </div>
                <button class="btn btn-brand btn-login" type="submit">
                    Anmelden
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </form>

            <div class="demo-box">
                <div class="demo-box-title"><span>Demo-Zugänge</span><small>Nur für die Testumgebung</small></div>
                <div class="demo-credentials">
                    <div><strong>Administration</strong><code>admin@schule.local</code><code>admin123</code></div>
                    <div><strong>Mitarbeiter</strong><code>max@schule.local</code><code>max123</code></div>
                </div>
            </div>
            <div class="login-footer">Wepro GmbH · Kaufbeuren</div>
        </div>
    </section>
</div>
