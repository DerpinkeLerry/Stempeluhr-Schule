<?php
declare(strict_types=1);

$monthNames = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
    7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
];
$weekdayNames = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
$portionLabels = ['FULL' => 'Ganzer Tag', 'AM' => 'Vormittag', 'PM' => 'Nachmittag'];
$statusLabels = ['PENDING' => 'Offen', 'APPROVED' => 'Genehmigt', 'REJECTED' => 'Abgelehnt', 'CANCELLED' => 'Storniert'];
$statusClasses = ['PENDING' => 'request-pending', 'APPROVED' => 'request-approved', 'REJECTED' => 'request-rejected', 'CANCELLED' => 'request-cancelled'];

$monthDate = new DateTimeImmutable($monthStart . ' 00:00:00', new DateTimeZone('UTC'));
$previousMonth = $monthDate->modify('-1 month');
$nextMonth = $monthDate->modify('+1 month');
$requestDefaultDate = $monthEnd < $today ? $today : max($today, $monthStart);
$daysInMonth = (int)$monthDate->format('t');
$dayDates = [];
for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
    $dateObject = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    $dayDates[] = [
        'date' => $date,
        'day' => $day,
        'weekday' => (int)$dateObject->format('N'),
        'holiday' => $holidaysByDay[$date] ?? '',
        'today' => $date === $today,
    ];
}

$employeeInitials = static function (string $name): string {
    $result = '';
    foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $result .= strtoupper(substr($part, 0, 1));
        if (strlen($result) >= 2) break;
    }
    return $result !== '' ? $result : 'M';
};

$periodLabel = static function (array $item) use ($portionLabels): string {
    $start = date('d.m.Y', strtotime((string)$item['start_date']));
    $end = date('d.m.Y', strtotime((string)$item['end_date']));
    $period = $start === $end ? $start : $start . ' – ' . $end;
    $portion = (string)($item['portion'] ?? 'FULL');
    return $period . ($portion !== 'FULL' ? ' · ' . ($portionLabels[$portion] ?? $portion) : '');
};

$pendingOwnRequests = 0;
if (!$isAdmin) {
    foreach ($requests as $request) {
        if (($request['status'] ?? '') === 'PENDING') $pendingOwnRequests++;
    }
}
?>

<div id="vacationCalendarRoot" data-is-admin="<?= $isAdmin ? '1' : '0' ?>">
    <section class="page-hero vacation-hero">
        <div>
            <div class="eyebrow">Gemeinsame Planung</div>
            <h1>Urlaubskalender</h1>
            <p>Genehmigte Urlaube aller aktuell angestellten Mitarbeiter</p>
        </div>
        <div class="vacation-hero-actions">
            <?php if ($isAdmin): ?>
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#vacationCreateModal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Urlaub eintragen
                </button>
                <a class="btn btn-outline-brand" href="#vacationRequests">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                    Anträge
                    <?php if ($pendingVacationRequestCount > 0): ?><span class="button-count"><?= (int)$pendingVacationRequestCount ?></span><?php endif; ?>
                </a>
            <?php else: ?>
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#vacationRequestModal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Urlaub beantragen
                </button>
            <?php endif; ?>
        </div>
    </section>

    <section class="vacation-summary-cards">
        <article class="vacation-summary-card">
            <span class="summary-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
            <div><strong><?= count($employees) ?></strong><span>aktive Mitarbeiter</span></div>
        </article>
        <article class="vacation-summary-card">
            <span class="summary-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg></span>
            <div><strong><?= count($vacations) ?></strong><span>Urlaubseinträge im <?= h($monthNames[$month]) ?></span></div>
        </article>
        <article class="vacation-summary-card">
            <span class="summary-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l2 2 4-4M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/></svg></span>
            <div><strong><?= $isAdmin ? (int)$pendingVacationRequestCount : $pendingOwnRequests ?></strong><span><?= $isAdmin ? 'offene Anträge' : 'meine offenen Anträge' ?></span></div>
        </article>
        <?php if (!$isAdmin && $ownVacationAccount): ?>
            <article class="vacation-summary-card vacation-balance-card">
                <span class="summary-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5M4 19h16M8 16v-5M12 16V8M16 16v-3"/></svg></span>
                <div><strong><?= h(number_format((float)$ownVacationAccount['remaining_days'], 1, ',', '.')) ?></strong><span>verfügbare Urlaubstage <?= (int)$year ?></span></div>
            </article>
        <?php endif; ?>
    </section>

    <section class="surface-card vacation-calendar-card">
        <div class="vacation-calendar-toolbar">
            <div class="month-navigation" aria-label="Monatsnavigation">
                <a class="calendar-nav-button" href="<?= h(url('/vacation-calendar?year=' . $previousMonth->format('Y') . '&month=' . $previousMonth->format('n'))) ?>" aria-label="Vorheriger Monat">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </a>
                <div class="month-navigation-title">
                    <span>Planungsmonat</span>
                    <strong><?= h($monthNames[$month]) ?> <?= (int)$year ?></strong>
                </div>
                <a class="calendar-nav-button" href="<?= h(url('/vacation-calendar?year=' . $nextMonth->format('Y') . '&month=' . $nextMonth->format('n'))) ?>" aria-label="Nächster Monat">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </div>

            <form class="vacation-calendar-jump" method="get" action="<?= h(url('/vacation-calendar')) ?>">
                <label class="visually-hidden" for="vacationCalendarMonth">Monat</label>
                <select class="form-select" id="vacationCalendarMonth" name="month">
                    <?php foreach ($monthNames as $number => $name): ?>
                        <option value="<?= (int)$number ?>"<?= $number === $month ? ' selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="vacationCalendarYear">Jahr</label>
                <input class="form-control" id="vacationCalendarYear" name="year" type="number" min="1970" max="2200" value="<?= (int)$year ?>">
                <button class="btn btn-outline-brand" type="submit">Anzeigen</button>
            </form>

            <div class="vacation-calendar-tools">
                <label class="search-field compact-search" for="vacationEmployeeSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input id="vacationEmployeeSearch" type="search" placeholder="Mitarbeiter suchen">
                </label>
                <label class="calendar-toggle">
                    <input id="vacationHideFree" type="checkbox">
                    <span>Nur mit Urlaub</span>
                </label>
            </div>
        </div>

        <div class="vacation-legend" aria-label="Legende">
            <span><i class="legend-swatch legend-vacation"></i> Genehmigter Urlaub</span>
            <span><i class="legend-swatch legend-weekend"></i> Wochenende</span>
            <span><i class="legend-swatch legend-holiday"></i> Feiertag</span>
            <?php if ($isAdmin): ?><span><i class="legend-swatch legend-edit"></i> Urlaub anklicken zum Bearbeiten</span><?php endif; ?>
        </div>

        <div class="vacation-timeline-scroll">
            <div class="vacation-timeline" style="--calendar-days: <?= (int)$daysInMonth ?>">
                <div class="vacation-timeline-header">
                    <div class="calendar-employee-column calendar-employee-heading">Mitarbeiter</div>
                    <div class="calendar-days-grid calendar-day-heading-grid">
                        <?php foreach ($dayDates as $dayInfo): ?>
                            <?php
                            $classes = ['calendar-day-heading'];
                            if ($dayInfo['weekday'] >= 6) $classes[] = 'is-weekend';
                            if ($dayInfo['holiday'] !== '') $classes[] = 'is-holiday';
                            if ($dayInfo['today']) $classes[] = 'is-today';
                            ?>
                            <div class="<?= h(implode(' ', $classes)) ?>" title="<?= h($dayInfo['holiday']) ?>">
                                <span><?= h($weekdayNames[$dayInfo['weekday']]) ?></span>
                                <strong><?= (int)$dayInfo['day'] ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="vacationTimelineRows">
                    <?php foreach ($employees as $employeeIndex => $employee): ?>
                        <?php
                        $employeeId = (int)$employee['id'];
                        $employeeVacations = $vacationsByEmployee[$employeeId] ?? [];
                        $searchText = trim((string)$employee['name'] . ' ' . (string)$employee['department'] . ' ' . (string)$employee['personnel_number']);
                        $colorClass = 'vacation-color-' . (($employeeIndex % 8) + 1);
                        ?>
                        <div class="vacation-timeline-row <?= h($colorClass) ?>" data-employee-row data-has-vacation="<?= $employeeVacations ? '1' : '0' ?>" data-search="<?= h($searchText) ?>">
                            <div class="calendar-employee-column">
                                <span class="calendar-employee-avatar"><?= h($employeeInitials((string)$employee['name'])) ?></span>
                                <span class="calendar-employee-copy">
                                    <strong><?= h((string)$employee['name']) ?></strong>
                                    <small><?= h((string)($employee['department'] ?: 'Ohne Abteilung')) ?></small>
                                </span>
                            </div>
                            <div class="calendar-days-grid calendar-row-grid">
                                <?php foreach ($dayDates as $dayInfo): ?>
                                    <?php
                                    $classes = ['calendar-day-cell'];
                                    if ($dayInfo['weekday'] >= 6) $classes[] = 'is-weekend';
                                    if ($dayInfo['holiday'] !== '') $classes[] = 'is-holiday';
                                    if ($dayInfo['today']) $classes[] = 'is-today';
                                    ?>
                                    <span class="<?= h(implode(' ', $classes)) ?>" title="<?= h($dayInfo['holiday']) ?>"></span>
                                <?php endforeach; ?>

                                <?php foreach ($employeeVacations as $vacation): ?>
                                    <?php
                                    $clipStart = max((string)$vacation['start_date'], $monthStart);
                                    $clipEnd = min((string)$vacation['end_date'], $monthEnd);
                                    $startDay = (int)date('j', strtotime($clipStart));
                                    $span = (int)((strtotime($clipEnd) - strtotime($clipStart)) / 86400) + 1;
                                    $portion = (string)($vacation['portion'] ?? 'FULL');
                                    $barLabel = $periodLabel($vacation);
                                    $tag = $isAdmin ? 'button' : 'div';
                                    ?>
                                    <<?= $tag ?>
                                        class="vacation-bar<?= $portion !== 'FULL' ? ' is-half is-' . strtolower($portion) : '' ?><?= $isAdmin ? ' vacation-calendar-edit' : '' ?>"
                                        style="--bar-start: <?= $startDay ?>; --bar-span: <?= $span ?>"
                                        title="<?= h((string)$employee['name'] . ' · ' . $barLabel) ?>"
                                        <?php if ($isAdmin): ?>
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#vacationEditModal"
                                            data-absence-id="<?= (int)$vacation['id'] ?>"
                                            data-employee-name="<?= h((string)$employee['name']) ?>"
                                            data-start-date="<?= h((string)$vacation['start_date']) ?>"
                                            data-end-date="<?= h((string)$vacation['end_date']) ?>"
                                            data-portion="<?= h($portion) ?>"
                                            data-note="<?= h((string)$vacation['note']) ?>"
                                        <?php endif; ?>
                                    >
                                        <span><?= h($portion === 'FULL' ? $barLabel : ($portionLabels[$portion] ?? $portion)) ?></span>
                                    </<?= $tag ?>>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="vacationCalendarEmpty" class="calendar-filter-empty" hidden>
            <strong>Keine passenden Mitarbeiter gefunden</strong>
            <span>Suche oder Filter zurücksetzen.</span>
        </div>
    </section>

    <?php if ($isAdmin): ?>
        <section class="surface-card vacation-accounts-card">
            <div class="section-heading table-heading vacation-account-heading">
                <div>
                    <div class="eyebrow">Kontostände <?= (int)$year ?></div>
                    <h2>Urlaubskonten der aktiven Mitarbeiter</h2>
                    <p>Anspruch, automatisch übertragener Resturlaub, bereits genommene Tage und aktuell verfügbarer Bestand auf einen Blick.</p>
                </div>
                <span class="vacation-account-cutoff">Übertrag verfällt am 31.03.<?= (int)$year ?></span>
            </div>
            <div class="table-responsive">
                <table class="table vacation-account-table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Mitarbeiter</th>
                        <th class="text-end">Anspruch</th>
                        <th class="text-end">Übertrag</th>
                        <th class="text-end">Genommen</th>
                        <th class="text-end">Verfügbar</th>
                        <th class="text-end"><span class="visually-hidden">Öffnen</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <?php $account = $vacationAccounts[(int)$employee['id']] ?? null; ?>
                        <?php if (!$account) continue; ?>
                        <tr>
                            <td>
                                <span class="vacation-account-person">
                                    <strong><?= h((string)$employee['name']) ?></strong>
                                    <small><?= h((string)($employee['department'] ?: 'Ohne Abteilung')) ?></small>
                                </span>
                            </td>
                            <td class="text-end"><?= h(number_format((float)$account['entitlement_days'] + (float)$account['adjustment_days'], 1, ',', '.')) ?> T</td>
                            <td class="text-end">
                                <?= h(number_format((float)$account['carryover_days'], 1, ',', '.')) ?> T
                                <?php if ((float)$account['expired_carryover_days'] > 0): ?>
                                    <span class="account-expired-label" title="Nicht genutzter Übertrag ist nach dem 31.03. verfallen">verfallen</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= h(number_format((float)$account['used_days'], 1, ',', '.')) ?> T</td>
                            <td class="text-end"><strong class="account-remaining-value<?= (float)$account['remaining_days'] <= 0 ? ' is-empty' : '' ?>"><?= h(number_format((float)$account['remaining_days'], 1, ',', '.')) ?> T</strong></td>
                            <td class="text-end"><a class="btn btn-table-action" href="<?= h(url('/employee?id=' . (int)$employee['id'] . '&vacation_year=' . $year . '#vacation-account')) ?>">Öffnen</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section id="vacationRequests" class="surface-card vacation-requests-card">
        <div class="section-heading vacation-request-heading">
            <div>
                <div class="eyebrow"><?= $isAdmin ? 'Dauerhaft gespeichert' : 'Mein Verlauf' ?></div>
                <h2><?= $isAdmin ? 'Urlaubsanträge' : 'Meine Urlaubsanträge' ?></h2>
                <p><?= $isAdmin ? 'Jeder Antrag bleibt mit Entscheidung und Bearbeitungszeitpunkt im Archiv erhalten.' : 'Hier sehen Sie den vollständigen Status Ihrer eingereichten Anträge.' ?></p>
            </div>
            <?php if ($isAdmin): ?>
                <div class="request-filter-tabs" role="navigation" aria-label="Antragsstatus filtern">
                    <?php foreach (['PENDING' => 'Offen', 'APPROVED' => 'Genehmigt', 'REJECTED' => 'Abgelehnt', 'ALL' => 'Alle'] as $filter => $label): ?>
                        <a class="request-filter-tab<?= $requestStatus === $filter ? ' active' : '' ?>" href="<?= h(url('/vacation-calendar?year=' . $year . '&month=' . $month . '&request_status=' . $filter . '#vacationRequests')) ?>"><?= h($label) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="vacation-request-list">
            <?php foreach ($requests as $request): ?>
                <?php
                $requestStatusCode = (string)$request['status'];
                $statusLabel = $statusLabels[$requestStatusCode] ?? $requestStatusCode;
                $statusClass = $statusClasses[$requestStatusCode] ?? 'request-cancelled';
                ?>
                <article class="vacation-request-item <?= h($statusClass) ?>">
                    <div class="request-status-rail" aria-hidden="true"></div>
                    <div class="request-main">
                        <div class="request-title-row">
                            <div>
                                <?php if ($isAdmin): ?><strong><?= h((string)$request['employee_name']) ?></strong><?php else: ?><strong><?= h($periodLabel($request)) ?></strong><?php endif; ?>
                                <?php if ($isAdmin): ?><span><?= h($periodLabel($request)) ?></span><?php endif; ?>
                            </div>
                            <span class="request-status-badge <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                        </div>
                        <div class="request-meta">
                            <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg><?= h(number_format((float)$request['requested_days'], 1, ',', '.')) ?> Urlaubstage</span>
                            <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>Beantragt am <?= h(utc_to_local((string)$request['requested_at'], 'Europe/Berlin', 'd.m.Y H:i')) ?> Uhr</span>
                            <?php if (!empty($request['decided_at'])): ?>
                                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Bearbeitet am <?= h(utc_to_local((string)$request['decided_at'], 'Europe/Berlin', 'd.m.Y H:i')) ?> Uhr<?= !empty($request['decided_by_name']) ? ' von ' . h((string)$request['decided_by_name']) : '' ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ((string)$request['note'] !== ''): ?><p class="request-note"><strong>Hinweis:</strong> <?= h((string)$request['note']) ?></p><?php endif; ?>
                        <?php if ((string)$request['decision_note'] !== ''): ?><p class="request-decision-note"><strong>Entscheidung:</strong> <?= h((string)$request['decision_note']) ?></p><?php endif; ?>
                    </div>
                    <?php if ($isAdmin && $requestStatusCode === 'PENDING'): ?>
                        <button
                            class="btn btn-brand vacation-request-review"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#vacationDecisionModal"
                            data-request-id="<?= (int)$request['id'] ?>"
                            data-employee-name="<?= h((string)$request['employee_name']) ?>"
                            data-period="<?= h($periodLabel($request)) ?>"
                            data-days="<?= h(number_format((float)$request['requested_days'], 1, ',', '.')) ?>"
                            data-note="<?= h((string)$request['note']) ?>"
                        >Prüfen</button>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <?php if (!$requests): ?>
                <div class="empty-panel vacation-request-empty">
                    <span class="empty-panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l2 2 4-4M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/></svg></span>
                    <strong><?= $isAdmin ? 'Keine Anträge in diesem Filter' : 'Noch keine Urlaubsanträge' ?></strong>
                    <p><?= $isAdmin ? 'Alle früheren Entscheidungen bleiben über den Filter „Alle“ erreichbar.' : 'Ein neuer Antrag erscheint direkt nach dem Absenden an dieser Stelle.' ?></p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin && $requestPageCount > 1): ?>
            <nav class="request-pagination" aria-label="Antragsseiten">
                <?php for ($page = 1; $page <= $requestPageCount; $page++): ?>
                    <a class="request-page-link<?= $page === $requestPage ? ' active' : '' ?>" href="<?= h(url('/vacation-calendar?year=' . $year . '&month=' . $month . '&request_status=' . $requestStatus . '&requests_page=' . $page . '#vacationRequests')) ?>"><?= (int)$page ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="vacationCreateModal" tabindex="-1" aria-labelledby="vacationCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><div class="eyebrow">Administration</div><h2 class="modal-title" id="vacationCreateModalLabel">Urlaub eintragen</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <form id="vacationCalendarCreateForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="vacationCreateEmployee">Mitarbeiter</label>
                        <select class="form-select" id="vacationCreateEmployee" name="employeeId" required>
                            <option value="">Bitte auswählen</option>
                            <?php foreach ($employees as $employee): ?>
                                <?php $account = $vacationAccounts[(int)$employee['id']] ?? null; ?>
                                <option value="<?= (int)$employee['id'] ?>" data-remaining="<?= h((string)($account['remaining_days'] ?? 0)) ?>">
                                    <?= h((string)$employee['name']) ?><?= $account ? ' · ' . h(number_format((float)$account['remaining_days'], 1, ',', '.')) . ' Tage verfügbar' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6"><label class="form-label" for="vacationCreateStart">Von</label><input class="form-control" id="vacationCreateStart" name="start_date" type="date" value="<?= h($monthStart) ?>" required></div>
                        <div class="col-sm-6"><label class="form-label" for="vacationCreateEnd">Bis</label><input class="form-control" id="vacationCreateEnd" name="end_date" type="date" value="<?= h($monthStart) ?>" required></div>
                        <div class="col-12"><label class="form-label" for="vacationCreatePortion">Umfang</label><select class="form-select" id="vacationCreatePortion" name="portion"><option value="FULL">Ganzer Tag / Zeitraum</option><option value="AM">Vormittag</option><option value="PM">Nachmittag</option></select><small class="form-text">Halbe Tage sind nur für ein einzelnes Datum möglich.</small></div>
                        <div class="col-12"><label class="form-label" for="vacationCreateNote">Interne Notiz</label><textarea class="form-control" id="vacationCreateNote" name="note" rows="3" maxlength="200" placeholder="Optional"></textarea></div>
                    </div>
                    <div class="vacation-balance-warning"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span>Das Speichern wird automatisch blockiert, wenn der verfügbare Urlaub nicht ausreicht oder sich Zeiträume überschneiden.</span></div>
                    <input type="hidden" name="type" value="VACATION">
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-brand" type="submit">Urlaub speichern</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="vacationEditModal" tabindex="-1" aria-labelledby="vacationEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><div class="eyebrow">Urlaub verwalten</div><h2 class="modal-title" id="vacationEditModalLabel">Urlaub bearbeiten</h2><p id="vacationEditEmployee" class="modal-subtitle"></p></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <form id="vacationCalendarEditForm">
                <div class="modal-body">
                    <input type="hidden" name="absenceId">
                    <input type="hidden" name="type" value="VACATION">
                    <div class="row g-3">
                        <div class="col-sm-6"><label class="form-label">Von</label><input class="form-control" name="start_date" type="date" required></div>
                        <div class="col-sm-6"><label class="form-label">Bis</label><input class="form-control" name="end_date" type="date" required></div>
                        <div class="col-12"><label class="form-label">Umfang</label><select class="form-select" name="portion"><option value="FULL">Ganzer Tag / Zeitraum</option><option value="AM">Vormittag</option><option value="PM">Nachmittag</option></select></div>
                        <div class="col-12"><label class="form-label">Notiz</label><textarea class="form-control" name="note" rows="3" maxlength="200"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer modal-footer-split">
                    <button id="vacationCalendarDelete" class="btn btn-outline-danger" type="button">Urlaub löschen</button>
                    <div><button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-brand" type="submit">Änderungen speichern</button></div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="vacationDecisionModal" tabindex="-1" aria-labelledby="vacationDecisionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><div class="eyebrow">Antrag prüfen</div><h2 class="modal-title" id="vacationDecisionModalLabel">Urlaubsantrag entscheiden</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <form id="vacationDecisionForm">
                <div class="modal-body">
                    <input type="hidden" name="requestId">
                    <div class="decision-request-summary">
                        <strong id="vacationDecisionEmployee"></strong>
                        <span id="vacationDecisionPeriod"></span>
                        <small id="vacationDecisionDays"></small>
                        <p id="vacationDecisionRequestNote" hidden></p>
                    </div>
                    <fieldset class="decision-options">
                        <legend>Entscheidung</legend>
                        <label class="decision-option decision-approve"><input type="radio" name="decision" value="APPROVED" checked><span><strong>Genehmigen</strong><small>Der Urlaub wird sofort im Kalender eingetragen.</small></span></label>
                        <label class="decision-option decision-reject"><input type="radio" name="decision" value="REJECTED"><span><strong>Ablehnen</strong><small>Der Antrag bleibt mit der Ablehnung im Archiv.</small></span></label>
                    </fieldset>
                    <label class="form-label" for="vacationDecisionNote">Begründung / Notiz</label>
                    <textarea class="form-control" id="vacationDecisionNote" name="decision_note" rows="3" maxlength="300" placeholder="Optional, aber bei Ablehnung empfohlen"></textarea>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-brand" type="submit">Entscheidung speichern</button></div>
            </form>
        </div>
    </div>
</div>
<?php else: ?>
<div class="modal fade" id="vacationRequestModal" tabindex="-1" aria-labelledby="vacationRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><div class="eyebrow">Urlaubsplanung</div><h2 class="modal-title" id="vacationRequestModalLabel">Urlaub beantragen</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <form id="vacationRequestForm">
                <div class="modal-body">
                    <?php if ($ownVacationAccount): ?>
                        <div class="request-balance-strip">
                            <div><span>Verfügbar <?= (int)$year ?></span><strong><?= h(number_format((float)$ownVacationAccount['remaining_days'], 1, ',', '.')) ?> Tage</strong></div>
                            <div><span>Übertrag bis 31.03.</span><strong><?= h(number_format((float)$ownVacationAccount['carryover_remaining_days'], 1, ',', '.')) ?> Tage</strong></div>
                        </div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-sm-6"><label class="form-label" for="vacationRequestStart">Von</label><input class="form-control" id="vacationRequestStart" name="start_date" type="date" min="<?= h($today) ?>" value="<?= h($requestDefaultDate) ?>" required></div>
                        <div class="col-sm-6"><label class="form-label" for="vacationRequestEnd">Bis</label><input class="form-control" id="vacationRequestEnd" name="end_date" type="date" min="<?= h($today) ?>" value="<?= h($requestDefaultDate) ?>" required></div>
                        <div class="col-12"><label class="form-label" for="vacationRequestPortion">Umfang</label><select class="form-select" id="vacationRequestPortion" name="portion"><option value="FULL">Ganzer Tag / Zeitraum</option><option value="AM">Vormittag</option><option value="PM">Nachmittag</option></select><small class="form-text">Halbe Tage sind nur für ein einzelnes Datum möglich.</small></div>
                        <div class="col-12"><label class="form-label" for="vacationRequestNote">Hinweis an die Administration</label><textarea class="form-control" id="vacationRequestNote" name="note" rows="3" maxlength="200" placeholder="Optional"></textarea></div>
                    </div>
                    <div class="vacation-balance-warning"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span>Der Antrag wird gespeichert und bleibt auch nach der Entscheidung dauerhaft in Ihrem Verlauf sichtbar.</span></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-brand" type="submit">Antrag verbindlich senden</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
