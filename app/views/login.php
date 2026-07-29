<?php
declare(strict_types=1);
?>
<section class="login-wrap">
    <div class="login-shell">
        <div class="login-brand-panel">
            <div class="login-brand-content">
                <div class="login-kicker">WEPRO GMBH · KAUFBEUREN</div>
                <h1>Wir helfen<br>Helfern!</h1>
                <p>Zeiten klar erfassen, Teams zuverlässig organisieren und den Arbeitsalltag einfach halten.</p>
                <div class="login-feature-list" aria-label="Vorteile">
                    <span><i></i> Übersichtliche Zeiterfassung</span>
                    <span><i></i> Sichere lokale Datenhaltung</span>
                    <span><i></i> Optimiert für Desktop und Mobil</span>
                </div>
            </div>
            <div class="login-network" aria-hidden="true">
                <span class="network-line line-one"></span>
                <span class="network-line line-two"></span>
                <span class="network-node node-one"></span>
                <span class="network-node node-two"></span>
                <span class="network-node node-three"></span>
            </div>
        </div>
        <div class="login-form-panel">
            <div class="login-mobile-brand">
                <img src="<?= h(url('/assets/wepro-logo.svg')) ?>" alt="WEPRO Zeiterfassung">
            </div>
            <div class="login-form-heading">
                <span class="eyebrow">Digitale Zeiterfassung</span>
                <h2>Willkommen zurück</h2>
                <p>Bitte melden Sie sich mit Ihren Zugangsdaten an.</p>
            </div>
            <form method="post" action="<?= h(url('/login')) ?>" class="login-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <div class="mb-3">
                    <label class="form-label" for="loginEmail">E-Mail-Adresse</label>
                    <input class="form-control form-control-lg" id="loginEmail" type="email" name="email" autocomplete="username" required autofocus placeholder="name@wepro.org">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="loginPassword">Passwort</label>
                    <input class="form-control form-control-lg" id="loginPassword" type="password" name="password" autocomplete="current-password" required placeholder="Passwort eingeben">
                </div>
                <button class="btn btn-primary btn-lg w-100">Sicher anmelden</button>
            </form>
            <div class="demo-box mt-4">
                <div class="demo-title">Demo-Zugänge</div>
                <div><strong>Admin</strong><span>admin@schule.local · admin123</span></div>
                <div><strong>Mitarbeiter</strong><span>max@schule.local · max123</span></div>
            </div>
        </div>
    </div>
</section>
