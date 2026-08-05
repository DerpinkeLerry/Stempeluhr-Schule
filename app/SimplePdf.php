<?php
declare(strict_types=1);

final class SimplePdf
{
    private const PORTRAIT_WIDTH = 595.28;
    private const PORTRAIT_HEIGHT = 841.89;

    /** @var list<array{content:string,width:float,height:float}> */
    private array $pages = [];
    private string $current = '';
    private float $width = self::PORTRAIT_WIDTH;
    private float $height = self::PORTRAIT_HEIGHT;

    public function newPage(string $orientation = 'portrait'): void
    {
        $this->flushCurrentPage();
        $orientation = strtolower(trim($orientation));
        if ($orientation === 'landscape') {
            $this->width = self::PORTRAIT_HEIGHT;
            $this->height = self::PORTRAIT_WIDTH;
            return;
        }
        $this->width = self::PORTRAIT_WIDTH;
        $this->height = self::PORTRAIT_HEIGHT;
    }

    public function pageWidth(): float
    {
        return $this->width;
    }

    public function pageHeight(): float
    {
        return $this->height;
    }

    public function text(
        float $x,
        float $top,
        float $size,
        string $text,
        bool $bold = false,
        ?array $color = null
    ): void {
        $font = $bold ? 'F2' : 'F1';
        $y = $this->height - $top;
        $prefix = $color === null ? '' : $this->colorCommand($color, 'rg');
        $suffix = $color === null ? '' : "0 g\n";
        $this->current .= $prefix
            . sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $y, $this->escape($text))
            . $suffix;
    }

    public function textRight(
        float $right,
        float $top,
        float $size,
        string $text,
        bool $bold = false,
        ?array $color = null
    ): void {
        $this->text(max(0, $right - $this->estimateTextWidth($text, $size, $bold)), $top, $size, $text, $bold, $color);
    }

    public function textCenter(
        float $left,
        float $width,
        float $top,
        float $size,
        string $text,
        bool $bold = false,
        ?array $color = null
    ): void {
        $textWidth = $this->estimateTextWidth($text, $size, $bold);
        $this->text($left + max(0, ($width - $textWidth) / 2), $top, $size, $text, $bold, $color);
    }

    public function line(
        float $x1,
        float $top1,
        float $x2,
        float $top2,
        float $width = 0.7,
        ?array $color = null
    ): void {
        $y1 = $this->height - $top1;
        $y2 = $this->height - $top2;
        $prefix = $color === null ? '' : $this->colorCommand($color, 'RG');
        $suffix = $color === null ? '' : "0 G\n";
        $this->current .= $prefix
            . sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, $y1, $x2, $y2)
            . $suffix;
    }

    public function rect(
        float $x,
        float $top,
        float $width,
        float $height,
        bool $fill = false,
        float $gray = 0.92,
        ?array $strokeColor = null
    ): void {
        $y = $this->height - $top - $height;
        if ($fill) {
            $this->current .= sprintf("%.2F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $y, $width, $height);
            return;
        }
        $prefix = $strokeColor === null ? '' : $this->colorCommand($strokeColor, 'RG');
        $suffix = $strokeColor === null ? '' : "0 G\n";
        $this->current .= $prefix . sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $y, $width, $height) . $suffix;
    }

    public function rectColor(float $x, float $top, float $width, float $height, float $red, float $green, float $blue): void
    {
        $y = $this->height - $top - $height;
        $red = max(0.0, min(1.0, $red));
        $green = max(0.0, min(1.0, $green));
        $blue = max(0.0, min(1.0, $blue));
        $this->current .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f 0 g\n", $red, $green, $blue, $x, $y, $width, $height);
    }

    public function build(): string
    {
        $this->flushCurrentPage(true);

        $objects = [];
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $pageIds = [];
        $nextId = 5;
        foreach ($this->pages as $page) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $pageIds[] = $pageId;
            $content = $page['content'];
            $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $page['width'],
                $page['height'],
                $contentId
            );
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

    private function flushCurrentPage(bool $force = false): void
    {
        if ($this->current === '' && !$force) {
            return;
        }
        if ($this->current === '' && $this->pages !== []) {
            return;
        }
        $this->pages[] = [
            'content' => $this->current,
            'width' => $this->width,
            'height' => $this->height,
        ];
        $this->current = '';
    }

    private function colorCommand(array $color, string $operator): string
    {
        $red = max(0.0, min(1.0, (float)($color[0] ?? 0.0)));
        $green = max(0.0, min(1.0, (float)($color[1] ?? 0.0)));
        $blue = max(0.0, min(1.0, (float)($color[2] ?? 0.0)));
        return sprintf("%.3F %.3F %.3F %s\n", $red, $green, $blue, $operator);
    }

    private function estimateTextWidth(string $text, float $size, bool $bold): float
    {
        $encoded = $this->encode($text);
        $factor = $bold ? 0.56 : 0.52;
        return strlen($encoded) * $size * $factor;
    }

    private function escape(string $text): string
    {
        $value = $this->encode($text);
        return str_replace(["\\", '(', ')', "\r", "\n"], ["\\\\", '\\(', '\\)', ' ', ' '], $value);
    }

    private function encode(string $text): string
    {
        $value = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) : false;
        if ($value === false) {
            $value = str_replace(['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'], ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'], $text);
        }
        return $value;
    }
}
