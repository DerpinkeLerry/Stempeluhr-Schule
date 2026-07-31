<?php
declare(strict_types=1);
$parts = preg_split('/\s+/', trim((string)$employee['name'])) ?: [];
$initials = '';
foreach ($parts as $part) {
    if ($part === '') continue;
    $initials .= strtoupper(substr($part, 0, 1));
    if (strlen($initials) >= 2) break;
}
?>
<div id="meRoot" data-employee-id="<?= (int)$employee['id'] ?>"<?= (($status['status'] ?? '') === 'ON_BREAK') ? ' class="on-break"' : '' ?>>
    <section class="page-hero personal-hero">
        <div class="personal-heading">
            <span class="employee-avatar employee-avatar-lg" aria-hidden="true"><?= h($initials ?: 'M') ?></span>
            <div>
                <div class="eyebrow">Persönliche Zeiterfassung</div>
                <h1>Hallo, <?= h($employee['name']) ?></h1>
                <p><?= h($employee['email']) ?></p>
            </div>
        </div>
        <div class="personal-status">
            <span class="personal-status-label">Aktueller Status</span>
            <div id="meStatus"><?= $this->renderStatusBadge($status, true) ?></div>
        </div>
    </section>

    <div class="row g-4 align-items-stretch">
        <div class="col-xl-8 col-lg-7">
            <section class="clock-panel h-100">
                <div class="clock-decoration" aria-hidden="true"><span></span><span></span><span></span></div>
                <div class="clock-panel-head">
                    <div>
                        <div class="eyebrow eyebrow-light">Heute</div>
                        <h2>Meine Arbeitszeit</h2>
                    </div>
                    <div class="live-indicator"><span></span> Live</div>
                </div>

                <div class="clock-metrics">
                    <article class="clock-metric clock-metric-primary">
                        <span class="metric-label">Arbeitszeit</span>
                        <strong id="meTotals" class="time-display"><?= h(seconds_to_hhmmss($totals['net_seconds'])) ?></strong>
                        <small>Nettozeit ohne Pausen</small>
                    </article>
                    <article class="clock-metric clock-metric-break">
                        <div class="break-metric-current">
                            <span class="metric-label">Pausenzeit</span>
                            <strong id="meBreakTotal" class="time-display time-display-secondary"><?= h(seconds_to_hhmmss($totals['break_seconds'])) ?></strong>
                            <small>Heute erfasste Pausen</small>
                        </div>
                        <div id="meBreakRest" class="break-rest" aria-live="polite" aria-hidden="<?= (($status['status'] ?? '') === 'ON_BREAK') ? 'false' : 'true' ?>">
                            <span class="break-rest-label">Restpause</span>
                            <strong id="meBreakRemaining" class="break-rest-value"><?= h(signed_seconds_to_hhmmss($totals['break_remaining_seconds'] ?? 1800)) ?></strong>
                        </div>
                    </article>
                </div>

                <div class="clock-rule"></div>
                <div id="meActions" class="action-grid"><?= $this->renderActionButtons((int)$employee['id'], $status) ?></div>
                <div class="clock-hint">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                    Arbeitsbeginn ist frühestens ab 07:30 Uhr möglich. Während einer Pause bleibt die Arbeitszeit stehen.
                </div>
            </section>
        </div>

        <div class="col-xl-4 col-lg-5">
            <section class="surface-card breaks-card h-100">
                <div class="section-heading compact-heading">
                    <div>
                        <div class="eyebrow">Tagesverlauf</div>
                        <h2>Pausen heute</h2>
                    </div>
                    <span class="section-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8h8v8a4 4 0 0 1-4 4H9a4 4 0 0 1-4-4V8h2ZM15 10h2a3 3 0 0 1 0 6h-2M5 4h10"/></svg>
                    </span>
                </div>
                <div id="meBreaks" class="break-list">
                    <?php if (!$breaks): ?>
                        <div class="empty-panel">
                            <span class="empty-panel-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                            </span>
                            <strong>Noch keine Pause</strong>
                            <p>Gestartete Pausen werden hier automatisch angezeigt.</p>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($breaks as $index => $break): ?>
                        <div class="break-row">
                            <span class="break-number"><?= (int)$index + 1 ?></span>
                            <div class="break-time">
                                <strong><?= h(utc_to_local($break['started_at'], $employee['timezone'], 'H:i')) ?> – <?= h(utc_to_local($break['ended_at'], $employee['timezone'], 'H:i')) ?></strong>
                                <small>Pause</small>
                            </div>
                            <strong class="break-duration"><?= h(seconds_to_hhmmss((int)$break['duration_seconds'])) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</div>
