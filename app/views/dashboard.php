<?php
declare(strict_types=1);
$activeEmployeeCount = count(array_filter($employees, static fn(array $employee): bool => (int)($employee['active'] ?? 0) === 1));
$inactiveEmployeeCount = count($employees) - $activeEmployeeCount;
$workingCount = 0;
$breakCount = 0;
foreach ($statuses as $itemStatus) {
    if (($itemStatus['status'] ?? '') === 'WORKING' && empty($itemStatus['stale_session'])) $workingCount++;
    if (($itemStatus['status'] ?? '') === 'ON_BREAK') $breakCount++;
}
$reportNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$currentReportWeek = sprintf('%04d-W%02d', (int)$week['year'], (int)$week['week']);
$currentReportMonth = $reportNow->format('Y-m');
$currentReportYear = $reportNow->format('Y');
$scheduleDayLabels = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
$defaultScheduleHours = [1 => 8.5, 2 => 8.5, 3 => 8.5, 4 => 8.5, 5 => 4.0, 6 => 0.0, 7 => 0.0];
?>
<section class="page-hero dashboard-hero">
    <div class="page-hero-copy">
        <div class="eyebrow">Administration</div>
        <h1>Mitarbeiter im Blick</h1>
        <p>Arbeitszeiten, Anwesenheiten und Zeitnachweise zentral und übersichtlich verwalten.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline-brand" data-bs-toggle="modal" data-bs-target="#timeReportModal">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
            Zeitnachweise
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
        <div><strong><?= $activeEmployeeCount ?></strong><span>Aktive Mitarbeiter</span></div>
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
                <p>Persönliche Daten und den regelmäßigen Wochenplan direkt gemeinsam festlegen.</p>
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
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="employeeRole">Rolle</label>
                <select class="form-select" id="employeeRole" name="role">
                    <option value="employee">Mitarbeiter</option>
                    <option value="admin">Administration</option>
                </select>
            </div>
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="employeeVacation">Urlaubsanspruch</label>
                <input class="form-control" id="employeeVacation" name="vacation_entitlement" type="number" min="0" max="365" step="0.5" value="<?= h((string)cfg('default_vacation_entitlement', 30)) ?>">
            </div>
            <div class="col-lg-4 col-md-6">
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

            <div class="col-12">
                <section class="schedule-planner schedule-planner-create" data-schedule-planner>
                    <div class="schedule-planner-heading">
                        <div>
                            <div class="eyebrow">Arbeitszeiten</div>
                            <h3>Regelmäßiger Wochenplan</h3>
                            <p>Arbeitstage lassen sich einzeln aktivieren. Urlaub und Abwesenheiten werden nur an eingeplanten Tagen angerechnet.</p>
                        </div>
                        <div class="schedule-total" aria-live="polite">
                            <span>Wochenzeit</span>
                            <strong data-schedule-total>38:00 Std.</strong>
                        </div>
                    </div>
                    <div class="schedule-day-cards">
                        <?php foreach ($scheduleDayLabels as $weekday => $dayLabel): $hours = $defaultScheduleHours[$weekday]; $enabled = $hours > 0; ?>
                            <article class="schedule-day-card<?= $enabled ? '' : ' is-off' ?>" data-schedule-day>
                                <div class="schedule-day-card-head">
                                    <div>
                                        <strong><?= h($dayLabel) ?></strong>
                                        <span><?= $enabled ? 'Arbeitstag' : 'Frei' ?></span>
                                    </div>
                                    <label class="schedule-day-switch" title="Arbeitstag ein- oder ausschalten">
                                        <input type="checkbox" data-schedule-toggle<?= $enabled ? ' checked' : '' ?> aria-label="<?= h($dayLabel) ?> als Arbeitstag verwenden">
                                        <span aria-hidden="true"></span>
                                    </label>
                                </div>
                                <label class="form-label" for="createScheduleDay<?= $weekday ?>">Stunden</label>
                                <div class="schedule-hours-input">
                                    <input
                                        class="form-control"
                                        id="createScheduleDay<?= $weekday ?>"
                                        name="schedule_day_<?= $weekday ?>"
                                        type="number"
                                        min="0"
                                        max="24"
                                        step="0.25"
                                        value="<?= h(rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.')) ?>"
                                        data-schedule-hours
                                        data-default-hours="<?= $weekday === 5 ? '4' : '8.5' ?>"
                                        <?= $enabled ? '' : 'disabled' ?>
                                    >
                                    <span>Std.</span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="schedule-planner-note">
                        <span>Standard: Montag bis Donnerstag 8:30 Std., Freitag 4:00 Std.</span>
                        <span>Bei mehr als 6 geplanten Stunden: 30 Min. Pause plus Frühstart vor 08:00 Uhr.</span>
                    </div>
                </section>
            </div>

            <div class="col-lg-8 d-flex flex-wrap align-items-center gap-4">
                <div class="form-check"><input class="form-check-input" id="employeeTrainee" name="is_trainee" type="checkbox" value="1"><label class="form-check-label" for="employeeTrainee">Auszubildender</label></div>
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
        <div class="employee-list-tools">
            <label class="employee-visibility-switch" for="showInactiveEmployees">
                <input id="showInactiveEmployees" type="checkbox" role="switch" aria-describedby="showInactiveEmployeesHint">
                <span class="employee-switch-track" aria-hidden="true"><span></span></span>
                <span class="employee-switch-copy">
                    <strong>Alle anzeigen</strong>
                    <small id="showInactiveEmployeesHint"><?= $inactiveEmployeeCount ?> inaktiv</small>
                </span>
            </label>
            <label class="search-field" for="employeeSearch">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input id="employeeSearch" type="search" placeholder="Mitarbeiter suchen" autocomplete="off">
            </label>
        </div>
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
                <tr
                    data-employee-id="<?= $id ?>"
                    data-active="<?= (int)($employee['active'] ?? 0) ?>"
                    data-search="<?= h(strtolower(($employee['personnel_number'] ?? '') . ' ' . $employee['name'] . ' ' . ($employee['email'] ?? '') . ' ' . ($employee['department'] ?? ''))) ?>"
                    <?= (int)($employee['active'] ?? 0) === 1 ? '' : 'hidden' ?>
                >
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
                                data-is-trainee="<?= (int)($employee['is_trainee'] ?? 0) ?>"
                                data-active="<?= (int)($employee['active'] ?? 0) ?>"
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
                        <div class="eyebrow">Mitarbeiter verwalten</div>
                        <h2 class="modal-title" id="employeeEditTitle">Mitarbeiter bearbeiten</h2>
                        <p>Stammdaten, Rolle und Beschäftigungsstatus verwalten.</p>
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
                        <div class="col-md-6"><label class="form-label">Rolle</label><select class="form-select" name="role"><option value="employee">Mitarbeiter</option><option value="admin">Administration</option></select></div>
                        <div class="col-md-6">
                            <label class="form-label">Neues Passwort</label>
                            <input class="form-control" name="password" type="password" minlength="6" autocomplete="new-password" placeholder="Leer lassen, um es beizubehalten">
                            <div class="form-text">Aktive Mitarbeiter können sich automatisch mit E-Mail-Adresse und Passwort anmelden.</div>
                        </div>
                        <div class="col-md-6"><label class="form-label">Zeitzone</label><select class="form-select" name="timezone" required>
                            <?php foreach ($timezoneOptions as $group => $options): ?><optgroup label="<?= h($group) ?>"><?php foreach ($options as $option): ?><option value="<?= h($option['value']) ?>"><?= h($option['label']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                        </select></div>
                        <div class="col-12 d-flex flex-wrap gap-4">
                            <div class="form-check"><input class="form-check-input" name="is_trainee" id="editTrainee" type="checkbox" value="1"><label class="form-check-label" for="editTrainee">Auszubildender</label></div>
                            <div class="form-check"><input class="form-check-input" name="active" id="editActive" type="checkbox" value="1"><label class="form-check-label" for="editActive">Aktiv beschäftigt</label></div>
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

<div class="modal fade" id="timeReportModal" tabindex="-1" aria-labelledby="timeReportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= h(url('/reports/time.pdf')) ?>" target="_blank" id="timeReportForm">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div class="modal-header time-report-header">
                <div class="modal-title-group me-auto">
                    <div class="eyebrow" id="reportPeriodEyebrow">Wöchentlicher Nachweis</div>
                    <h2 class="modal-title" id="timeReportTitle">Zeitnachweise als PDF</h2>
                    <p id="reportPeriodDescription">Eine übersichtliche Seite je Mitarbeiter für die gewählte Kalenderwoche.</p>
                </div>
                <button type="submit" class="btn btn-brand time-report-print" id="reportPdfSubmit">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                    <span id="reportPdfSubmitLabel">Wochen-PDF öffnen</span>
                </button>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <section class="report-period-section" aria-labelledby="reportPeriodHeading">
                    <div class="report-section-label" id="reportPeriodHeading">Zeitraum und Seitenaufbau</div>
                    <div class="report-period-switch" role="radiogroup" aria-label="Art des Zeitnachweises">
                        <label class="report-period-option">
                            <input type="radio" name="report_type" value="week" checked>
                            <span class="report-period-icon" aria-hidden="true">7</span>
                            <span class="report-period-option-copy">
                                <strong>Woche</strong>
                                <small>7 Tage detailliert</small>
                            </span>
                            <span class="report-period-check" aria-hidden="true"></span>
                        </label>
                        <label class="report-period-option">
                            <input type="radio" name="report_type" value="month">
                            <span class="report-period-icon" aria-hidden="true">31</span>
                            <span class="report-period-option-copy">
                                <strong>Monat</strong>
                                <small>Alle Tage auf einer Seite</small>
                            </span>
                            <span class="report-period-check" aria-hidden="true"></span>
                        </label>
                        <label class="report-period-option">
                            <input type="radio" name="report_type" value="year">
                            <span class="report-period-icon" aria-hidden="true">12</span>
                            <span class="report-period-option-copy">
                                <strong>Jahr</strong>
                                <small>12 Monate als Übersicht</small>
                            </span>
                            <span class="report-period-check" aria-hidden="true"></span>
                        </label>
                    </div>

                    <div class="report-period-settings">
                        <div class="report-period-panel" data-report-period-panel="week">
                            <label class="form-label" for="reportWeek">Kalenderwoche</label>
                            <input class="form-control" id="reportWeek" name="report_week" type="week" value="<?= h($currentReportWeek) ?>" min="1970-W01" max="2200-W53" required>
                            <span class="report-layout-note"><strong>DIN A4 Hochformat:</strong> tägliche Beginn-, Ende-, Pausen- und Arbeitszeiten mit Unterschriftsfeld.</span>
                        </div>
                        <div class="report-period-panel" data-report-period-panel="month" hidden>
                            <label class="form-label" for="reportMonth">Monat</label>
                            <input class="form-control" id="reportMonth" name="report_month" type="month" value="<?= h($currentReportMonth) ?>" min="1970-01" max="2200-12" disabled required>
                            <span class="report-layout-note"><strong>DIN A4 Querformat:</strong> alle Kalendertage detailliert und kompakt auf genau einer Seite pro Person.</span>
                        </div>
                        <div class="report-period-panel" data-report-period-panel="year" hidden>
                            <label class="form-label" for="reportYear">Kalenderjahr</label>
                            <input class="form-control" id="reportYear" name="report_year" type="number" value="<?= h($currentReportYear) ?>" min="1970" max="2200" step="1" disabled required>
                            <span class="report-layout-note"><strong>DIN A4 Querformat:</strong> Monatswerte, Arbeitszeiten, Pausen, Urlaub, Krankheit und Feiertage auf einer Seite.</span>
                        </div>
                    </div>
                </section>

                <div class="report-list-heading">
                    <div>
                        <div class="report-section-label">Mitarbeiter</div>
                        <p>Jede ausgewählte Person erhält ein eigenes PDF-Blatt.</p>
                    </div>
                    <span class="selection-count" id="reportSelectionCount"></span>
                </div>
                <div class="report-toolbar">
                    <label class="search-field flex-grow-1" for="reportEmployeeSearch">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                        <input id="reportEmployeeSearch" type="search" placeholder="Mitarbeiter durchsuchen" autocomplete="off">
                    </label>
                </div>
                <label class="report-select-all">
                    <input class="form-check-input" type="checkbox" id="selectAllEmployees" checked>
                    <span>
                        <strong>Alle aktiven Mitarbeiter auswählen</strong>
                        <small>Die Reihenfolge im PDF ist alphabetisch.</small>
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
