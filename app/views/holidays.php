<?php
declare(strict_types=1);
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h1 class="h3 mb-1">Feiertage Kaufbeuren</h1>
        <div class="text-secondary">Gesetzliche Feiertage für Bayern und Kaufbeuren</div>
    </div>
    <form class="d-flex gap-2" method="get" action="<?= h(url('/holidays')) ?>">
        <select class="form-select" name="year">
            <?php for ($optionYear = 2025; $optionYear <= 2035; $optionYear++): ?>
                <option value="<?= $optionYear ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= $optionYear ?></option>
            <?php endfor; ?>
        </select>
        <button class="btn btn-primary">Anzeigen</button>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Datum</th><th>Feiertag</th></tr></thead>
            <tbody>
            <?php foreach ($holidays as $holiday): ?>
                <tr>
                    <td><?= h(date('d.m.Y', strtotime($holiday['day']))) ?></td>
                    <td><?= h($holiday['name']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$holidays): ?><tr><td colspan="2" class="text-center py-4 text-secondary">Keine Feiertage gefunden.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
