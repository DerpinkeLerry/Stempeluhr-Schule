<?php
declare(strict_types=1);

final class TimeReportPdfRenderer
{
    private const NAVY = [0.082, 0.145, 0.255];
    private const NAVY_2 = [0.114, 0.192, 0.322];
    private const ORANGE = [1.000, 0.706, 0.196];
    private const TEXT = [0.090, 0.137, 0.216];
    private const MUTED = [0.408, 0.467, 0.541];
    private const LINE = [0.855, 0.882, 0.918];
    private const PAPER = [0.957, 0.965, 0.976];
    private const WHITE = [1.000, 1.000, 1.000];
    private const SUCCESS = [0.137, 0.549, 0.412];
    private const DANGER = [0.769, 0.310, 0.365];

    public function render(array $report): string
    {
        $employees = $report['employees'] ?? [];
        if (!is_array($employees) || $employees === []) {
            throw new RuntimeException('Der Zeitnachweis enthält keine Mitarbeiter');
        }

        $pdf = new SimplePdf();
        $total = count($employees);
        $type = (string)($report['type'] ?? 'week');
        foreach ($employees as $index => $employeeReport) {
            $page = $index + 1;
            match ($type) {
                'month' => $this->drawMonthReport($pdf, $report, $employeeReport, $page, $total),
                'year' => $this->drawYearReport($pdf, $report, $employeeReport, $page, $total),
                default => $this->drawWeekReport($pdf, $report, $employeeReport, $page, $total),
            };
        }
        return $pdf->build();
    }

    private function drawWeekReport(SimplePdf $pdf, array $report, array $employeeReport, int $page, int $total): void
    {
        $pdf->newPage('portrait');
        $employee = $employeeReport['employee'];
        $period = $report['period'];

        $this->drawHeader(
            $pdf,
            'Arbeitszeitnachweis',
            'Wochenübersicht',
            $employee,
            (string)$period['title'],
            (string)$period['range_label'],
            (string)$report['created_at']
        );

        $this->drawMetricCards($pdf, 35, 126, 525, 48, [
            ['label' => 'Arbeitszeit', 'value' => $this->duration((int)$employeeReport['work_seconds'])],
            ['label' => 'Pausen', 'value' => $this->duration((int)$employeeReport['break_seconds'])],
        ]);

        $left = 35.0;
        $top = 194.0;
        $headerHeight = 25.0;
        $rowHeight = 34.0;
        $widths = [36.0, 55.0, 48.0, 48.0, 52.0, 68.0, 218.0];
        $headers = ['Tag', 'Datum', 'Beginn', 'Ende', 'Pause', 'Arbeitszeit', 'Bemerkung'];
        $tableWidth = array_sum($widths);
        $days = $employeeReport['days'];

        $pdf->rectColor($left, $top, $tableWidth, $headerHeight, ...self::NAVY);
        foreach ($days as $rowIndex => $day) {
            $rowTop = $top + $headerHeight + ($rowHeight * $rowIndex);
            $fill = $this->dayRowColor($day, $rowIndex);
            $pdf->rectColor($left, $rowTop, $tableWidth, $rowHeight, ...$fill);
        }
        $this->drawGrid($pdf, $left, $top, $widths, $headerHeight, $rowHeight, count($days));
        $this->drawHeaders($pdf, $left, $top, $widths, $headers, 8.0, 17.0);

        foreach ($days as $rowIndex => $day) {
            $values = [
                (string)$day['day'],
                (string)$day['date'],
                (string)$day['start'],
                (string)$day['end'],
                $this->duration((int)$day['break_seconds']),
                $this->duration((int)$day['work_seconds']),
                $this->shortText((string)$day['note'], 40),
            ];
            $x = $left;
            $baseline = $top + $headerHeight + ($rowHeight * $rowIndex) + 21;
            foreach ($values as $column => $value) {
                $pdf->text($x + 4, $baseline, 8.2, $value === '' ? '-' : $value, false, self::TEXT);
                $x += $widths[$column];
            }
        }

        $pdf->text(35, 505, 9.2, 'Bestätigung', true, self::NAVY);
        $pdf->text(35, 523, 8.2, 'Die aufgeführten Arbeitszeiten wurden geprüft.', false, self::MUTED);
        $pdf->text(35, 554, 7.8, 'Notizen / Korrekturen', true, self::NAVY);
        $pdf->rectColor(35, 565, 525, 68, ...self::PAPER);
        $pdf->rect(35, 565, 525, 68, false, 0.92, self::LINE);
        $pdf->line(120, 675, 560, 675, 0.8, self::MUTED);
        $pdf->text(120, 691, 8.2, 'Unterschrift Arbeitnehmer', false, self::MUTED);
        $this->drawFooter($pdf, 'Stempeluhr - Wochenübersicht', $page, $total);
    }

    private function drawMonthReport(SimplePdf $pdf, array $report, array $employeeReport, int $page, int $total): void
    {
        $pdf->newPage('landscape');
        $employee = $employeeReport['employee'];
        $period = $report['period'];

        $this->drawHeader(
            $pdf,
            'Arbeitszeitnachweis',
            'Monatsübersicht',
            $employee,
            (string)$period['title'],
            (string)$period['range_label'],
            (string)$report['created_at']
        );

        $this->drawMetricCards($pdf, 35, 104, 772, 38, [
            ['label' => 'Arbeitszeit', 'value' => $this->duration((int)$employeeReport['work_seconds'])],
            ['label' => 'Pausen', 'value' => $this->duration((int)$employeeReport['break_seconds'])],
        ], 7.1, 12.0);

        $left = 35.0;
        $top = 154.0;
        $headerHeight = 19.0;
        $days = $employeeReport['days'];
        $rowHeight = min(11.65, 362.0 / max(1, count($days)));
        $widths = [27.0, 49.0, 45.0, 45.0, 50.0, 62.0, 494.0];
        $headers = ['Tag', 'Datum', 'Beginn', 'Ende', 'Pause', 'Arbeitszeit', 'Bemerkung'];
        $tableWidth = array_sum($widths);

        $pdf->rectColor($left, $top, $tableWidth, $headerHeight, ...self::NAVY);
        foreach ($days as $rowIndex => $day) {
            $rowTop = $top + $headerHeight + ($rowHeight * $rowIndex);
            $pdf->rectColor($left, $rowTop, $tableWidth, $rowHeight, ...$this->dayRowColor($day, $rowIndex));
        }
        $this->drawGrid($pdf, $left, $top, $widths, $headerHeight, $rowHeight, count($days), 0.35);
        $this->drawHeaders($pdf, $left, $top, $widths, $headers, 6.3, 13.0);

        foreach ($days as $rowIndex => $day) {
            $values = [
                (string)$day['day'],
                (string)$day['date_short'],
                (string)$day['start'],
                (string)$day['end'],
                $this->duration((int)$day['break_seconds']),
                $this->duration((int)$day['work_seconds']),
                $this->shortText((string)$day['note'], 108),
            ];
            $x = $left;
            $baseline = $top + $headerHeight + ($rowHeight * $rowIndex) + min(8.2, $rowHeight - 2.2);
            foreach ($values as $column => $value) {
                $fontSize = $column === 6 ? 6.1 : 6.25;
                $pdf->text($x + 3, $baseline, $fontSize, $value === '' ? '-' : $value, false, self::TEXT);
                $x += $widths[$column];
            }
        }

        $pdf->text(35, 546, 7.4, 'Geprüft und bestätigt:', true, self::NAVY);
        $pdf->line(150, 553, 430, 553, 0.65, self::MUTED);
        $pdf->text(150, 565, 6.7, 'Unterschrift Arbeitnehmer', false, self::MUTED);
        $pdf->line(527, 553, 807, 553, 0.65, self::MUTED);
        $pdf->text(527, 565, 6.7, 'Datum / Unterschrift Vorgesetzter', false, self::MUTED);
        $this->drawFooter($pdf, 'Stempeluhr - Monatsübersicht', $page, $total);
    }

    private function drawYearReport(SimplePdf $pdf, array $report, array $employeeReport, int $page, int $total): void
    {
        $pdf->newPage('landscape');
        $employee = $employeeReport['employee'];
        $period = $report['period'];

        $this->drawHeader(
            $pdf,
            'Arbeitszeitnachweis',
            'Jahresübersicht',
            $employee,
            (string)$period['title'],
            (string)$period['range_label'],
            (string)$report['created_at']
        );

        $this->drawMetricCards($pdf, 35, 104, 772, 42, [
            ['label' => 'Arbeitszeit im Jahr', 'value' => $this->duration((int)$employeeReport['work_seconds'])],
            ['label' => 'Pausen im Jahr', 'value' => $this->duration((int)$employeeReport['break_seconds'])],
        ], 7.0, 12.0);

        $left = 35.0;
        $top = 160.0;
        $headerHeight = 23.0;
        $rowHeight = 27.0;
        $widths = [130.0, 110.0, 90.0, 100.0, 100.0, 100.0, 142.0];
        $headers = ['Monat', 'Arbeitszeit', 'Pausen', 'Urlaubstage', 'Krankheitstage', 'Feiertage', 'Anwesenheitstage'];
        $months = $employeeReport['months'];
        $tableWidth = array_sum($widths);

        $pdf->rectColor($left, $top, $tableWidth, $headerHeight, ...self::NAVY);
        foreach ($months as $rowIndex => $month) {
            $rowTop = $top + $headerHeight + ($rowHeight * $rowIndex);
            $fill = $rowIndex % 2 === 0 ? self::WHITE : self::PAPER;
            $pdf->rectColor($left, $rowTop, $tableWidth, $rowHeight, ...$fill);
        }
        $this->drawGrid($pdf, $left, $top, $widths, $headerHeight, $rowHeight, count($months), 0.45);
        $this->drawHeaders($pdf, $left, $top, $widths, $headers, 6.7, 15.0, true);

        foreach ($months as $rowIndex => $month) {
            $values = [
                (string)$month['name'],
                $this->duration((int)$month['work_seconds']),
                $this->duration((int)$month['break_seconds']),
                $this->formatDays((float)$month['vacation_days']),
                $this->formatDays((float)$month['sick_days']),
                (string)$month['holiday_days'],
                (string)$month['presence_days'],
            ];
            $x = $left;
            $baseline = $top + $headerHeight + ($rowHeight * $rowIndex) + 17;
            foreach ($values as $column => $value) {
                if ($column === 0) {
                    $pdf->text($x + 8, $baseline, 7.4, $value, true, self::NAVY);
                } else {
                    $pdf->textCenter($x, $widths[$column], $baseline, 7.1, $value, false, self::TEXT);
                }
                $x += $widths[$column];
            }
        }

        $summaryTop = 513.0;
        $summaryValues = [
            ['label' => 'Urlaub', 'value' => $this->formatDays((float)$employeeReport['vacation_days']) . ' Tage'],
            ['label' => 'Krank', 'value' => $this->formatDays((float)$employeeReport['sick_days']) . ' Tage'],
            ['label' => 'Feiertage', 'value' => (string)$employeeReport['holiday_days'] . ' Tage'],
            ['label' => 'Anwesenheit', 'value' => (string)$employeeReport['presence_days'] . ' Tage'],
        ];
        $this->drawMetricCards($pdf, 35, $summaryTop, 772, 39, $summaryValues, 6.8, 10.5);

        $pdf->line(167, 562, 407, 562, 0.65, self::MUTED);
        $pdf->text(167, 574, 6.7, 'Unterschrift Arbeitnehmer', false, self::MUTED);
        $pdf->line(567, 562, 807, 562, 0.65, self::MUTED);
        $pdf->text(567, 574, 6.7, 'Datum / Unterschrift Vorgesetzter', false, self::MUTED);
        $this->drawFooter($pdf, 'Stempeluhr - Jahresübersicht', $page, $total);
    }

    private function drawHeader(
        SimplePdf $pdf,
        string $title,
        string $subtitle,
        array $employee,
        string $periodTitle,
        string $periodRange,
        string $createdAt
    ): void {
        $width = $pdf->pageWidth();
        $pdf->rectColor(0, 0, $width, 7, ...self::ORANGE);
        $pdf->text(35, 34, 17.0, $title, true, self::NAVY);
        $pdf->text(35, 54, 8.2, $subtitle, true, self::ORANGE);
        $nameLimit = $width > 700 ? 58 : 36;
        $pdf->text(35, 76, 11.5, $this->shortText((string)$employee['name'], $nameLimit), true, self::TEXT);

        $meta = [];
        if (!empty($employee['personnel_number'])) {
            $meta[] = 'Personalnr. ' . $employee['personnel_number'];
        }
        if (!empty($employee['department'])) {
            $meta[] = (string)$employee['department'];
        }
        if (isset($employee['weekly_hours'])) {
            $meta[] = number_format((float)$employee['weekly_hours'], 2, ',', '.') . ' Std./Woche';
        }
        $metaLimit = $width > 700 ? 96 : 58;
        $pdf->text(35, 92, 7.5, $this->shortText(implode('  |  ', $meta), $metaLimit), false, self::MUTED);

        $right = $width - 35;
        $pdf->textRight($right, 34, 12.5, $periodTitle, true, self::NAVY);
        $pdf->textRight($right, 53, 7.6, $periodRange, false, self::MUTED);
        $pdf->textRight($right, 70, 6.8, 'Erstellt: ' . $createdAt, false, self::MUTED);
    }

    private function drawMetricCards(
        SimplePdf $pdf,
        float $left,
        float $top,
        float $totalWidth,
        float $height,
        array $metrics,
        float $labelSize = 7.3,
        float $valueSize = 13.0
    ): void {
        $gap = 9.0;
        $count = max(1, count($metrics));
        $cardWidth = ($totalWidth - ($gap * ($count - 1))) / $count;
        foreach ($metrics as $index => $metric) {
            $x = $left + ($cardWidth + $gap) * $index;
            $pdf->rectColor($x, $top, $cardWidth, $height, ...self::PAPER);
            $pdf->rect($x, $top, $cardWidth, $height, false, 0.92, self::LINE);
            $pdf->rectColor($x, $top, 4, $height, ...self::ORANGE);
            $pdf->text($x + 13, $top + 15, $labelSize, (string)$metric['label'], true, self::MUTED);
            $color = !empty($metric['signed'])
                ? $this->signedColor($this->parseSignedDuration((string)$metric['value']))
                : self::NAVY;
            $pdf->text($x + 13, $top + $height - 10, $valueSize, (string)$metric['value'], true, $color);
        }
    }

    private function drawGrid(
        SimplePdf $pdf,
        float $left,
        float $top,
        array $widths,
        float $headerHeight,
        float $rowHeight,
        int $rows,
        float $lineWidth = 0.55
    ): void {
        $tableWidth = array_sum($widths);
        $tableHeight = $headerHeight + ($rowHeight * $rows);
        $pdf->rect($left, $top, $tableWidth, $tableHeight, false, 0.92, self::LINE);
        $pdf->line($left, $top + $headerHeight, $left + $tableWidth, $top + $headerHeight, $lineWidth, self::LINE);
        for ($row = 1; $row < $rows; $row++) {
            $y = $top + $headerHeight + ($rowHeight * $row);
            $pdf->line($left, $y, $left + $tableWidth, $y, $lineWidth, self::LINE);
        }
        $x = $left;
        foreach ($widths as $width) {
            $x += $width;
            $pdf->line($x, $top, $x, $top + $tableHeight, $lineWidth, self::LINE);
        }
    }

    private function drawHeaders(
        SimplePdf $pdf,
        float $left,
        float $top,
        array $widths,
        array $headers,
        float $size,
        float $baselineOffset,
        bool $center = false
    ): void {
        $x = $left;
        foreach ($headers as $index => $header) {
            if ($center && $index > 0) {
                $pdf->textCenter($x, $widths[$index], $top + $baselineOffset, $size, (string)$header, true, self::WHITE);
            } else {
                $pdf->text($x + 4, $top + $baselineOffset, $size, (string)$header, true, self::WHITE);
            }
            $x += $widths[$index];
        }
    }

    private function drawFooter(SimplePdf $pdf, string $label, int $page, int $total): void
    {
        $top = $pdf->pageHeight() - 12;
        $pdf->text(35, $top, 6.8, $label, false, self::MUTED);
        $pdf->textRight($pdf->pageWidth() - 35, $top, 6.8, 'Seite ' . $page . ' von ' . $total, false, self::MUTED);
    }

    private function dayRowColor(array $day, int $rowIndex): array
    {
        if (!empty($day['is_weekend'])) {
            return [0.945, 0.953, 0.965];
        }
        if (!empty($day['is_holiday'])) {
            return [0.914, 0.969, 0.941];
        }
        return match ($day['absence_type'] ?? null) {
            'VACATION' => [0.910, 0.953, 0.996],
            'SICK' => [1.000, 0.969, 0.835],
            'SCHOOL', 'OTHER' => [0.925, 0.969, 0.925],
            default => $rowIndex % 2 === 0 ? self::WHITE : self::PAPER,
        };
    }

    private function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private function signedDuration(int $seconds): string
    {
        return ($seconds < 0 ? '-' : '+') . $this->duration(abs($seconds));
    }

    private function signedColor(int $seconds): array
    {
        if ($seconds < 0) {
            return self::DANGER;
        }
        if ($seconds > 0) {
            return self::SUCCESS;
        }
        return self::NAVY;
    }

    private function parseSignedDuration(string $value): int
    {
        if (!preg_match('/^([+-])(\d+):(\d{2})$/', $value, $matches)) {
            return 0;
        }
        $seconds = ((int)$matches[2] * 3600) + ((int)$matches[3] * 60);
        return $matches[1] === '-' ? -$seconds : $seconds;
    }

    private function formatDays(float $days): string
    {
        $decimals = abs($days - round($days)) < 0.001 ? 0 : 1;
        return number_format($days, $decimals, ',', '.');
    }

    private function shortText(string $text, int $length): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3) . '...' : $text;
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($chars) && count($chars) > $length) {
            return implode('', array_slice($chars, 0, $length - 3)) . '...';
        }
        return $text;
    }
}
