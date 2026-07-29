<?php
declare(strict_types=1);

final class SimplePdf
{
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;
    private array $pages = [];
    private string $current = '';

    public function newPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
    }

    public function text(float $x, float $top, float $size, string $text, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $y = self::HEIGHT - $top;
        $this->current .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $y, $this->escape($text));
    }

    public function textColor(float $x, float $top, float $size, string $text, float $red, float $green, float $blue, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $y = self::HEIGHT - $top;
        $red = max(0.0, min(1.0, $red));
        $green = max(0.0, min(1.0, $green));
        $blue = max(0.0, min(1.0, $blue));
        $this->current .= sprintf("%.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET 0 g\n", $red, $green, $blue, $font, $size, $x, $y, $this->escape($text));
    }

    public function line(float $x1, float $top1, float $x2, float $top2, float $width = 0.7): void
    {
        $y1 = self::HEIGHT - $top1;
        $y2 = self::HEIGHT - $top2;
        $this->current .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, $y1, $x2, $y2);
    }

    public function rect(float $x, float $top, float $width, float $height, bool $fill = false, float $gray = 0.92): void
    {
        $y = self::HEIGHT - $top - $height;
        if ($fill) {
            $this->current .= sprintf("%.2F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $y, $width, $height);
        } else {
            $this->current .= sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $y, $width, $height);
        }
    }

    public function rectColor(float $x, float $top, float $width, float $height, float $red, float $green, float $blue): void
    {
        $y = self::HEIGHT - $top - $height;
        $red = max(0.0, min(1.0, $red));
        $green = max(0.0, min(1.0, $green));
        $blue = max(0.0, min(1.0, $blue));
        $this->current .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f 0 g\n", $red, $green, $blue, $x, $y, $width, $height);
    }

    public function build(): string
    {
        if ($this->current !== '' || !$this->pages) {
            $this->pages[] = $this->current;
            $this->current = '';
        }

        $objects = [];
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $pageIds = [];
        $nextId = 5;
        foreach ($this->pages as $content) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $pageIds[] = $pageId;
            $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        }

        $kids = implode(' ', array_map(fn(int $id): string => $id . ' 0 R', $pageIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        $maxId = max(array_keys($objects));
        for ($id = 1; $id <= $maxId; $id++) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private function escape(string $text): string
    {
        $value = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) : false;
        if ($value === false) {
            $value = str_replace(['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'], ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'], $text);
        }
        $value = str_replace(["\\", '(', ')', "\r", "\n"], ["\\\\", '\\(', '\\)', ' ', ' '], $value);
        return $value;
    }
}
