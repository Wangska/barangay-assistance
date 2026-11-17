<?php

final class SimplePdf
{
    private const PAGE_WIDTH = 841.89;
    private const PAGE_HEIGHT = 595.28;
    private const MARGIN = 32.0;

    /** @var list<string> */
    private array $streams = [];
    private string $ops = '';
    private float $y = 0;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->ops !== '') {
            $this->streams[] = $this->ops;
        }
        $this->ops = '';
        $this->y = self::PAGE_HEIGHT - self::MARGIN;
    }

    public function cursor(): float
    {
        return $this->y;
    }

    public function ensure(float $height): void
    {
        if ($this->y - $height < self::MARGIN + 18) {
            $this->addPage();
        }
    }

    public function advance(float $height): void
    {
        $this->y -= $height;
    }

    public function text(float $x, float $size, string $text, bool $bold = false): void
    {
        $this->textAt($x, $this->y, $size, $text, $bold);
    }

    public function textAt(float $x, float $y, float $size, string $text, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->ops .= sprintf(
            "BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font,
            $size,
            $x,
            $y - $size,
            self::escape($text)
        );
    }

    public function rule(float $x1, float $x2): void
    {
        $this->ops .= sprintf("0.75 0.82 0.80 RG %.2F %.2F m %.2F %.2F l S\n", $x1, $this->y, $x2, $this->y);
    }

    public function output(): string
    {
        if ($this->ops !== '') {
            $this->streams[] = $this->ops;
            $this->ops = '';
        }
        $total = count($this->streams);
        foreach ($this->streams as $index => $stream) {
            $this->streams[$index] = $stream . sprintf(
                "BT /F1 8 Tf 1 0 0 1 %.2F 18 Tm (Page %d of %d) Tj ET\n",
                self::MARGIN,
                $index + 1,
                $total
            );
        }

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        $nextId = 5;
        foreach ($this->streams as $unused) {
            $pageIds[] = $nextId;
            $nextId += 2;
        }
        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds));
        $objects[] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        foreach ($this->streams as $index => $stream) {
            $pageId = $pageIds[$index];
            $contentId = $pageId + 1;
            $objects[$pageId - 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Contents ' . $contentId . ' 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>';
            $objects[$contentId - 1] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $body) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    public static function wrap(string $text, float $width, float $size): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $text)));
        if ($text === '') {
            return ['—'];
        }
        $max = max(4, (int) floor($width / ($size * 0.48)));
        $length = static fn (string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        $slice = static fn (string $value, int $start, int $count): string => function_exists('mb_substr') ? mb_substr($value, $start, $count, 'UTF-8') : substr($value, $start, $count);
        $lines = [];
        foreach (explode(' ', $text) as $word) {
            while ($length($word) > $max) {
                $lines[] = $slice($word, 0, $max);
                $word = $slice($word, $max, $length($word) - $max);
            }
            $last = count($lines) - 1;
            if ($last < 0 || $length($lines[$last] . ' ' . $word) > $max) {
                $lines[] = $word;
            } else {
                $lines[$last] .= ' ' . $word;
            }
        }
        return $lines === [] ? ['—'] : $lines;
    }

    private static function escape(string $text): string
    {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }
}
