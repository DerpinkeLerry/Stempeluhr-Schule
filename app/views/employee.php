<?php
declare(strict_types=1);
$typeLabels = ['VACATION' => 'Urlaub', 'SICK' => 'Krank', 'SCHOOL' => 'Schule', 'OTHER' => 'Sonstiges'];
?>
<div id="employeeRoot" data-employee-id="<?= (int)$employee['id'] ?>">
<div class="mb-3"><a href="<?= h(url('/')) ?>">← Zur Übersicht</a></div>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="h3 mb-1"><?= h($employee['name']) ?></h1>
        <div class="text-secondary"><?= h($employee['email']) ?> · <?= h($employee['role']) ?></div>
    </div>
    <div class="text-end">
        <div id="employeeStatus"><?= $this->renderStatusBadge($status, true) ?></div>
        <div class="mt-2 fw-semibold"><span id="employeeToday"><?= h(seconds_to_hhmmss($totals['net_seconds'])) ?></span> heute</div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body p-0">
                <h2 class="h5 p-3 mb-0">Letzte Arbeitszeiten</h2>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>Start</th><th>Ende</th><th>Pause</th><th>Arbeitszeit</th></tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <tr>
                                <td><?= h(utc_to_local($session['started_at'], $employee['timezone'])) ?></td>
                                <td><?= h(utc_to_local($session['ended_at'], $employee['timezone'])) ?></td>
                                <td><?= h(seconds_to_hhmmss((int)$session['break_seconds'])) ?></td>
                                <td><?= h(seconds_to_hhmmss((int)$session['net_seconds'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$sessions): ?>
                            <tr><td colspan="4" class="text-center py-4 text-secondary">Noch keine Arbeitszeiten.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Abwesenheit eintragen</h2>
                <form id="formAbsence" data-employee-id="<?= (int)$employee['id'] ?>">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Art</label>
                            <select class="form-select" name="type">
                                <option value="VACATION">Urlaub</option>
                                <option value="SICK">Krank</option>
                                <option value="SCHOOL">Schule</option>
                                <option value="OTHER">Sonstiges</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Von</label>
                            <input class="form-control" type="date" name="start_date" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Bis</label>
                            <input class="form-control" type="date" name="end_date" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notiz</label>
                            <input class="form-control" name="note" maxlength="200">
                        </div>
                        <div class="col-12 mt-3"><button class="btn btn-primary w-100">Eintragen</button></div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <h2 class="h5 p-3 mb-0">Abwesenheiten</h2>
                <div class="list-group list-group-flush">
                    <?php foreach ($absences as $absence): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <strong><?= h($typeLabels[$absence['type']] ?? $absence['type']) ?></strong>
                                    <div class="small text-secondary"><?= h(date('d.m.Y', strtotime($absence['start_date']))) ?> bis <?= h(date('d.m.Y', strtotime($absence['end_date']))) ?></div>
                                    <?php if ($absence['note'] !== ''): ?><div class="small mt-1"><?= h($absence['note']) ?></div><?php endif; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary absence-edit"
                                        data-bs-toggle="modal"
                                        data-bs-target="#absenceEditModal"
                                        data-absence-id="<?= (int)$absence['id'] ?>"
                                        data-type="<?= h($absence['type']) ?>"
                                        data-start-date="<?= h($absence['start_date']) ?>"
                                        data-end-date="<?= h($absence['end_date']) ?>"
                                        data-note="<?= h($absence['note']) ?>"
                                    >Bearbeiten</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger absence-delete" data-absence-id="<?= (int)$absence['id'] ?>">Löschen</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$absences): ?><div class="p-3 text-secondary">Keine Einträge vorhanden.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="absenceEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formAbsenceEdit">
                <input type="hidden" name="absenceId">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Abwesenheit bearbeiten</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Art</label>
                            <select class="form-select" name="type">
                                <option value="VACATION">Urlaub</option>
                                <option value="SICK">Krank</option>
                                <option value="SCHOOL">Schule</option>
                                <option value="OTHER">Sonstiges</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Von</label>
                            <input class="form-control" type="date" name="start_date" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Bis</label>
                            <input class="form-control" type="date" name="end_date" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notiz</label>
                            <input class="form-control" name="note" maxlength="200">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>
