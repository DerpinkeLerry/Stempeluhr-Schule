<?php
declare(strict_types=1);
?>
<div class="row justify-content-center mt-5">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h3 text-center mb-4">Anmelden</h1>
                <form method="post" action="<?= h(url('/login')) ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">E-Mail</label>
                        <input class="form-control" type="email" name="email" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Passwort</label>
                        <input class="form-control" type="password" name="password" required>
                    </div>
                    <button class="btn btn-primary w-100">Login</button>
                </form>
            </div>
        </div>
        <div class="demo-box mt-3">
            <div><strong>Admin:</strong> admin@schule.local / admin123</div>
            <div><strong>Mitarbeiter:</strong> max@schule.local / max123</div>
        </div>
    </div>
</div>
