<?php
declare(strict_types=1);
$workingCount = 0;
$breakCount = 0;
foreach ($statuses as $itemStatus) {
    if (($itemStatus['status'] ?? '') === 'WORKING' && empty($itemStatus['stale_session'])) $workingCount++;
    if (($itemStatus['status'] ?? '') === 'ON_BREAK') $breakCount++;
}
?>
<section class="page-hero dashboard-hero">
    <div class="page-hero-copy">
        <div class="eyebrow">Administration</div>
        <h1>Mitarbeiter im Blick</h1>
        <p>Arbeitszeiten, Anwesenheiten und Wochenzettel zentral und übersichtlich verwalten.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline-brand" data-bs-toggle="modal" data-bs-target="#weekReportModal">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
            Wochenzettel
        </button>
        <button class="btn btn-brand" data-bs-toggle="collapse" data-bs-target="#newEmployee" aria-expanded="false" aria-controls="newEmployee">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Mitarbeiter anlegen
        </button>
    </div>
</section>

<div class="stat-grid" aria-label="Aktuelle Kennzahlen">
    <article class="stat-card">
        <span class="stat-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </span>
        <div><strong><?= count($employees) ?></strong><span>Mitarbeiter gesamt</span></div>
    </article>
    <article class="stat-card stat-card-positive">
        <span class="stat-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
        </span>
        <div><strong><?= $workingCount ?></strong><span>Aktuell im Dienst</span></div>
    </article>
    <article class="stat-card stat-card-accent">
        <span class="stat-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2v6M18 2v6M4 8h16M6 12h3M6 16h5M4 4h16v18H4z"/></svg>
        </span>
        <div><strong><?= $breakCount ?></strong><span>Aktuell in Pause</span></div>
    </article>
</div>

<div class="collapse form-collapse" id="newEmployee">
    <section class="surface-card employee-create-card">
        <div class="section-heading">
            <div>
                <div class="eyebrow">Neuer Zugang</div>
                <h2>Mitarbeiter anlegen</h2>
                <p>Persönliche Daten, Rolle und lokale Zeitzone festlegen.</p>
            </div>
            <button type="button" class="icon-button" data-bs-toggle="collapse" data-bs-target="#newEmployee" aria-label="Formular schließen">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <form id="formEmployee" class="row g-3">
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="employeePersonnelNumber">Personalnummer</label>
                <input class="form-control" id="employeePersonnelNumber" name="personnel_number" maxlength="50" placeholder="z. B. 00123" required>
            </div>
            <div class="col-lg-5 col-md-6">
                <label class="form-label" for="employeeName">Name</label>
                <input class="form-control" id="employeeName" name="name" required maxlength="100" autocomplete="name" placeholder="Vor- und Nachname">
            </div>
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="employeeDepartment">Abteilung</label>
                <input class="form-control" id="employeeDepartment" name="department" maxlength="100" placeholder="z. B. Produktion">
            </div>
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="employeeEmail">E-Mail</label>
                <input class="form-control" id="employeeEmail" name="email" type="email" required autocomplete="email" placeholder="name@unternehmen.de">
            </div>
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="employeePhone">Telefon</label>
                <input class="form-control" id="employeePhone" name="phone" maxlength="50" autocomplete="tel">
            </div>
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="employeePassword">Passwort</label>
                <input class="form-control" id="employeePassword" name="password" type="password" minlength="6" required autocomplete="new-password" placeholder="Mindestens 6 Zeichen">
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="employeeRole">Rolle</label>
                <select class="form-select" id="employeeRole" name="role">
                    <option value="employee">Mitarbeiter</option>
                    <option value="admin">Administration</option>
                </select>
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="employeeWeeklyHours">Wochenstunden</label>
                <input class="form-control" id="employeeWeeklyHours" name="weekly_hours" type="number" min="0" max="168" step="0.25" value="38">
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="employeeVacation">Urlaubsanspruch</label>
                <input class="form-control" id="employeeVacation" name="vacation_entitlement" type="number" min="0" max="365" step="0.5" value="<?= h((string)cfg('default_vacation_entitlement', 30)) ?>">
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="employeeTimezone">Zeitzone</label>
                <select class="form-select" id="employeeTimezone" name="timezone" required>
                    <?php foreach ($timezoneOptions as $group => $options): ?>
                        <optgroup label="<?= h($group) ?>">
                            <?php foreach ($options as $option): ?>
                                <option value="<?= h($option['value']) ?>" <?= $option['value'] === 'Europe/Berlin' ? 'selected' : '' ?>><?= h($option['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-8 d-flex flex-wrap align-items-center gap-4">
                <div class="form-check"><input class="form-check-input" id="employeeTrainee" name="is_trainee" type="checkbox" value="1"><label class="form-check-label" for="employeeTrainee">Auszubildender</label></div>
                <div class="form-check"><input class="form-check-input" id="employeeSpecialTime" name="special_time" type="checkbox" value="1"><label class="form-check-label" for="employeeSpecialTime">Sonderarbeitszeit</label></div>
            </div>
            <div class="col-lg-4 d-flex align-items-end">
                <button class="btn btn-brand w-100" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Speichern</button>
            </div>
        </form>
    </section>
</div>

<section class="surface-card employee-list-card">
    <div class="section-heading table-heading">
        <div>
            <div class="eyebrow">Live-Übersicht</div>
            <h2>Teamstatus</h2>
            <p>Die Arbeitszeiten werden automatisch jede Sekunde aktualisiert.</p>
        </div>
        <label class="search-field" for="employeeSearch">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
            <input id="employeeSearch" type="search" placeholder="Mitarbeiter suchen" autocomplete="off">
        </label>
    </div>
    <div class="table-responsive">
        <table class="table employee-table align-middle mb-0" id="employeeTable">
            <thead>
            <tr>
                <th>Mitarbeiter</th>
                <th>Status</th>
                <th>Arbeitszeit heute</th>
                <th class="text-end">Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $employee): ?>
                <?php
                $id = (int)$employee['id'];
                $parts = preg_split('/\s+/', trim((string)$employee['name'])) ?: [];
                $initials = '';
                foreach ($parts as $part) {
                    if ($part === '') continue;
                    $initials .= strtoupper(substr($part, 0, 1));
                    if (strlen($initials) >= 2) break;
                }
                ?>
                <tr data-employee-id="<?= $id ?>" data-search="<?= h(strtolower(($employee['personnel_number'] ?? '') . ' ' . $employee['name'] . ' ' . ($employee['email'] ?? '') . ' ' . ($employee['department'] ?? ''))) ?>">
                    <td>
                        <div class="employee-identity">
                            <span class="employee-avatar" aria-hidden="true"><?= h($initials ?: 'M') ?></span>
                            <span>
                                <strong><?= h($employee['name']) ?></strong>
                                <small><?= h(($employee['personnel_number'] ? $employee['personnel_number'] . ' · ' : '') . ($employee['department'] ?: $employee['email'])) ?></small>
                            </span>
                        </div>
                    </td>
                    <td class="status-cell"><?= $this->renderStatusBadge($statuses[$id]) ?></td>
                    <td><span class="today-cell time-value"><?= h(seconds_to_hhmmss($totals[$id]['net_seconds'])) ?></span></td>
                    <td class="text-end">
                        <div class="employee-row-actions">
                            <button
                                type="button"
                                class="btn btn-table-action employee-edit"
                                data-bs-toggle="modal"
                                data-bs-target="#employeeEditModal"
                                data-employee-id="<?= $id ?>"
                                data-name="<?= h($employee['name']) ?>"
                                data-email="<?= h($employee['email']) ?>"
                                data-role="<?= h($employee['role']) ?>"
                                data-timezone="<?= h($employee['timezone']) ?>"
                                data-personnel-number="<?= h((string)($employee['personnel_number'] ?? '')) ?>"
                                data-department="<?= h((string)($employee['department'] ?? '')) ?>"
                                data-phone="<?= h((string)($employee['phone'] ?? '')) ?>"
                                data-weekly-hours="<?= h((string)($employee['weekly_hours'] ?? '0')) ?>"
                                data-is-trainee="<?= (int)($employee['is_trainee'] ?? 0) ?>"
                                data-special-time="<?= (int)($employee['special_time'] ?? 0) ?>"
                                data-active="<?= (int)($employee['active'] ?? 0) ?>"
                                data-login-enabled="<?= (int)($employee['login_enabled'] ?? 0) ?>"
                            >
                                Bearbeiten
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 4 6 6M4 20l4-1 11-11a2 2 0 0 0-3-3L5 16l-1 4Z"/></svg>
                            </button>
                            <a class="btn btn-table-action" href="<?= h(url('/employee?id=' . $id)) ?>">
                                Details
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$employees): ?>
                <tr><td colspan="4" class="empty-state"><span>Keine Mitarbeiter vorhanden.</span></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="table-empty-filter" id="employeeSearchEmpty" hidden>Keine passenden Mitarbeiter gefunden.</div>
</section>

<div class="modal fade" id="employeeEditModal" tabindex="-1" aria-labelledby="employeeEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form id="formEmployeeEdit" data-current-admin-id="<?= (int)$currentAdminId ?>">
                <input type="hidden" name="employeeId">
                <div class="modal-header">
                    <div class="modal-title-group">
                        <div class="eyebrow">Zugang verwalten</div>
                        <h2 class="modal-title" id="employeeEditTitle">Mitarbeiter bearbeiten</h2>
                        <p>Stammdaten, Zugang und Beschäftigungsstatus verwalten.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Personalnummer</label><input class="form-control" name="personnel_number" maxlength="50"></div>
                        <div class="col-md-8"><label class="form-label">Name</label><input class="form-control" name="name" required maxlength="100" autocomplete="name"></div>
                        <div class="col-md-6"><label class="form-label">E-Mail</label><input class="form-control" name="email" type="email" required autocomplete="email"></div>
                        <div class="col-md-6"><label class="form-label">Telefon</label><input class="form-control" name="phone" maxlength="50"></div>
                        <div class="col-md-6"><label class="form-label">Abteilung</label><input class="form-control" name="department" maxlength="100"></div>
                        <div class="col-md-3"><label class="form-label">Wochenstunden</label><input class="form-control" name="weekly_hours" type="number" min="0" max="168" step="0.25"></div>
                        <div class="col-md-3"><label class="form-label">Rolle</label><select class="form-select" name="role"><option value="employee">Mitarbeiter</option><option value="admin">Administration</option></select></div>
                        <div class="col-md-6"><label class="form-label">Neues Passwort</label><input class="form-control" name="password" type="password" minlength="6" autocomplete="new-password" placeholder="Leer lassen, um es beizubehalten"></div>
                        <div class="col-md-6"><label class="form-label">Zeitzone</label><select class="form-select" name="timezone" required>
                            <?php foreach ($timezoneOptions as $group => $options): ?><optgroup label="<?= h($group) ?>"><?php foreach ($options as $option): ?><option value="<?= h($option['value']) ?>"><?= h($option['label']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                        </select></div>
                        <div class="col-12 d-flex flex-wrap gap-4">
                            <div class="form-check"><input class="form-check-input" name="is_trainee" id="editTrainee" type="checkbox" value="1"><label class="form-check-label" for="editTrainee">Auszubildender</label></div>
                            <div class="form-check"><input class="form-check-input" name="special_time" id="editSpecial" type="checkbox" value="1"><label class="form-check-label" for="editSpecial">Sonderarbeitszeit</label></div>
                            <div class="form-check"><input class="form-check-input" name="active" id="editActive" type="checkbox" value="1"><label class="form-check-label" for="editActive">Aktiv beschäftigt</label></div>
                            <div class="form-check"><input class="form-check-input" name="login_enabled" id="editLogin" type="checkbox" value="1"><label class="form-check-label" for="editLogin">Anmeldung erlaubt</label></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer employee-edit-footer">
                    <div class="employee-delete-area">
                        <button type="button" class="btn btn-danger" id="deleteEmployeeButton">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V3h8v3M19 6l-1 15H6L5 6M10 11v5M14 11v5"/></svg>
                            Mitarbeiter deaktivieren
                        </button>
                        <small id="employeeDeleteHelp">Alle Arbeitszeiten und Abwesenheiten bleiben erhalten.</small>
                    </div>
                    <div class="employee-edit-actions">
                        <button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-brand">Änderungen speichern</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="weekReportModal" tabindex="-1" aria-labelledby="weekReportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= h(url('/reports/week.pdf')) ?>" target="_blank" id="weekReportForm">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div class="modal-header week-report-header">
                <div class="modal-title-group me-auto">
                    <div class="eyebrow">KW <?= h(sprintf('%02d', $week['week'])) ?></div>
                    <h2 class="modal-title" id="weekReportTitle">Wochenzettel drucken</h2>
                    <p><?= h($week['start_label']) ?> bis <?= h($week['end_label']) ?></p>
                </div>
                <button type="submit" class="btn btn-brand week-report-print">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                    PDF öffnen
                </button>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <div class="report-toolbar">
                    <label class="search-field flex-grow-1" for="reportEmployeeSearch">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                        <input id="reportEmployeeSearch" type="search" placeholder="Liste durchsuchen" autocomplete="off">
                    </label>
                    <span class="selection-count" id="reportSelectionCount"></span>
                </div>
                <label class="report-select-all">
                    <input class="form-check-input" type="checkbox" id="selectAllEmployees" checked>
                    <span>
                        <strong>Alle Mitarbeiter auswählen</strong>
                        <small>Für jede Auswahl wird eine eigene PDF-Seite erstellt.</small>
                    </span>
                </label>
                <div class="report-employee-list" id="reportEmployeeList">
                    <?php foreach ($employees as $employee): ?>
                        <?php if (!(int)$employee['active']) continue; ?>
                        <label class="report-employee-item" data-search="<?= h(strtolower(($employee['personnel_number'] ?? '') . ' ' . $employee['name'] . ' ' . ($employee['email'] ?? '') . ' ' . ($employee['department'] ?? ''))) ?>">
                            <input class="form-check-input report-employee-checkbox" type="checkbox" name="employee_ids[]" value="<?= (int)$employee['id'] ?>" checked>
                            <span class="report-avatar" aria-hidden="true"><?= h(strtoupper(substr((string)$employee['name'], 0, 1))) ?></span>
                            <span class="report-person">
                                <strong><?= h($employee['name']) ?></strong>
                                <small><?= h(($employee['personnel_number'] ? $employee['personnel_number'] . ' · ' : '') . ($employee['department'] ?: $employee['email'])) ?></small>
                            </span>
                            <svg class="check-mark" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="report-empty" id="reportSearchEmpty" hidden>Keine passenden Mitarbeiter gefunden.</div>
            </div>
        </form>
    </div>
</div>
