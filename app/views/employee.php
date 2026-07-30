<?php
declare(strict_types=1);
$typeLabels = ['VACATION' => 'Urlaub', 'SICK' => 'Krank', 'SCHOOL' => 'Schule', 'OTHER' => 'Sonstiges'];
$typeClasses = ['VACATION' => 'absence-vacation', 'SICK' => 'absence-sick', 'SCHOOL' => 'absence-school', 'OTHER' => 'absence-other'];
$parts = preg_split('/\s+/', trim((string)$employee['name'])) ?: [];
$initials = '';
foreach ($parts as $part) {
    if ($part === '') continue;
    $initials .= strtoupper(substr($part, 0, 1));
    if (strlen($initials) >= 2) break;
}
?>
<div id="employeeRoot" data-employee-id="<?= (int)$employee['id'] ?>">
    <a class="back-link" href="<?= h(url('/')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        Zur Mitarbeiterübersicht
    </a>

    <section class="employee-profile-hero">
        <div class="profile-decoration" aria-hidden="true"></div>
        <div class="employee-profile-main">
            <span class="employee-avatar employee-avatar-xl" aria-hidden="true"><?= h($initials ?: 'M') ?></span>
            <div>
                <div class="eyebrow eyebrow-light">Mitarbeiterprofil</div>
                <h1><?= h($employee['name']) ?></h1>
                <div class="profile-meta">
                    <span><?= h($employee['email']) ?></span>
                    <span><?= $employee['role'] === 'admin' ? 'Administration' : 'Mitarbeiter' ?></span>
                    <span><?= h($employee['timezone']) ?></span>
                </div>
            </div>
        </div>
        <div class="profile-live-summary">
            <div id="employeeStatus"><?= $this->renderStatusBadge($status, true) ?></div>
            <div class="profile-time"><strong id="employeeToday"><?= h(seconds_to_hhmmss($totals['net_seconds'])) ?></strong><span>Arbeitszeit heute</span></div>
        </div>
    </section>

    <div class="row g-4 align-items-start">
        <div class="col-xl-7">
            <section class="surface-card sessions-card">
                <div class="section-heading table-heading">
                    <div>
                        <div class="eyebrow">Zeithistorie</div>
                        <h2>Letzte Arbeitszeiten</h2>
                        <p>Die letzten <?= count($sessions) ?> erfassten Arbeitstage im Überblick.</p>
                    </div>
                    <span class="section-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table sessions-table mb-0 align-middle">
                        <thead><tr><th>Beginn</th><th>Ende</th><th>Pause</th><th>Nettozeit</th></tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <tr>
                                <td><span class="session-date"><?= h(utc_to_local($session['started_at'], $employee['timezone'])) ?></span></td>
                                <td><?= h(utc_to_local($session['ended_at'], $employee['timezone'])) ?></td>
                                <td><span class="muted-time"><?= h(seconds_to_hhmmss((int)$session['break_seconds'])) ?></span></td>
                                <td><strong class="time-value"><?= h(seconds_to_hhmmss((int)$session['net_seconds'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$sessions): ?>
                            <tr><td colspan="4" class="empty-state"><span>Noch keine Arbeitszeiten erfasst.</span></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-xl-5">
            <section class="surface-card absence-create-card mb-4">
                <div class="section-heading compact-heading">
                    <div>
                        <div class="eyebrow">Planung</div>
                        <h2>Abwesenheit eintragen</h2>
                    </div>
                    <span class="section-icon section-icon-accent">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg>
                    </span>
                </div>
                <form id="formAbsence" data-employee-id="<?= (int)$employee['id'] ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="absenceType">Art</label>
                            <select class="form-select" id="absenceType" name="type">
                                <option value="VACATION">Urlaub</option>
                                <option value="SICK">Krank</option>
                                <option value="SCHOOL">Schule</option>
                                <option value="OTHER">Sonstiges</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="absenceStart">Von</label>
                            <input class="form-control" id="absenceStart" type="date" name="start_date" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="absenceEnd">Bis</label>
                            <input class="form-control" id="absenceEnd" type="date" name="end_date" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="absenceNote">Notiz</label>
                            <input class="form-control" id="absenceNote" name="note" maxlength="200" placeholder="Optionaler Hinweis">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-brand w-100" type="submit">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                                Abwesenheit eintragen
                            </button>
                        </div>
                    </div>
                </form>
            </section>

            <section class="surface-card absence-list-card">
                <div class="section-heading compact-heading">
                    <div>
                        <div class="eyebrow">Übersicht</div>
                        <h2>Abwesenheiten</h2>
                    </div>
                    <span class="count-badge"><?= count($absences) ?></span>
                </div>
                <div class="absence-list">
                    <?php foreach ($absences as $absence): ?>
                        <article class="absence-item <?= h($typeClasses[$absence['type']] ?? 'absence-other') ?>">
                            <span class="absence-marker" aria-hidden="true"></span>
                            <div class="absence-content">
                                <div class="absence-title-row">
                                    <strong><?= h($typeLabels[$absence['type']] ?? $absence['type']) ?></strong>
                                    <span><?= h(date('d.m.Y', strtotime($absence['start_date']))) ?> – <?= h(date('d.m.Y', strtotime($absence['end_date']))) ?></span>
                                </div>
                                <?php if ($absence['note'] !== ''): ?><p><?= h($absence['note']) ?></p><?php endif; ?>
                            </div>
                            <div class="absence-actions">
                                <button
                                    type="button"
                                    class="icon-button absence-edit"
                                    data-bs-toggle="modal"
                                    data-bs-target="#absenceEditModal"
                                    data-absence-id="<?= (int)$absence['id'] ?>"
                                    data-type="<?= h($absence['type']) ?>"
                                    data-start-date="<?= h($absence['start_date']) ?>"
                                    data-end-date="<?= h($absence['end_date']) ?>"
                                    data-note="<?= h($absence['note']) ?>"
                                    aria-label="Abwesenheit bearbeiten"
                                    title="Bearbeiten"
                                ><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 4 6 6M4 20l4-1 11-11a2 2 0 0 0-3-3L5 16l-1 4Z"/></svg></button>
                                <button type="button" class="icon-button icon-button-danger absence-delete" data-absence-id="<?= (int)$absence['id'] ?>" aria-label="Abwesenheit löschen" title="Löschen">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V3h8v3M19 6l-1 15H6L5 6M10 11v5M14 11v5"/></svg>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$absences): ?>
                        <div class="empty-panel empty-panel-small">
                            <span class="empty-panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg></span>
                            <strong>Keine Abwesenheiten</strong>
                            <p>Geplante Einträge erscheinen an dieser Stelle.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<div class="modal fade" id="absenceEditModal" tabindex="-1" aria-labelledby="absenceEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="formAbsenceEdit">
                <input type="hidden" name="absenceId">
                <div class="modal-header">
                    <div class="modal-title-group">
                        <div class="eyebrow">Eintrag ändern</div>
                        <h2 class="modal-title" id="absenceEditTitle">Abwesenheit bearbeiten</h2>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
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
                    <button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-brand">Änderungen speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>
