<?php
declare(strict_types=1);
?>
<div class="d-flex justify-content-between align-items-center mb-4 gap-3 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Mitarbeiter</h1>
        <div class="text-secondary">Aktueller Stand der Stempeluhr</div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#weekReportModal">Wochenzettel PDF</button>
        <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#newEmployee">Mitarbeiter anlegen</button>
    </div>
</div>

<div class="collapse mb-4" id="newEmployee">
    <div class="card">
        <div class="card-body">
            <form id="formEmployee" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="name" required maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label">E-Mail</label>
                    <input class="form-control" name="email" type="email" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Passwort</label>
                    <input class="form-control" name="password" type="password" minlength="6" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rolle</label>
                    <select class="form-select" name="role">
                        <option value="employee">Mitarbeiter</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Zeitzone</label>
                    <select class="form-select" name="timezone" required>
                        <?php foreach ($timezoneOptions as $group => $options): ?>
                            <optgroup label="<?= h($group) ?>">
                                <?php foreach ($options as $option): ?>
                                    <option value="<?= h($option['value']) ?>" <?= $option['value'] === 'Europe/Berlin' ? 'selected' : '' ?>><?= h($option['label']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Standort</label>
                    <input class="form-control" value="Kaufbeuren, Bayern" disabled>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-success w-100">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="employeeTable">
            <thead>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <th>Heute</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $employee): ?>
                <?php $id = (int)$employee['id']; ?>
                <tr data-employee-id="<?= $id ?>">
                    <td>
                        <div class="fw-semibold"><?= h($employee['name']) ?></div>
                        <div class="small text-secondary"><?= h($employee['email']) ?></div>
                    </td>
                    <td class="status-cell"><?= $this->renderStatusBadge($statuses[$id]) ?></td>
                    <td class="today-cell"><?= h(seconds_to_hhmmss($totals[$id]['net_seconds'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(url('/employee?id=' . $id)) ?>">Details</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$employees): ?>
                <tr><td colspan="4" class="text-center py-5 text-secondary">Keine Mitarbeiter vorhanden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="small text-secondary mt-2">Die Zeiten laufen jede Sekunde weiter.</div>

<div class="modal fade" id="weekReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= h(url('/reports/week.pdf')) ?>" target="_blank" id="weekReportForm">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div class="modal-header week-report-header">
                <div class="me-auto">
                    <h2 class="modal-title fs-5">Wochenzettel drucken</h2>
                    <div class="small text-secondary">KW <?= h(sprintf('%02d', $week['week'])) ?> · <?= h($week['start_label']) ?> bis <?= h($week['end_label']) ?></div>
                </div>
                <button type="submit" class="btn btn-primary week-report-print">PDF öffnen</button>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <div class="form-check report-check mb-3">
                    <input class="form-check-input" type="checkbox" id="selectAllEmployees" checked>
                    <label class="form-check-label fw-semibold" for="selectAllEmployees">Alle auswählen</label>
                </div>
                <div class="report-employee-list">
                    <?php foreach ($employees as $employee): ?>
                        <?php if (!(int)$employee['active']) continue; ?>
                        <label class="report-employee-item">
                            <input class="form-check-input report-employee-checkbox" type="checkbox" name="employee_ids[]" value="<?= (int)$employee['id'] ?>" checked>
                            <span>
                                <strong><?= h($employee['name']) ?></strong>
                                <small><?= h($employee['email']) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="small text-secondary mt-3">Die Liste kann vollständig gescrollt werden. Für jeden ausgewählten Mitarbeiter wird eine eigene PDF-Seite mit Unterschriftsfeld erstellt.</div>
            </div>
        </form>
    </div>
</div>
