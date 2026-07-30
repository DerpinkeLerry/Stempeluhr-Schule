<?php
declare(strict_types=1);
$monthLabels = [1 => 'JAN', 2 => 'FEB', 3 => 'MÄR', 4 => 'APR', 5 => 'MAI', 6 => 'JUN', 7 => 'JUL', 8 => 'AUG', 9 => 'SEP', 10 => 'OKT', 11 => 'NOV', 12 => 'DEZ'];
?>
<section class="page-hero holidays-hero">
    <div class="page-hero-copy">
        <div class="eyebrow">Kalender</div>
        <h1>Feiertage in Kaufbeuren</h1>
        <p>Gesetzliche Feiertage für Bayern und die Stadt Kaufbeuren auf einen Blick.</p>
    </div>
    <form class="year-filter" method="get" action="<?= h(url('/holidays')) ?>">
        <label for="holidayYear">Kalenderjahr</label>
        <div class="year-filter-controls">
            <select class="form-select" id="holidayYear" name="year">
                <?php for ($optionYear = 2025; $optionYear <= 2035; $optionYear++): ?>
                    <option value="<?= $optionYear ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= $optionYear ?></option>
                <?php endfor; ?>
            </select>
            <button class="btn btn-brand" type="submit">
                Anzeigen
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>
    </form>
</section>

<div class="holiday-summary">
    <span class="holiday-summary-icon">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg>
    </span>
    <div>
        <strong><?= count($holidays) ?> Feiertage</strong>
        <span>Region DE-BY-KF · Kalenderjahr <?= $year ?></span>
    </div>
</div>

<section class="surface-card holiday-card">
    <div class="section-heading table-heading">
        <div>
            <div class="eyebrow">Jahresübersicht</div>
            <h2>Feiertagskalender <?= $year ?></h2>
            <p>Diese Tage werden in der Zeiterfassung automatisch berücksichtigt.</p>
        </div>
        <span class="section-icon section-icon-accent">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 8.4l6.1-.9L12 2Z"/></svg>
        </span>
    </div>
    <div class="holiday-list">
        <?php foreach ($holidays as $holiday): ?>
            <?php $timestamp = strtotime($holiday['day']); ?>
            <article class="holiday-item">
                <time datetime="<?= h($holiday['day']) ?>" class="holiday-date">
                    <strong><?= h(date('d', $timestamp)) ?></strong>
                    <span><?= h($monthLabels[(int)date('n', $timestamp)] ?? strtoupper(date('M', $timestamp))) ?></span>
                </time>
                <div class="holiday-name">
                    <strong><?= h($holiday['name']) ?></strong>
                    <span><?= h(date('d.m.Y', $timestamp)) ?></span>
                </div>
                <span class="holiday-dayname"><?= h(['Sunday' => 'Sonntag', 'Monday' => 'Montag', 'Tuesday' => 'Dienstag', 'Wednesday' => 'Mittwoch', 'Thursday' => 'Donnerstag', 'Friday' => 'Freitag', 'Saturday' => 'Samstag'][date('l', $timestamp)] ?? date('l', $timestamp)) ?></span>
            </article>
        <?php endforeach; ?>
        <?php if (!$holidays): ?>
            <div class="empty-panel">
                <strong>Keine Feiertage gefunden</strong>
                <p>Für das gewählte Jahr liegen keine Einträge vor.</p>
            </div>
        <?php endif; ?>
    </div>
</section>
