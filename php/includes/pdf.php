<?php
/**
 * Minimal, dependency-free PDF writer — just enough for certificates.
 * Landscape A4, Helvetica + Helvetica-Bold, centered text, lines/rectangles.
 * No Composer, no FPDF bundle — a single small class.
 *
 * PDF uses a bottom-left origin; this wrapper flips Y so callers use a
 * natural top-down coordinate system (y = distance from the top).
 */

declare(strict_types=1);

class MiniPDF
{
    private float $w;          // page width (pt)
    private float $h;          // page height (pt)
    private array $content = [];
    private string $font = 'F1';
    private float $size = 12;
    private array $rgb = [0, 0, 0];

    // Helvetica advance widths (1/1000 em) for ASCII 32..126. Used for
    // horizontal centering. Bold is slightly wider but close enough here.
    private const HW = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584
    ];

    /** A4 landscape by default (842 x 595 pt). */
    public function __construct(float $w = 842, float $h = 595)
    {
        $this->w = $w; $this->h = $h;
    }

    public function width(): float { return $this->w; }
    public function height(): float { return $this->h; }

    public function setFont(bool $bold, float $size): void
    {
        $this->font = $bold ? 'F2' : 'F1';
        $this->size = $size;
    }

    public function setColor(int $r, int $g, int $b): void
    {
        $this->rgb = [$r/255, $g/255, $b/255];
        $this->content[] = sprintf('%.3f %.3f %.3f rg', $r/255, $g/255, $b/255);
        $this->content[] = sprintf('%.3f %.3f %.3f RG', $r/255, $g/255, $b/255);
    }

    private function esc(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /** Text width in points for the current font size. */
    public function textWidth(string $s, ?float $size = null): float
    {
        $size = $size ?? $this->size;
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($s[$i]);
            $w += ($o >= 32 && $o <= 126) ? self::HW[$o - 32] : 556;
        }
        return $w * $size / 1000;
    }

    /** Draw text at (x, yTop). */
    public function text(float $x, float $yTop, string $s): void
    {
        $y = $this->h - $yTop;
        $this->content[] = 'BT /' . $this->font . ' ' . $this->size . ' Tf '
            . sprintf('%.2f %.2f Td (%s) Tj ET', $x, $y, $this->esc($s));
    }

    /** Draw text horizontally centered on the page at yTop. */
    public function textCenter(float $yTop, string $s): void
    {
        $x = ($this->w - $this->textWidth($s)) / 2;
        $this->text($x, $yTop, $s);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $lw = 1): void
    {
        $this->content[] = sprintf('%.2f w %.2f %.2f m %.2f %.2f l S',
            $lw, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    public function rect(float $x, float $yTop, float $w, float $h, float $lw = 1): void
    {
        $this->content[] = sprintf('%.2f w %.2f %.2f %.2f %.2f re S',
            $lw, $x, $this->h - $yTop - $h, $w, $h);
    }

    /** Build and return the raw PDF bytes. */
    public function output(): string
    {
        $stream = implode("\n", $this->content);
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->w} {$this->h}] "
                 . "/Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>";
        $objs[4] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $count = count($objs) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
        return $pdf;
    }
}
