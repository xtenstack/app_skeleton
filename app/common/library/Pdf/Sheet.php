<?php

declare(strict_types=1);

namespace App_skeleton\Pdf;

/**
 * The FPDF object behind a Document. Internal: nothing outside
 * App_skeleton\Pdf may use it, and no method of the `pdf` service or of a
 * document returns it.
 *
 * It adds two things to FPDF. draw() puts a line of mixed code pages on
 * the page, switching to each run's font (and embedding a font the first
 * time it is needed, so a document carries only the fonts it uses). And
 * Footer() repeats a caller's text and / or "Page n of N". FPDF's
 * automatic page break is off; Document decides where a page ends, and
 * Typesetter does all measuring, so FPDF's own Cell() / MultiCell()
 * layout is not used.
 */
final class Sheet extends \FPDF
{
    /** UTF-8. */
    public string $footerText = '';

    public bool $pageNumbers = false;

    public function __construct(string $orientation, string $size)
    {
        parent::__construct($orientation, 'mm', $size);

        // The embedded DejaVu subsets, not FPDF's built-in fonts.
        $this->fontpath = Fonts::DIR;
    }

    /**
     * Draws one line (runs of [code page, bytes], as Typesetter returns
     * them) from $x, in a row $height millimetres tall whose top is $y.
     *
     * @param array<int, array{0: string, 1: string}> $line
     */
    public function draw(array $line, float $x, float $y, float $height, string $style, float $points): void
    {
        // Where FPDF's own Cell() puts the baseline.
        $baseline = $y + 0.5 * $height + 0.3 * $points * Typesetter::MM_PER_PT;

        foreach ($line as [$page, $bytes]) {
            if ($bytes === '') {
                continue;
            }

            $this->useFont($page, $style, $points);
            $this->Text($x, $baseline, $bytes);

            $x += Typesetter::width([[$page, $bytes]], $style, $points);
        }
    }

    public function Footer(): void
    {
        if ($this->footerText === '' && !$this->pageNumbers) {
            return;
        }

        $points = 8.0;
        $height = Typesetter::lineHeight($points);
        $width  = $this->w - $this->lMargin - $this->rMargin;
        // Part of the way into the bottom margin.
        $y = $this->h - $this->bMargin + min(8.0, $this->bMargin * 0.4);

        $this->SetTextColor(110);

        $numberWidth = 0.0;

        if ($this->pageNumbers) {
            // FPDF fills in {nb} when the document is finished; two digits are allowed for when lining it up.
            $numberWidth = Typesetter::width(Text::runs('Page ' . $this->PageNo() . ' of 00'), Fonts::REGULAR, $points);

            $this->draw(Text::runs('Page ' . $this->PageNo() . ' of {nb}'), $this->lMargin + $width - $numberWidth, $y, $height, Fonts::REGULAR, $points);
        }

        if ($this->footerText !== '') {
            $this->draw(Typesetter::fit($this->footerText, $width - $numberWidth - 4.0, Fonts::REGULAR, $points), $this->lMargin, $y, $height, Fonts::REGULAR, $points);
        }

        $this->SetTextColor(0);
    }

    /** Selects the font for a code page, embedding it in the document the first time it is used. */
    private function useFont(string $codePage, string $style, float $points): void
    {
        $family = Fonts::family($codePage);

        if (!isset($this->fonts[$family . $style])) {
            $this->AddFont($family, $style, Fonts::file($codePage, $style));
        }

        $this->SetFont($family, $style, $points);
    }
}
