<?php
declare(strict_types=1);
?>
<div id="meRoot" data-employee-id="<?= (int)$employee['id'] ?>">
    <div class="mb-4">
        <h1 class="h3 mb-1"><?= h($employee['name']) ?></h1>
        <div class="text-secondary"><?= h($employee['email']) ?></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card clock-card h-100">
                <div class="card-body p-4 text-center">
                    <div class="text-secondary mb-2">Mein Status</div>
                    <div id="meStatus" class="mb-4"><?= $this->renderStatusBadge($status, true) ?></div>
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="text-secondary">Arbeitszeit heute</div>
                            <div id="meTotals" class="time-display"><?= h(seconds_to_hhmmss($totals['net_seconds'])) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-secondary">Pausenzeit heute</div>
                            <div id="meBreakTotal" class="time-display"><?= h(seconds_to_hhmmss($totals['break_seconds'])) ?></div>
                        </div>
                    </div>
                    <div id="meActions" class="action-grid"><?= $this->renderActionButtons((int)$employee['id'], $status) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Pausen heute</h2>
                    <div id="meBreaks">
                        <?php if (!$breaks): ?>
                            <div class="text-secondary">Noch keine Pause.</div>
                        <?php endif; ?>
                        <?php foreach ($breaks as $break): ?>
                            <div class="break-row">
                                <div><?= h(utc_to_local($break['started_at'], $employee['timezone'], 'H:i')) ?> - <?= h(utc_to_local($break['ended_at'], $employee['timezone'], 'H:i')) ?></div>
                                <strong><?= h(seconds_to_hhmmss((int)$break['duration_seconds'])) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
