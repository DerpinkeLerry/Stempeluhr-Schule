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
$requestTypeLabels = ['CREATE' => 'Neuer Urlaub', 'CHANGE' => 'Änderung', 'DELETE' => 'Löschung'];
$requestTypeClasses = ['CREATE' => 'request-type-create', 'CHANGE' => 'request-type-change', 'DELETE' => 'request-type-delete'];

$previousYear = $year - 1;
$nextYear = $year + 1;
$currentCalendarYear = (int)date('Y', strtotime($today));
$requestDefaultDate = $year === $currentCalendarYear
    ? $today
    : ($year > $currentCalendarYear ? $periodStart : $periodEnd);
$calendarPeriodLabel = 'im Jahr ' . $year;

$employeeInitials = static function (string $name): string {
    $result = '';
    foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $result .= strtoupper(substr($part, 0, 1));
        if (strlen($result) >= 2) {
            break;
        }
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

$originalPeriodLabel = static function (array $item) use ($periodLabel): string {
    if (empty($item['original_start_date']) || empty($item['original_end_date'])) {
        return '';
    }
    return $periodLabel([
        'start_date' => (string)$item['original_start_date'],
        'end_date' => (string)$item['original_end_date'],
        'portion' => (string)($item['original_portion'] ?? 'FULL'),
    ]);
};

$pendingOwnRequests = 0;
if (!$isAdmin) {
    foreach ($requests as $request) {
        if (($request['status'] ?? '') === 'PENDING') {
            $pendingOwnRequests++;
        }
    }
}

// A balanced palette keeps employees clearly distinguishable without looking neon.
// The sequence alternates warm and cool hues; labels always use white text.
$employeePalette = [
    ['background' => '#3B6FD8', 'foreground' => '#FFFFFF'], // royal blue
    ['background' => '#E17835', 'foreground' => '#FFFFFF'], // warm orange
    ['background' => '#C4478D', 'foreground' => '#FFFFFF'], // berry pink
    ['background' => '#4B9A61', 'foreground' => '#FFFFFF'], // fresh green
    ['background' => '#7756C7', 'foreground' => '#FFFFFF'], // violet
    ['background' => '#2D98A8', 'foreground' => '#FFFFFF'], // cyan teal
    ['background' => '#C94549', 'foreground' => '#FFFFFF'], // clear red
    ['background' => '#D5A62A', 'foreground' => '#FFFFFF'], // golden amber
    ['background' => '#187F75', 'foreground' => '#FFFFFF'], // deep teal
    ['background' => '#CF3F78', 'foreground' => '#FFFFFF'], // raspberry pink
    ['background' => '#355C9A', 'foreground' => '#FFFFFF'], // deep blue
    ['background' => '#E38A42', 'foreground' => '#FFFFFF'], // tangerine
    ['background' => '#77A441', 'foreground' => '#FFFFFF'], // leaf green
    ['background' => '#5867C9', 'foreground' => '#FFFFFF'], // indigo
    ['background' => '#2B9A72', 'foreground' => '#FFFFFF'], // emerald
    ['background' => '#DD6B65', 'foreground' => '#FFFFFF'], // coral
    ['background' => '#934EC2', 'foreground' => '#FFFFFF'], // purple
    ['background' => '#3D9DCA', 'foreground' => '#FFFFFF'], // sky blue
    ['background' => '#B64268', 'foreground' => '#FFFFFF'], // raspberry
    ['background' => '#3375AE', 'foreground' => '#FFFFFF'], // azure
    ['background' => '#C34455', 'foreground' => '#FFFFFF'], // rose red
    ['background' => '#387D60', 'foreground' => '#FFFFFF'], // forest green
    ['background' => '#6550AF', 'foreground' => '#FFFFFF'], // deep violet
    ['background' => '#3E9A4F', 'foreground' => '#FFFFFF'], // meadow green
];
$employeeById = [];
$employeeColorById = [];
$employeeTextColorById = [];
foreach ($employees as $employee) {
    $employeeId = (int)$employee['id'];
    $employeeById[$employeeId] = $employee;
    // Consecutive employee IDs receive alternating warm and cool hues. The assignment
    // stays stable when the list is filtered or sorted.
    $paletteIndex = max(0, $employeeId - 1) % count($employeePalette);
    $employeeColorById[$employeeId] = $employeePalette[$paletteIndex]['background'];
    $employeeTextColorById[$employeeId] = $employeePalette[$paletteIndex]['foreground'];
}

// The full-year view follows the compact planning-board model of the previous
// calendar: months run vertically, days horizontally, and overlapping vacations
// are placed on the smallest possible number of tracks.
$yearMatrixMonths = [];
foreach ($monthNames as $monthNumber => $monthName) {
    $matrixMonthStart = sprintf('%04d-%02d-01', $year, $monthNumber);
    $matrixMonthDate = new DateTimeImmutable($matrixMonthStart, new DateTimeZone('UTC'));
    $matrixMonthEnd = $matrixMonthDate->modify('last day of this month')->format('Y-m-d');
    $matrixDaysInMonth = (int)$matrixMonthDate->format('t');
    $matrixDays = [];

    for ($day = 1; $day <= 31; $day++) {
        if ($day > $matrixDaysInMonth) {
            $matrixDays[] = ['outside' => true, 'day' => $day];
            continue;
        }

        $date = sprintf('%04d-%02d-%02d', $year, $monthNumber, $day);
        $dateObject = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        $matrixDays[] = [
            'outside' => false,
            'date' => $date,
            'day' => $day,
            'weekday' => (int)$dateObject->format('N'),
            'holiday' => $holidaysByDay[$date] ?? '',
            'today' => $date === $today,
        ];
    }

    $rawSegments = [];
    $monthEmployeeIds = [];
    $monthVacationIds = [];
    foreach ($vacations as $vacation) {
        $employeeId = (int)$vacation['employee_id'];
        if (!isset($employeeById[$employeeId])) {
            continue;
        }
        if ((string)$vacation['end_date'] < $matrixMonthStart || (string)$vacation['start_date'] > $matrixMonthEnd) {
            continue;
        }

        $clipStart = max((string)$vacation['start_date'], $matrixMonthStart);
        $clipEnd = min((string)$vacation['end_date'], $matrixMonthEnd);
        $workdaySegments = vacation_calendar_workday_segments($clipStart, $clipEnd, $holidaysByDay);
        if ($workdaySegments === []) {
            continue;
        }

        $employee = $employeeById[$employeeId];
        $name = (string)$employee['name'];
        $portion = (string)($vacation['portion'] ?? 'FULL');
        $vacationId = (int)$vacation['id'];
        $monthVacationIds[$vacationId] = true;
        $monthEmployeeIds[$employeeId] = true;

        foreach ($workdaySegments as $workdaySegment) {
            $span = (int)$workdaySegment['span'];
            $rawSegments[] = [
                'vacation' => $vacation,
                'employee' => $employee,
                'employee_id' => $employeeId,
                'search' => trim($name . ' ' . (string)$employee['department'] . ' ' . (string)$employee['personnel_number']),
                'color' => $employeeColorById[$employeeId],
                'text_color' => $employeeTextColorById[$employeeId],
                'initials' => $employeeInitials($name),
                'start_day' => (int)$workdaySegment['start_day'],
                'end_day' => (int)$workdaySegment['end_day'],
                'span' => $span,
                'visible_start_date' => (string)$workdaySegment['start_date'],
                'visible_end_date' => (string)$workdaySegment['end_date'],
                'portion' => $portion,
                'period_label' => $periodLabel($vacation),
            ];
        }
    }

    $segments = vacation_calendar_merge_visual_segments($rawSegments);

    $trackEndDays = [];
    foreach ($segments as $index => $segment) {
        $track = 0;
        while (isset($trackEndDays[$track]) && $segment['start_day'] <= $trackEndDays[$track]) {
            $track++;
        }
        $trackEndDays[$track] = $segment['end_day'];

        $visibleStartDate = (string)($segment['visible_start_date'] ?? '');
        $visibleEndDate = (string)($segment['visible_end_date'] ?? '');
        $visiblePeriod = $visibleStartDate !== '' && $visibleEndDate !== ''
            ? date('d.m.Y', strtotime($visibleStartDate))
                . ($visibleStartDate === $visibleEndDate ? '' : ' – ' . date('d.m.Y', strtotime($visibleEndDate)))
            : (string)$segment['period_label'];

        $segments[$index]['track'] = $track + 1;
        $segments[$index]['group_id'] = 'm' . $monthNumber . '-e' . (int)$segment['employee_id'] . '-g' . ($index + 1);
        $segments[$index]['visual_period_label'] = $visiblePeriod;
        $segments[$index]['bar_label'] = (string)$segment['employee']['name'];
    }

    $yearMatrixMonths[$monthNumber] = [
        'name' => $monthName,
        'days' => $matrixDays,
        'segments' => $segments,
        'track_count' => max(1, count($trackEndDays)),
        'vacation_count' => count($monthVacationIds),
        'employee_count' => count($monthEmployeeIds),
        'is_current' => $year === (int)date('Y', strtotime($today))
            && $monthNumber === (int)date('n', strtotime($today)),
    ];
}
?>

<div id="vacationCalendarRoot" data-is-admin="<?= $isAdmin ? '1' : '0' ?>">
    <section class="page-hero vacation-hero">
        <div>
            <div class="eyebrow">Gemeinsame Planung</div>
            <h1>Urlaubskalender</h1>
            <p>Genehmigte Urlaube aller aktuell angestellten Mitarbeiter.</p>
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
                <button class="btn btn-outline-brand" type="button" data-bs-toggle="modal" data-bs-target="#vacationChangeRequestModal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h11M4 7l3-3M4 7l3 3M20 17H9M20 17l-3-3M20 17l-3 3"/></svg>
                    Urlaub ändern
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
            <div><strong><?= count($vacations) ?></strong><span>Urlaubseinträge <?= h($calendarPeriodLabel) ?></span></div>
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
            <div class="month-navigation" aria-label="Jahresnavigation">
                <a class="calendar-nav-button" href="<?= h(url('/vacation-calendar?year=' . $previousYear)) ?>" aria-label="Vorheriges Jahr">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </a>
                <div class="month-navigation-title">
                    <span>Planungsjahr</span>
                    <strong><?= (int)$year ?></strong>
                </div>
                <a class="calendar-nav-button" href="<?= h(url('/vacation-calendar?year=' . $nextYear)) ?>" aria-label="Nächstes Jahr">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </div>

            <form class="vacation-calendar-jump" method="get" action="<?= h(url('/vacation-calendar')) ?>">
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
                    <span>Nur belegte Monate</span>
                </label>
            </div>
        </div>

        <div class="vacation-legend" aria-label="Legende">
            <span><i class="legend-swatch legend-vacation"></i> Genehmigter Urlaub</span>
            <span><i class="legend-swatch legend-weekend"></i> Wochenende</span>
            <span><i class="legend-swatch legend-holiday"></i> Feiertag</span>
            <?php if ($isAdmin): ?>
                <span><i class="legend-swatch legend-select"></i> Freie Fläche oder Datum oben anklicken und zum Markieren ziehen</span>
                <span><i class="legend-swatch legend-edit"></i> Urlaub anklicken zum Bearbeiten</span>
            <?php endif; ?>
            <span class="year-view-hint">Mitarbeiterfarbe anklicken, um gezielt zu filtern.</span>
        </div>

        <div class="vacation-employee-key" data-employee-key>
            <div class="vacation-employee-key-heading">
                <div>
                    <strong>Mitarbeiterfarben</strong>
                    <span>Jede Farbe gehört dauerhaft zu einer Person.</span>
                </div>
                <small>Farbe anklicken: Mitarbeiter hervorheben</small>
            </div>
            <div class="vacation-employee-key-list" role="list" aria-label="Farben der Mitarbeiter">
                <?php foreach ($employees as $employee): ?>
                    <?php
                    $employeeId = (int)$employee['id'];
                    $employeeColor = $employeeColorById[$employeeId] ?? '#3B6FD8';
                    $employeeTextColor = $employeeTextColorById[$employeeId] ?? '#FFFFFF';
                    $searchText = trim((string)$employee['name'] . ' ' . (string)$employee['department'] . ' ' . (string)$employee['personnel_number']);
                    $hasVacationInYear = !empty($vacationsByEmployee[$employeeId]);
                    ?>
                    <button
                        class="vacation-employee-key-item"
                        type="button"
                        role="listitem"
                        style="--employee-color: <?= h($employeeColor) ?>; --employee-text-color: <?= h($employeeTextColor) ?>"
                        data-employee-legend
                        data-employee-filter="<?= h((string)$employee['name']) ?>"
                        data-search="<?= h($searchText) ?>"
                        data-has-vacation="<?= $hasVacationInYear ? '1' : '0' ?>"
                        title="<?= h((string)$employee['name'] . ' filtern') ?>"
                    >
                        <i aria-hidden="true"></i>
                        <span><?= h((string)$employee['name']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="vacation-year-board-scroll" data-year-matrix>
            <div class="vacation-year-board">
                <?php foreach ($yearMatrixMonths as $monthNumber => $matrixMonth): ?>
                    <?php
                    $monthSummary = $matrixMonth['vacation_count'] === 0
                        ? 'Keine Urlaube'
                        : $matrixMonth['vacation_count'] . ' ' . ($matrixMonth['vacation_count'] === 1 ? 'Eintrag' : 'Einträge')
                            . ' · ' . $matrixMonth['employee_count'] . ' ' . ($matrixMonth['employee_count'] === 1 ? 'Person' : 'Personen');
                    ?>
                    <section
                        class="vacation-year-board-month<?= $matrixMonth['is_current'] ? ' is-current' : '' ?>"
                        data-year-month-row
                        data-has-vacation="<?= $matrixMonth['vacation_count'] > 0 ? '1' : '0' ?>"
                        aria-labelledby="vacationYearMonth<?= (int)$monthNumber ?>"
                    >
                        <div class="vacation-year-board-month-label">
                            <span><?= str_pad((string)$monthNumber, 2, '0', STR_PAD_LEFT) ?></span>
                            <strong id="vacationYearMonth<?= (int)$monthNumber ?>"><?= h($matrixMonth['name']) ?></strong>
                            <small><?= h($monthSummary) ?></small>
                        </div>

                        <div class="vacation-year-board-grid" style="--track-count: <?= (int)$matrixMonth['track_count'] ?>">
                            <div
                                class="vacation-year-board-days<?= $isAdmin ? ' is-admin-selectable' : '' ?>"
                                <?= $isAdmin ? 'aria-label="Tage des Monats – anklicken oder zum Markieren ziehen"' : 'aria-hidden="true"' ?>
                            >
                                <?php foreach ($matrixMonth['days'] as $dayInfo): ?>
                                    <?php if ($dayInfo['outside']): ?>
                                        <span class="is-outside"></span>
                                    <?php else: ?>
                                        <?php
                                        $classes = [];
                                        if ($dayInfo['weekday'] >= 6) $classes[] = 'is-weekend';
                                        if ($dayInfo['holiday'] !== '') $classes[] = 'is-holiday';
                                        if ($dayInfo['today']) $classes[] = 'is-today';
                                        $isSelectableWorkday = $dayInfo['weekday'] < 6 && $dayInfo['holiday'] === '';
                                        $selectionTitle = $dayInfo['holiday'] !== ''
                                            ? (string)$dayInfo['holiday'] . ' – wird nicht als Urlaubstag gezählt'
                                            : ($dayInfo['weekday'] >= 6
                                                ? 'Wochenende – wird nicht als Urlaubstag gezählt'
                                                : date('d.m.Y', strtotime((string)$dayInfo['date'])) . ' für Urlaub auswählen');
                                        ?>
                                        <span
                                            class="<?= h(implode(' ', $classes)) ?>"
                                            title="<?= h($isAdmin ? $selectionTitle : (string)$dayInfo['holiday']) ?>"
                                            <?php if ($isAdmin): ?>
                                                role="button"
                                                tabindex="<?= $isSelectableWorkday ? '0' : '-1' ?>"
                                                data-vacation-select-handle-date="<?= h((string)$dayInfo['date']) ?>"
                                                data-selectable-workday="<?= $isSelectableWorkday ? '1' : '0' ?>"
                                                aria-label="<?= h($selectionTitle) ?>"
                                                aria-disabled="<?= $isSelectableWorkday ? 'false' : 'true' ?>"
                                            <?php endif; ?>
                                        >
                                            <small><?= h($weekdayNames[$dayInfo['weekday']]) ?></small>
                                            <strong><?= (int)$dayInfo['day'] ?></strong>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="vacation-year-board-lanes">
                                <div
                                    class="vacation-year-board-background<?= $isAdmin ? ' is-admin-selectable' : '' ?>"
                                    <?= $isAdmin ? 'data-vacation-selection-layer' : 'aria-hidden="true"' ?>
                                >
                                    <?php foreach ($matrixMonth['days'] as $dayInfo): ?>
                                        <?php if ($dayInfo['outside']): ?>
                                            <span class="is-outside"></span>
                                        <?php else: ?>
                                            <?php
                                            $classes = [];
                                            if ($dayInfo['weekday'] >= 6) $classes[] = 'is-weekend';
                                            if ($dayInfo['holiday'] !== '') $classes[] = 'is-holiday';
                                            if ($dayInfo['today']) $classes[] = 'is-today';
                                            $isSelectableWorkday = $dayInfo['weekday'] < 6 && $dayInfo['holiday'] === '';
                                            $selectionTitle = $dayInfo['holiday'] !== ''
                                                ? (string)$dayInfo['holiday'] . ' – wird nicht als Urlaubstag gezählt'
                                                : ($dayInfo['weekday'] >= 6
                                                    ? 'Wochenende – wird nicht als Urlaubstag gezählt'
                                                    : 'Urlaub ab ' . date('d.m.Y', strtotime((string)$dayInfo['date'])) . ' auswählen');
                                            ?>
                                            <?php if ($isAdmin): ?>
                                                <button
                                                    class="vacation-year-board-day-select <?= h(implode(' ', $classes)) ?>"
                                                    type="button"
                                                    data-vacation-select-date="<?= h((string)$dayInfo['date']) ?>"
                                                    data-selectable-workday="<?= $isSelectableWorkday ? '1' : '0' ?>"
                                                    aria-label="<?= h($selectionTitle) ?>"
                                                    aria-disabled="<?= $isSelectableWorkday ? 'false' : 'true' ?>"
                                                    title="<?= h($selectionTitle) ?>"
                                                ></button>
                                            <?php else: ?>
                                                <span class="<?= h(implode(' ', $classes)) ?>" title="<?= h((string)$dayInfo['holiday']) ?>"></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>

                                <?php foreach ($matrixMonth['segments'] as $segment): ?>
                                    <?php
                                    $employee = $segment['employee'];
                                    $portion = (string)$segment['portion'];
                                    $groupId = (string)$segment['group_id'];
                                    $groupTitle = (string)$employee['name'] . ' · ' . (string)$segment['visual_period_label'];
                                    $isCompactGroup = (int)$segment['span'] < 4;
                                    ?>
                                    <div
                                        class="vacation-year-board-bar<?= $portion !== 'FULL' ? ' is-half is-' . strtolower($portion) : '' ?><?= $isCompactGroup ? ' is-compact-label' : '' ?>"
                                        style="--bar-start: <?= (int)$segment['start_day'] ?>; --bar-span: <?= (int)$segment['span'] ?>; --bar-track: <?= (int)$segment['track'] ?>; --employee-color: <?= h((string)$segment['color']) ?>; --employee-text-color: <?= h((string)$segment['text_color']) ?>"
                                        title="<?= h($groupTitle) ?>"
                                        data-vacation-entry
                                        data-vacation-visual-group
                                        data-visual-group-id="<?= h($groupId) ?>"
                                        data-full-name="<?= h((string)$employee['name']) ?>"
                                        data-initials="<?= h((string)$segment['initials']) ?>"
                                        data-compact-label="<?= $isCompactGroup ? '1' : '0' ?>"
                                        data-search="<?= h((string)$segment['search']) ?>"
                                        data-employee-id="<?= (int)$segment['employee_id'] ?>"
                                        <?= $isAdmin ? 'aria-hidden="true"' : 'tabindex="0"' ?>
                                    >
                                        <span data-vacation-bar-label><?= h((string)$segment['bar_label']) ?></span>
                                    </div>

                                    <?php if ($isAdmin): ?>
                                        <?php foreach ($segment['children'] as $childSegment): ?>
                                            <?php
                                            $vacation = $childSegment['vacation'];
                                            $childTitle = (string)$employee['name'] . ' · ' . (string)$childSegment['period_label'] . ' bearbeiten';
                                            ?>
                                            <button
                                                class="vacation-year-board-hitbox<?= $portion !== 'FULL' ? ' is-half is-' . strtolower($portion) : '' ?> vacation-calendar-edit"
                                                style="--bar-start: <?= (int)$childSegment['start_day'] ?>; --bar-span: <?= (int)$childSegment['span'] ?>; --bar-track: <?= (int)$segment['track'] ?>"
                                                type="button"
                                                title="<?= h($childTitle) ?>"
                                                aria-label="<?= h($childTitle) ?>"
                                                data-vacation-hitbox
                                                data-visual-group-id="<?= h($groupId) ?>"
                                                data-bs-toggle="modal"
                                                data-bs-target="#vacationEditModal"
                                                data-absence-id="<?= (int)$vacation['id'] ?>"
                                                data-employee-name="<?= h((string)$employee['name']) ?>"
                                                data-start-date="<?= h((string)$vacation['start_date']) ?>"
                                                data-end-date="<?= h((string)$vacation['end_date']) ?>"
                                                data-portion="<?= h($portion) ?>"
                                                data-note="<?= h((string)$vacation['note']) ?>"
                                            ></button>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="vacationCalendarEmpty" class="calendar-filter-empty" hidden>
            <strong>Keine passenden Mitarbeiter gefunden</strong>
            <span>Suche oder Filter zurücksetzen.</span>
        </div>
    </section>

    <?php
    $vacationAccountEmployees = $isAdmin
        ? $employees
        : (isset($employeeById[$currentUserId]) ? [$employeeById[$currentUserId]] : []);
    $visibleVacationAccounts = $isAdmin
        ? $vacationAccounts
        : ($ownVacationAccount ? [$currentUserId => $ownVacationAccount] : []);
    $visibleVacationAccountCount = 0;
    foreach ($vacationAccountEmployees as $employee) {
        if (isset($visibleVacationAccounts[(int)$employee['id']])) {
            $visibleVacationAccountCount++;
        }
    }
    ?>
    <?php if ($visibleVacationAccountCount > 0): ?>
        <section class="surface-card vacation-accounts-card<?= $isAdmin ? '' : ' vacation-accounts-card-own' ?>">
            <div class="section-heading table-heading vacation-account-heading">
                <div class="vacation-account-title">
                    <span class="vacation-account-title-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 19V5M4 19h16M8 16v-5M12 16V8M16 16v-3"/></svg>
                    </span>
                    <div>
                        <div class="eyebrow"><?= $isAdmin ? 'Kontostände' : 'Kontostand' ?> <?= (int)$year ?></div>
                        <h2><?= $isAdmin ? 'Urlaubskonten' : 'Mein Urlaubskonto' ?></h2>
                        <p><?= $isAdmin ? 'Anspruch, Übertrag, genommene und geplante Urlaubstage sowie Resturlaub auf einen Blick.' : 'Anspruch, genommene und geplante Urlaubstage sowie Ihr aktueller Resturlaub.' ?></p>
                    </div>
                </div>
                <div class="vacation-account-heading-meta">
                    <?php if ($isAdmin): ?>
                        <span class="vacation-account-count"><?= (int)$visibleVacationAccountCount ?> Konten</span>
                    <?php endif; ?>
                    <span class="vacation-account-cutoff">Übertrag verfällt am 31.03.<?= (int)$year ?></span>
                </div>
            </div>
            <div class="vacation-account-table-shell">
                <table class="table vacation-account-table align-middle mb-0">
                    <colgroup>
                        <col class="vacation-account-col-person">
                        <col class="vacation-account-col-color">
                        <col class="vacation-account-col-value">
                        <col class="vacation-account-col-value">
                        <col class="vacation-account-col-value">
                        <col class="vacation-account-col-rest">
                        <col class="vacation-account-col-value">
                        <col class="vacation-account-col-value">
                        <col class="vacation-account-col-expiry">
                        <?php if ($isAdmin): ?><col class="vacation-account-col-action"><?php endif; ?>
                    </colgroup>
                    <thead>
                    <tr>
                        <th>Mitarbeiter</th>
                        <th class="text-center">Farbe</th>
                        <th class="text-end"><span title="Jahresanspruch inklusive Korrektur, ohne Übertrag">Urlaubstage</span></th>
                        <th class="text-end">Übertrag</th>
                        <th class="text-end"><span title="Bis heute tatsächlich genommene Urlaubstage">Genommen</span></th>
                        <th><span title="Noch verfügbar nach bereits genommenem Urlaub">Rest</span></th>
                        <th class="text-end"><span title="Alle genehmigten Urlaubstage im gewählten Jahr">Geplant gesamt</span></th>
                        <th class="text-end"><span title="Genehmigte Urlaubstage ab morgen">Geplant Rest</span></th>
                        <th class="text-end"><span title="Noch verfallender oder bereits verfallener Resturlaub aus dem Vorjahr">Verfall Resturlaub</span></th>
                        <?php if ($isAdmin): ?><th class="text-end"><span class="visually-hidden">Urlaubskonto öffnen</span></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vacationAccountEmployees as $employee): ?>
                        <?php
                        $employeeId = (int)$employee['id'];
                        $account = $visibleVacationAccounts[$employeeId] ?? null;
                        if (!$account) continue;

                        $entitlement = (float)$account['entitlement_days'] + (float)$account['adjustment_days'];
                        $carryover = (float)$account['carryover_days'];
                        $taken = (float)($account['taken_days'] ?? $account['used_days']);
                        $remainingAfterTaken = (float)($account['remaining_after_taken_days'] ?? $account['remaining_days']);
                        $plannedTotal = (float)($account['planned_total_days'] ?? $account['used_days']);
                        $plannedRemaining = (float)($account['planned_remaining_days'] ?? max(0.0, $plannedTotal - $taken));
                        $remainingAfterPlanning = (float)$account['remaining_days'];
                        $carryoverExpiryValue = !empty($account['carryover_available'])
                            ? (float)($account['carryover_expiring_days'] ?? $account['carryover_remaining_days'])
                            : (float)$account['expired_carryover_days'];
                        $carryoverExpiryLabel = $carryoverExpiryValue <= 0
                            ? 'kein Übertrag'
                            : (!empty($account['carryover_available']) ? 'bis 31.03.' : 'verfallen');
                        $balanceBase = max(0.0, $entitlement + (!empty($account['carryover_available']) ? $carryover : 0.0));
                        $remainingShare = $balanceBase > 0 ? max(0.0, min(100.0, ($remainingAfterPlanning / $balanceBase) * 100)) : 0.0;
                        $employeeColor = $employeeColorById[$employeeId] ?? '#3B6FD8';
                        ?>
                        <tr style="--account-color: <?= h($employeeColor) ?>; --remaining-share: <?= h(number_format($remainingShare, 2, '.', '')) ?>%">
                            <td>
                                <span class="vacation-account-identity">
                                    <span class="vacation-account-avatar" aria-hidden="true"><?= h($employeeInitials((string)$employee['name'])) ?></span>
                                    <span class="vacation-account-person">
                                        <strong><?= h((string)$employee['name']) ?></strong>
                                        <small><?= h((string)($employee['department'] ?: 'Ohne Abteilung')) ?></small>
                                    </span>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="vacation-account-color" title="Kalenderfarbe von <?= h((string)$employee['name']) ?>" aria-label="Kalenderfarbe von <?= h((string)$employee['name']) ?>"></span>
                            </td>
                            <td class="text-end"><span class="account-metric account-entitlement"><?= h(number_format($entitlement, 1, ',', '.')) ?> T</span></td>
                            <td class="text-end"><span class="account-metric account-carryover"><?= h(number_format($carryover, 1, ',', '.')) ?> T</span></td>
                            <td class="text-end"><span class="account-metric account-taken"><?= h(number_format($taken, 1, ',', '.')) ?> T</span></td>
                            <td>
                                <span class="account-balance">
                                    <span class="account-balance-label">
                                        <strong class="account-remaining-value<?= $remainingAfterTaken <= 0 ? ' is-empty' : '' ?>"><?= h(number_format($remainingAfterTaken, 1, ',', '.')) ?> T</strong>
                                        <small>nach Planung <?= h(number_format($remainingAfterPlanning, 1, ',', '.')) ?> T</small>
                                    </span>
                                    <span class="account-balance-track" aria-hidden="true"><span></span></span>
                                </span>
                            </td>
                            <td class="text-end"><span class="account-metric account-planned-total"><?= h(number_format($plannedTotal, 1, ',', '.')) ?> T</span></td>
                            <td class="text-end"><span class="account-metric account-planned-open"><?= h(number_format($plannedRemaining, 1, ',', '.')) ?> T</span></td>
                            <td class="text-end">
                                <span class="account-expiry-value<?= $carryoverExpiryValue > 0 ? ' has-value' : '' ?>">
                                    <strong><?= h(number_format($carryoverExpiryValue, 1, ',', '.')) ?> T</strong>
                                    <small><?= h($carryoverExpiryLabel) ?></small>
                                </span>
                            </td>
                            <?php if ($isAdmin): ?>
                                <td class="text-end">
                                    <a class="vacation-account-open" href="<?= h(url('/employee?id=' . $employeeId . '&vacation_year=' . $year . '#vacation-account')) ?>" aria-label="Urlaubskonto von <?= h((string)$employee['name']) ?> öffnen" title="Urlaubskonto öffnen">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                                    </a>
                                </td>
                            <?php endif; ?>
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
                <h2><?= $isAdmin ? 'Urlaubsanträge und Änderungen' : 'Meine Urlaubsanträge und Änderungen' ?></h2>
                <p><?= $isAdmin ? 'Neue Urlaube, Verschiebungen und Löschwünsche bleiben mit Entscheidung und Bearbeitungszeitpunkt im Archiv erhalten.' : 'Hier sehen Sie den vollständigen Status Ihrer neuen Urlaubs- und Änderungsanträge.' ?></p>
            </div>
            <?php if ($isAdmin): ?>
                <div class="request-filter-tabs" role="navigation" aria-label="Antragsstatus filtern">
                    <?php foreach (['PENDING' => 'Offen', 'APPROVED' => 'Genehmigt', 'REJECTED' => 'Abgelehnt', 'ALL' => 'Alle'] as $filter => $label): ?>
                        <a class="request-filter-tab<?= $requestStatus === $filter ? ' active' : '' ?>" href="<?= h(url('/vacation-calendar?year=' . $year . '&request_status=' . $filter . '#vacationRequests')) ?>"><?= h($label) ?></a>
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
                $requestType = strtoupper((string)($request['request_type'] ?? 'CREATE'));
                $requestTypeLabel = $requestTypeLabels[$requestType] ?? 'Urlaubsantrag';
                $requestTypeClass = $requestTypeClasses[$requestType] ?? 'request-type-create';
                $newPeriodLabel = $periodLabel($request);
                $oldPeriodLabel = $originalPeriodLabel($request);
                $requestDetail = match ($requestType) {
                    'CHANGE' => $oldPeriodLabel . ' → ' . $newPeriodLabel,
                    'DELETE' => $oldPeriodLabel . ' soll gelöscht werden',
                    default => $newPeriodLabel,
                };
                ?>
                <article class="vacation-request-item <?= h($statusClass) ?>">
                    <div class="request-status-rail" aria-hidden="true"></div>
                    <div class="request-main">
                        <div class="request-title-row">
                            <div>
                                <?php if ($isAdmin): ?><strong><?= h((string)$request['employee_name']) ?></strong><?php else: ?><strong><?= h($requestTypeLabel) ?></strong><?php endif; ?>
                                <span><?= h($requestDetail) ?></span>
                            </div>
                            <span class="request-badges">
                                <span class="request-type-badge <?= h($requestTypeClass) ?>"><?= h($requestTypeLabel) ?></span>
                                <span class="request-status-badge <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                            </span>
                        </div>
                        <div class="request-meta">
                            <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg>
                                <?php if ($requestType === 'CHANGE'): ?>Neu <?= h(number_format((float)$request['requested_days'], 1, ',', '.')) ?> Tage · bisher <?= h(number_format((float)$request['original_days'], 1, ',', '.')) ?>
                                <?php elseif ($requestType === 'DELETE'): ?><?= h(number_format((float)$request['original_days'], 1, ',', '.')) ?> Urlaubstage betroffen
                                <?php else: ?><?= h(number_format((float)$request['requested_days'], 1, ',', '.')) ?> Urlaubstage<?php endif; ?>
                            </span>
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
                            data-request-type="<?= h($requestType) ?>"
                            data-request-type-label="<?= h($requestTypeLabel) ?>"
                            data-employee-name="<?= h((string)$request['employee_name']) ?>"
                            data-period="<?= h($requestDetail) ?>"
                            data-original-period="<?= h($oldPeriodLabel) ?>"
                            data-new-period="<?= h($newPeriodLabel) ?>"
                            data-days="<?= h(number_format((float)$request['requested_days'], 1, ',', '.')) ?>"
                            data-original-days="<?= h(number_format((float)$request['original_days'], 1, ',', '.')) ?>"
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
                    <a class="request-page-link<?= $page === $requestPage ? ' active' : '' ?>" href="<?= h(url('/vacation-calendar?year=' . $year . '&request_status=' . $requestStatus . '&requests_page=' . $page . '#vacationRequests')) ?>"><?= (int)$page ?></a>
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
                    <div id="vacationCalendarSelectionSummary" class="vacation-selection-summary" hidden>
                        <span class="vacation-selection-summary-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/><path d="m8 15 2 2 5-5"/></svg>
                        </span>
                        <span>
                            <strong>Im Kalender ausgewählt</strong>
                            <small id="vacationCalendarSelectionText"></small>
                        </span>
                    </div>
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
                        <div class="col-sm-6"><label class="form-label" for="vacationCreateStart">Von</label><input class="form-control" id="vacationCreateStart" name="start_date" type="date" value="<?= h($requestDefaultDate) ?>" required></div>
                        <div class="col-sm-6"><label class="form-label" for="vacationCreateEnd">Bis</label><input class="form-control" id="vacationCreateEnd" name="end_date" type="date" value="<?= h($requestDefaultDate) ?>" required></div>
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
                        <span id="vacationDecisionType" class="request-type-badge request-type-create">Neuer Urlaub</span>
                        <strong id="vacationDecisionEmployee"></strong>
                        <span id="vacationDecisionPeriod"></span>
                        <small id="vacationDecisionDays"></small>
                        <p id="vacationDecisionRequestNote" hidden></p>
                    </div>
                    <fieldset class="decision-options">
                        <legend>Entscheidung</legend>
                        <label class="decision-option decision-approve"><input type="radio" name="decision" value="APPROVED" checked><span><strong>Genehmigen</strong><small id="vacationDecisionApproveText">Der Urlaub wird sofort im Kalender eingetragen.</small></span></label>
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

<div class="modal fade" id="vacationChangeRequestModal" tabindex="-1" aria-labelledby="vacationChangeRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><div class="eyebrow">Bestehenden Urlaub</div><h2 class="modal-title" id="vacationChangeRequestModalLabel">Urlaub ändern</h2><p class="modal-subtitle">Verschiebung oder Löschung zur Genehmigung einreichen.</p></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <form id="vacationChangeRequestForm">
                <div class="modal-body">
                    <?php if (!$editableVacations): ?>
                        <div class="vacation-change-empty">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/><path d="m9 16 2 2 4-5"/></svg></span>
                            <strong>Kein änderbarer Urlaub vorhanden</strong>
                            <p>Hier erscheinen genehmigte Urlaube, die noch nicht begonnen haben.</p>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label" for="vacationChangeTarget">Welcher Urlaub soll geändert werden?</label>
                            <select class="form-select" id="vacationChangeTarget" name="target_absence_id" required>
                                <option value="">Bitte auswählen</option>
                                <?php foreach ($editableVacations as $vacation): ?>
                                    <?php
                                    $hasPendingChange = !empty($vacation['pending_change_request_id']);
                                    $vacationOptionLabel = $periodLabel($vacation)
                                        . ' · ' . number_format((float)$vacation['vacation_days'], 1, ',', '.') . ' Tage'
                                        . ($hasPendingChange ? ' · Antrag bereits offen' : '');
                                    ?>
                                    <option
                                        value="<?= (int)$vacation['id'] ?>"
                                        data-start-date="<?= h((string)$vacation['start_date']) ?>"
                                        data-end-date="<?= h((string)$vacation['end_date']) ?>"
                                        data-portion="<?= h((string)$vacation['portion']) ?>"
                                        data-period="<?= h($periodLabel($vacation)) ?>"
                                        data-days="<?= h(number_format((float)$vacation['vacation_days'], 1, ',', '.')) ?>"
                                        <?= $hasPendingChange ? 'disabled' : '' ?>
                                    ><?= h($vacationOptionLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text">Bereits begonnener oder vergangener Urlaub kann nicht mehr per Antrag geändert werden.</small>
                        </div>

                        <div id="vacationChangeCurrentSummary" class="vacation-change-current" hidden>
                            <span>Aktuell genehmigt</span>
                            <strong id="vacationChangeCurrentPeriod"></strong>
                            <small id="vacationChangeCurrentDays"></small>
                        </div>

                        <fieldset class="vacation-change-actions">
                            <legend>Was soll passieren?</legend>
                            <label class="vacation-change-action is-change">
                                <input type="radio" name="request_type" value="CHANGE" checked>
                                <span><strong>Verschieben / ändern</strong><small>Neuen Zeitraum oder Tagesumfang beantragen.</small></span>
                            </label>
                            <label class="vacation-change-action is-delete">
                                <input type="radio" name="request_type" value="DELETE">
                                <span><strong>Urlaub löschen</strong><small>Der genehmigte Urlaub wird erst nach Zustimmung entfernt.</small></span>
                            </label>
                        </fieldset>

                        <div id="vacationChangeFields" class="row g-3">
                            <div class="col-sm-6"><label class="form-label" for="vacationChangeStart">Neuer Beginn</label><input class="form-control" id="vacationChangeStart" name="start_date" type="date" min="<?= h($today) ?>" required></div>
                            <div class="col-sm-6"><label class="form-label" for="vacationChangeEnd">Neues Ende</label><input class="form-control" id="vacationChangeEnd" name="end_date" type="date" min="<?= h($today) ?>" required></div>
                            <div class="col-12"><label class="form-label" for="vacationChangePortion">Neuer Umfang</label><select class="form-select" id="vacationChangePortion" name="portion"><option value="FULL">Ganzer Tag / Zeitraum</option><option value="AM">Vormittag</option><option value="PM">Nachmittag</option></select><small class="form-text">Halbe Tage sind nur für ein einzelnes Datum möglich.</small></div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label" for="vacationChangeNote">Begründung an die Administration</label>
                            <textarea class="form-control" id="vacationChangeNote" name="note" rows="3" maxlength="300" required placeholder="Warum soll der Urlaub verschoben oder gelöscht werden?"></textarea>
                        </div>
                        <div class="vacation-balance-warning"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span>Der bestehende Urlaub bleibt unverändert, bis die Administration den Antrag genehmigt. Der Antrag bleibt anschließend dauerhaft im Verlauf sichtbar.</span></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-brand" type="submit" <?= !$editableVacations ? 'disabled' : '' ?>>Änderungsantrag senden</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
