<?php
declare(strict_types=1);

namespace App_skeleton\Pdf;

/**
 * One PDF document being built, top to bottom. Get one from the `pdf`
 * service's document() (App_skeleton\Pdf), never with `new`: the
 * constructor is not part of the interface (docs/MODULE-SPEC.md, "PDF
 * documents").
 *
 * Every method but output() returns the document, so calls chain.
 * Lengths are millimetres. Every string is plain UTF-8. Western and
 * Central European Latin, Greek, Cyrillic, Turkish and Baltic text is
 * drawn as it is (Text::runs() splits it by code page and the matching
 * DejaVu Sans subset is embedded); anything else becomes a plain
 * equivalent or "?". The document never throws over a character.
 *
 * Page breaks are the document's job: text flows onto a new page, a
 * table repeats its header on each page and never splits a row, and a
 * totals block or a set of side-by-side blocks is kept on one page.
 * Every block is measured before it is drawn (Typesetter gives the
 * lines), and room() starts a new page when the block would not fit.
 * FPDF's automatic page break and its own text layout are not used.
 *
 * Options arrays refuse unknown keys (PdfException), so a misspelt
 * option is not silently ignored. After output() the document is
 * finished and every further call throws PdfException. The FPDF object
 * is private and no method returns it.
 */
final class Document
{
    private const SIZES = ['A3' => 'A3', 'A4' => 'A4', 'A5' => 'A5', 'LETTER' => 'Letter', 'LEGAL' => 'Legal'];

    private const DOCUMENT_OPTIONS = ['title', 'author', 'subject', 'size', 'orientation', 'margins', 'fontSize', 'footer', 'pageNumbers', 'compress'];

    private const ALIGN = ['left' => 'L', 'center' => 'C', 'centre' => 'C', 'right' => 'R'];

    private const STYLES = ['normal' => Fonts::REGULAR, 'bold' => Fonts::BOLD];

    private const CELL_PAD_X = 2.2;
    private const CELL_PAD_Y = 1.3;

    private Sheet $sheet;

    private bool $finished = false;

    /** Body text, in points. */
    private float $fontSize;

    private float $top;
    private float $left;
    private float $width;
    private float $bottom;

    /**
     * @param array<string, mixed> $options App_skeleton\Pdf::document()
     */
    public function __construct(array $options = [])
    {
        self::refuseUnknown($options, self::DOCUMENT_OPTIONS, 'document');

        $size = self::SIZES[strtoupper((string) ($options['size'] ?? 'A4'))] ?? null;

        if ($size === null) {
            throw new PdfException("Unknown page size '" . self::printable($options['size']) . "'; use A3, A4, A5, Letter or Legal");
        }

        $orientation = strtolower((string) ($options['orientation'] ?? 'portrait'));

        if (!in_array($orientation, ['portrait', 'landscape'], true)) {
            throw new PdfException("Unknown orientation '" . self::printable($options['orientation']) . "'; use portrait or landscape");
        }

        $fontSize = $options['fontSize'] ?? 10;

        if (!is_int($fontSize) && !is_float($fontSize) || $fontSize < 6 || $fontSize > 16) {
            throw new PdfException('fontSize must be a number of points from 6 to 16');
        }

        $margins = self::margins($options['margins'] ?? null);

        foreach (['title', 'author', 'subject', 'footer'] as $key) {
            if (isset($options[$key]) && !is_string($options[$key])) {
                throw new PdfException("{$key} must be a string");
            }
        }

        try {
            $sheet = new Sheet($orientation === 'landscape' ? 'L' : 'P', $size);
        } catch (\Throwable $e) {
            throw new PdfException('The PDF library could not start a document', 0, $e);
        }

        if ($margins['left'] + $margins['right'] > $sheet->GetPageWidth() - 40 || $margins['top'] + $margins['bottom'] > $sheet->GetPageHeight() - 40) {
            throw new PdfException('The margins leave no room on the page');
        }

        $this->sheet    = $sheet;
        $this->fontSize = (float) $fontSize;
        $this->top      = $margins['top'];
        $this->left     = $margins['left'];
        $this->width    = $sheet->GetPageWidth() - $margins['left'] - $margins['right'];
        $this->bottom   = $sheet->GetPageHeight() - $margins['bottom'];

        $this->guard(function () use ($sheet, $options, $margins): void {
            $sheet->SetCompression((bool) ($options['compress'] ?? true));
            $sheet->SetMargins($margins['left'], $margins['top'], $margins['right']);
            $sheet->SetAutoPageBreak(false, $margins['bottom']);
            $sheet->AliasNbPages();
            $sheet->SetCreator('app_skeleton pdf service', true);

            // Metadata is UTF-8 (FPDF stores it as UTF-16); only page text goes through the code pages.
            foreach (['title' => 'SetTitle', 'author' => 'SetAuthor', 'subject' => 'SetSubject'] as $key => $setter) {
                if (isset($options[$key]) && $options[$key] !== '') {
                    $sheet->$setter(self::metadata($options[$key]), true);
                }
            }

            $sheet->footerText  = (string) ($options['footer'] ?? '');
            $sheet->pageNumbers = (bool) ($options['pageNumbers'] ?? false);

            $sheet->SetDrawColor(60);
            $sheet->SetTextColor(0);
            $sheet->AddPage();
        });
    }

    /**
     * A heading. It is kept on the same page as the line that follows it.
     *
     * @param int                  $level   1 (largest) to 3
     * @param array<string, mixed> $options align ('left' default, 'center', 'right')
     */
    public function heading(string $text, int $level = 1, array $options = []): static
    {
        return $this->guard(function () use ($text, $level, $options): static {
            self::refuseUnknown($options, ['align'], 'heading');

            if ($level < 1 || $level > 3) {
                throw new PdfException('A heading level is 1, 2 or 3');
            }

            $size   = $this->fontSize + [1 => 10.0, 2 => 4.0, 3 => 1.0][$level];
            $lines  = Typesetter::lines($text, $this->width, Fonts::BOLD, $size);
            $height = Typesetter::lineHeight($size);

            // Kept with the line that follows it.
            $this->room(count($lines) * $height + Typesetter::lineHeight($this->fontSize) + 2.0);
            $this->lines($lines, $this->left, $this->width, $height, self::align($options), Fonts::BOLD, $size);
            $this->sheet->SetY($this->sheet->GetY() + [1 => 1.5, 2 => 1.0, 3 => 0.5][$level]);

            return $this;
        });
    }

    /**
     * Text that wraps to the page width and flows over page breaks; a line
     * break in $text is kept.
     *
     * @param array<string, mixed> $options align ('left' default, 'center', 'right'),
     *                                      style ('normal' default, 'bold'),
     *                                      size ('normal' default, 'small', 'large'),
     *                                      muted (bool, default false: grey)
     */
    public function paragraph(string $text, array $options = []): static
    {
        return $this->guard(function () use ($text, $options): static {
            self::refuseUnknown($options, ['align', 'style', 'size', 'muted'], 'paragraph');

            $style = self::STYLES[(string) ($options['style'] ?? 'normal')] ?? null;

            if ($style === null) {
                throw new PdfException("Unknown style '" . self::printable($options['style']) . "'; use normal or bold");
            }

            $size   = $this->textSize($options, ['small', 'normal', 'large']);
            $height = Typesetter::lineHeight($size);

            if (!empty($options['muted'])) {
                $this->sheet->SetTextColor(95);
            }

            // One line at a time, so a long paragraph flows over a page break.
            foreach (Typesetter::lines($text, $this->width, $style, $size) as $line) {
                $this->room($height);
                $this->lines([$line], $this->left, $this->width, $height, self::align($options), $style, $size);
            }

            $this->sheet->SetTextColor(0);

            return $this;
        });
    }

    /**
     * One or more blocks of label / value lines: a seller and a customer
     * side by side, or one block of details.
     *
     * @param array<int, array<string, mixed>> $blocks each block has any of:
     *   title (string: a small caption above the block),
     *   lead  (string: a first line in bold, e.g. a name),
     *   rows  (list of [label, value]; an empty label lets the value use the block's full width;
     *          values wrap, and a line break in a value is kept)
     * @param array<string, mixed> $options layout ('columns' default: the blocks side by side in equal
     *                                      columns, kept on one page; 'stacked': one under another),
     *                                      labelWidth (millimetres; default: as wide as the widest label,
     *                                      at most 40% of the block)
     */
    public function keyValues(array $blocks, array $options = []): static
    {
        return $this->guard(function () use ($blocks, $options): static {
            self::refuseUnknown($options, ['layout', 'labelWidth'], 'keyValues');

            $layout = (string) ($options['layout'] ?? 'columns');

            if (!in_array($layout, ['columns', 'stacked'], true)) {
                throw new PdfException("Unknown layout '" . self::printable($options['layout'] ?? null) . "'; use columns or stacked");
            }

            $labelWidth = $options['labelWidth'] ?? null;

            if ($labelWidth !== null && (!is_int($labelWidth) && !is_float($labelWidth) || $labelWidth < 0)) {
                throw new PdfException('labelWidth must be a number of millimetres');
            }

            $blocks = array_values($blocks);

            if ($blocks === []) {
                return $this;
            }

            $gap        = 8.0;
            $columns    = $layout === 'columns' ? count($blocks) : 1;
            $blockWidth = ($this->width - $gap * ($columns - 1)) / $columns;

            if ($blockWidth < 20) {
                throw new PdfException('Too many blocks to set side by side; use the stacked layout');
            }

            $prepared = [];

            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    throw new PdfException('A keyValues block is an array with title, lead and rows');
                }

                self::refuseUnknown($block, ['title', 'lead', 'rows'], 'keyValues block');

                $prepared[] = $this->prepareBlock($block, $blockWidth, $labelWidth !== null ? (float) $labelWidth : null);
            }

            if ($layout === 'stacked') {
                foreach ($prepared as $i => $block) {
                    if ($i > 0) {
                        $this->spacer(3.0);
                    }

                    $this->room($block['height']);
                    $this->drawBlock($block, $this->left, $this->sheet->GetY());
                    $this->sheet->SetXY($this->left, $this->sheet->GetY());
                }

                return $this;
            }

            $height = max(array_column($prepared, 'height'));

            $this->room($height);

            $y = $this->sheet->GetY();

            foreach ($prepared as $i => $block) {
                $this->drawBlock($block, $this->left + $i * ($blockWidth + $gap), $y);
            }

            $this->sheet->SetXY($this->left, $y + $height);

            return $this;
        });
    }

    /**
     * A table. Cells wrap inside their column; a row is never split across
     * two pages; the header row is repeated at the top of each page the
     * table runs onto.
     *
     * @param array<int, array<string, mixed>> $columns each column has:
     *   label (string, the header cell),
     *   width (millimetres; omit it, or pass null, to share what is left equally),
     *   align ('left' default, 'right' for money, 'center')
     * @param array<int, array<int, mixed>> $rows one list per row, one value (text or a number) per column
     * @param array<string, mixed> $options repeatHeader (bool, default true),
     *                                      size ('normal' default, 'small')
     *
     * @throws PdfException when there is no column, or a row does not have one value per column
     */
    public function table(array $columns, array $rows, array $options = []): static
    {
        return $this->guard(function () use ($columns, $rows, $options): static {
            self::refuseUnknown($options, ['repeatHeader', 'size'], 'table');

            $size         = $this->textSize($options, ['small', 'normal']);
            $height       = Typesetter::lineHeight($size);
            $repeatHeader = (bool) ($options['repeatHeader'] ?? true);
            $columns      = self::prepareColumns($columns, $this->width);
            $count        = count($columns);

            // The header, measured in bold.
            $header       = [];
            $headerHeight = 0.0;

            foreach ($columns as $column) {
                $lines        = Typesetter::lines($column['label'], $column['width'] - 2 * self::CELL_PAD_X, Fonts::BOLD, $size);
                $header[]     = $lines;
                $headerHeight = max($headerHeight, count($lines) * $height + 2 * self::CELL_PAD_Y);
            }

            // A row can be no taller than a page that also carries the header.
            $maxLines = max(1, (int) floor(($this->bottom - $this->top - $headerHeight - 2 * self::CELL_PAD_Y) / $height));
            $prepared = [];

            foreach (array_values($rows) as $n => $row) {
                if (!is_array($row) || count($row) !== $count) {
                    throw new PdfException('Table row ' . ($n + 1) . " must have {$count} values, one per column");
                }

                $cells     = [];
                $rowHeight = 0.0;

                foreach (array_values($row) as $i => $value) {
                    if ($value !== null && !is_scalar($value)) {
                        throw new PdfException('Table row ' . ($n + 1) . ', column ' . ($i + 1) . ' must be text or a number');
                    }

                    $lines = Typesetter::lines((string) $value, $columns[$i]['width'] - 2 * self::CELL_PAD_X, Fonts::REGULAR, $size);

                    // A cell with more text than a page holds is cut there, and says so.
                    if (count($lines) > $maxLines) {
                        $lines                = array_slice($lines, 0, $maxLines);
                        $lines[$maxLines - 1] = Text::runs('...');
                    }

                    $cells[]   = $lines;
                    $rowHeight = max($rowHeight, count($lines) * $height + 2 * self::CELL_PAD_Y);
                }

                $prepared[] = ['cells' => $cells, 'height' => $rowHeight];
            }

            // The header never sits alone at the foot of a page.
            $this->room($headerHeight + ($prepared[0]['height'] ?? 0.0));
            $this->tableRow($columns, $header, $headerHeight, $height, Fonts::BOLD, $size, 0.45);

            foreach ($prepared as $row) {
                if ($this->room($row['height']) && $repeatHeader) {
                    $this->tableRow($columns, $header, $headerHeight, $height, Fonts::BOLD, $size, 0.45);
                }

                $this->tableRow($columns, $row['cells'], $row['height'], $height, Fonts::REGULAR, $size, 0.12);
            }

            $this->sheet->SetLineWidth(0.2);

            return $this;
        });
    }

    /**
     * A block of label / amount lines at the right of the page (subtotal,
     * tax, total), kept together on one page.
     *
     * @param array<int, array<int, mixed>> $rows [label, value] or [label, value, true];
     *                                             true draws the row in bold under a rule (the total)
     * @param array<string, mixed> $options width (millimetres, the block's width; default 80)
     */
    public function totals(array $rows, array $options = []): static
    {
        return $this->guard(function () use ($rows, $options): static {
            self::refuseUnknown($options, ['width'], 'totals');

            $blockWidth = $options['width'] ?? 80;

            if (!is_int($blockWidth) && !is_float($blockWidth) || $blockWidth < 30) {
                throw new PdfException('The totals width must be a number of millimetres, 30 or more');
            }

            $blockWidth = min((float) $blockWidth, $this->width);
            $prepared   = [];
            $valueWidth = 24.0;

            foreach (array_values($rows) as $n => $row) {
                if (!is_array($row) || count($row) < 2) {
                    throw new PdfException('Totals row ' . ($n + 1) . ' must be [label, value] or [label, value, true]');
                }

                $row = array_values($row);

                if (!is_scalar($row[0] ?? '') || !is_scalar($row[1] ?? '')) {
                    throw new PdfException('Totals row ' . ($n + 1) . ' must hold text');
                }

                $strong = (bool) ($row[2] ?? false);
                $style  = $strong ? Fonts::BOLD : Fonts::REGULAR;
                $size   = $strong ? $this->fontSize + 1.5 : $this->fontSize;

                $valueWidth = max($valueWidth, Typesetter::width(Text::runs(str_replace("\n", ' ', (string) $row[1])), $style, $size) + 2 * self::CELL_PAD_X);
                $prepared[] = ['label' => (string) $row[0], 'value' => (string) $row[1], 'strong' => $strong, 'style' => $style, 'size' => $size];
            }

            $valueWidth = min($valueWidth, $blockWidth * 0.6);
            $labelWidth = $blockWidth - $valueWidth;
            $total      = 1.5;

            foreach ($prepared as $i => $row) {
                $prepared[$i]['lines']  = Typesetter::lines($row['label'], $labelWidth - 2 * self::CELL_PAD_X, $row['style'], $row['size']);
                $prepared[$i]['amount'] = Typesetter::fit($row['value'], $valueWidth - 2 * self::CELL_PAD_X, $row['style'], $row['size']);
                $prepared[$i]['height'] = count($prepared[$i]['lines']) * Typesetter::lineHeight($row['size']) + 2 * self::CELL_PAD_Y;

                $total += $prepared[$i]['height'];
            }

            // The whole block on one page.
            $this->room($total);
            $this->sheet->SetY($this->sheet->GetY() + 1.5);

            $x = $this->left + $this->width - $blockWidth;

            foreach ($prepared as $row) {
                $y          = $this->sheet->GetY();
                $lineHeight = Typesetter::lineHeight($row['size']);

                if ($row['strong']) {
                    $this->sheet->SetLineWidth(0.45);
                    $this->sheet->Line($x, $y, $x + $blockWidth, $y);
                }

                // Labels and amounts are both set against the right edge of their column.
                $this->sheet->SetXY($x, $y + self::CELL_PAD_Y);
                $this->lines($row['lines'], $x + self::CELL_PAD_X, $labelWidth - 2 * self::CELL_PAD_X, $lineHeight, 'R', $row['style'], $row['size']);
                $this->sheet->SetXY($x, $y + self::CELL_PAD_Y);
                $this->lines([$row['amount']], $x + $labelWidth + self::CELL_PAD_X, $valueWidth - 2 * self::CELL_PAD_X, $lineHeight, 'R', $row['style'], $row['size']);
                $this->sheet->SetXY($this->left, $y + $row['height']);
            }

            $this->sheet->SetLineWidth(0.2);

            return $this;
        });
    }

    /** Vertical space, in millimetres. Space that would start a page is dropped. */
    public function spacer(float $height = 4.0): static
    {
        return $this->guard(function () use ($height): static {
            if ($height < 0) {
                throw new PdfException('A spacer cannot be negative');
            }

            $y = $this->sheet->GetY();

            // No space at the top of a page, and none pushed past the foot of one.
            if ($y > $this->top + 0.01) {
                $this->sheet->SetXY($this->left, min($y + $height, $this->bottom));
            }

            return $this;
        });
    }

    /**
     * Finishes the document and returns the PDF file's bytes (they start
     * with "%PDF-"). The document cannot be used afterwards.
     *
     * @throws PdfException
     */
    public function output(): string
    {
        $bytes = $this->guard(fn (): string => (string) $this->sheet->Output('S'));

        $this->finished = true;

        return $bytes;
    }

    /**
     * Runs one public method: refuses a finished document, and lets
     * nothing but a PdfException out (the library's own exception becomes
     * getPrevious()).
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function guard(\Closure $work): mixed
    {
        if ($this->finished) {
            throw new PdfException('This document has already been output; ask the pdf service for a new one');
        }

        try {
            return $work();
        } catch (PdfException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PdfException('The PDF could not be built: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Makes sure $height millimetres are left on the page, starting a new
     * page when they are not. Returns true when it started one. A block
     * taller than a whole page is drawn from the top of a page and runs
     * off it; table() and paragraph() never ask for that much.
     */
    private function room(float $height): bool
    {
        $y = $this->sheet->GetY();

        if ($y + $height <= $this->bottom + 0.01 || $y <= $this->top + 0.01) {
            return false;
        }

        $this->sheet->AddPage();
        $this->sheet->SetXY($this->left, $this->top);

        return true;
    }

    /**
     * Draws already wrapped lines downwards from the current y, each
     * aligned inside a box $width wide that starts at $x, and leaves the
     * cursor at the left margin under the last one.
     *
     * @param array<int, array<int, array{0: string, 1: string}>> $lines
     */
    private function lines(array $lines, float $x, float $width, float $height, string $align, string $style, float $size): void
    {
        $y = $this->sheet->GetY();

        foreach ($lines as $line) {
            $offset = match ($align) {
                'R'     => $width - Typesetter::width($line, $style, $size),
                'C'     => ($width - Typesetter::width($line, $style, $size)) / 2,
                default => 0.0,
            };

            $this->sheet->draw($line, $x + $offset, $y, $height, $style, $size);

            $y += $height;
        }

        $this->sheet->SetXY($this->left, $y);
    }

    /**
     * @param array<string, mixed> $block
     * @return array{height: float, items: list<array<string, mixed>>}
     */
    private function prepareBlock(array $block, float $width, ?float $labelWidth): array
    {
        $items  = [];
        $height = 0.0;
        $small  = max($this->fontSize - 2.0, 6.0);

        foreach (['title', 'lead'] as $key) {
            if (isset($block[$key]) && !is_scalar($block[$key])) {
                throw new PdfException("A keyValues block's {$key} must be text");
            }
        }

        if (isset($block['title']) && (string) $block['title'] !== '') {
            $lines   = Typesetter::lines((string) $block['title'], $width, Fonts::REGULAR, $small);
            $items[] = ['lines' => $lines, 'style' => Fonts::REGULAR, 'size' => $small, 'muted' => true, 'x' => 0.0, 'after' => 0.8, 'label' => []];
            $height += count($lines) * Typesetter::lineHeight($small) + 0.8;
        }

        if (isset($block['lead']) && (string) $block['lead'] !== '') {
            $size    = $this->fontSize + 1.0;
            $lines   = Typesetter::lines((string) $block['lead'], $width, Fonts::BOLD, $size);
            $items[] = ['lines' => $lines, 'style' => Fonts::BOLD, 'size' => $size, 'muted' => false, 'x' => 0.0, 'after' => 0.4, 'label' => []];
            $height += count($lines) * Typesetter::lineHeight($size) + 0.4;
        }

        $rows = $block['rows'] ?? [];

        if (!is_array($rows)) {
            throw new PdfException("A keyValues block's rows must be a list of [label, value]");
        }

        $pairs = [];

        foreach (array_values($rows) as $n => $row) {
            if (!is_array($row) || count($row) !== 2) {
                throw new PdfException('keyValues row ' . ($n + 1) . ' must be [label, value]');
            }

            [$label, $value] = array_values($row);

            if (($label !== null && !is_scalar($label)) || ($value !== null && !is_scalar($value))) {
                throw new PdfException('keyValues row ' . ($n + 1) . ' must hold text');
            }

            $pairs[] = [str_replace("\n", ' ', (string) $label), (string) $value];
        }

        if ($labelWidth === null) {
            $labelWidth = 0.0;

            foreach ($pairs as [$label]) {
                $labelWidth = max($labelWidth, $label !== '' ? Typesetter::width(Text::runs($label), Fonts::REGULAR, $this->fontSize) + 3.0 : 0.0);
            }
        }

        $labelWidth = min($labelWidth, $width * 0.4);
        $lineHeight = Typesetter::lineHeight($this->fontSize);

        foreach ($pairs as [$label, $value]) {
            $indent = $label !== '' ? $labelWidth : 0.0;
            $lines  = Typesetter::lines($value, $width - $indent, Fonts::REGULAR, $this->fontSize);

            $items[] = [
                'lines' => $lines, 'style' => Fonts::REGULAR, 'size' => $this->fontSize, 'muted' => false, 'x' => $indent, 'after' => 0.0,
                'label' => $label !== '' ? Typesetter::fit($label, max($labelWidth - 1.5, 1.0), Fonts::REGULAR, $this->fontSize) : [],
            ];
            $height += count($lines) * $lineHeight;
        }

        return ['height' => $height, 'items' => $items];
    }

    /** @param array{height: float, items: list<array<string, mixed>>} $block */
    private function drawBlock(array $block, float $x, float $y): void
    {
        foreach ($block['items'] as $item) {
            $height = Typesetter::lineHeight($item['size']);

            if ($item['label'] !== []) {
                $this->sheet->SetTextColor(95);
                $this->sheet->draw($item['label'], $x, $y, $height, $item['style'], $item['size']);
            }

            $this->sheet->SetTextColor($item['muted'] ? 95 : 0);

            foreach ($item['lines'] as $line) {
                $this->sheet->draw($line, $x + $item['x'], $y, $height, $item['style'], $item['size']);

                $y += $height;
            }

            $this->sheet->SetTextColor(0);

            $y += $item['after'];
        }

        $this->sheet->SetXY($this->left, $y);
    }

    /**
     * @param array<int, mixed> $columns
     * @return list<array{label: string, width: float, align: string}>
     */
    private static function prepareColumns(array $columns, float $pageWidth): array
    {
        $columns = array_values($columns);

        if ($columns === []) {
            throw new PdfException('A table needs at least one column');
        }

        $fixed  = 0.0;
        $shared = 0;

        foreach ($columns as $n => $column) {
            if (!is_array($column)) {
                throw new PdfException('Table column ' . ($n + 1) . ' must be an array with label, width and align');
            }

            self::refuseUnknown($column, ['label', 'width', 'align'], 'table column');

            if (isset($column['label']) && !is_scalar($column['label'])) {
                throw new PdfException('Table column ' . ($n + 1) . ': label must be text');
            }

            $width = $column['width'] ?? null;

            if ($width === null) {
                $shared++;

                continue;
            }

            if (!is_int($width) && !is_float($width) || $width <= 0) {
                throw new PdfException('Table column ' . ($n + 1) . ': width must be a positive number of millimetres');
            }

            $fixed += (float) $width;
        }

        // Fixed widths that leave too little for the shared columns (or
        // that are wider than the page) are scaled down to fit.
        $minimumShared = 12.0 * $shared;
        $scale         = $fixed + $minimumShared > $pageWidth ? ($pageWidth - $minimumShared) / max($fixed, 0.001) : 1.0;
        $each          = $shared > 0 ? ($pageWidth - $fixed * $scale) / $shared : 0.0;

        if ($scale <= 0 || ($shared > 0 && $each < 6)) {
            throw new PdfException('The table has too many columns for the page');
        }

        $prepared = [];

        foreach ($columns as $column) {
            $prepared[] = [
                'label' => (string) ($column['label'] ?? ''),
                'width' => isset($column['width']) ? (float) $column['width'] * $scale : $each,
                'align' => self::align($column),
            ];
        }

        return $prepared;
    }

    /**
     * One row of a table (the header is a row in bold) with a rule under it.
     *
     * @param list<array{label: string, width: float, align: string}>             $columns
     * @param array<int, array<int, array<int, array{0: string, 1: string}>>> $cells   wrapped lines, per column
     */
    private function tableRow(array $columns, array $cells, float $rowHeight, float $lineHeight, string $style, float $size, float $rule): void
    {
        $x = $this->left;
        $y = $this->sheet->GetY();

        foreach ($columns as $i => $column) {
            $this->sheet->SetXY($x, $y + self::CELL_PAD_Y);
            $this->lines($cells[$i], $x + self::CELL_PAD_X, $column['width'] - 2 * self::CELL_PAD_X, $lineHeight, $column['align'], $style, $size);

            $x += $column['width'];
        }

        $this->sheet->SetLineWidth($rule);
        $this->sheet->Line($this->left, $y + $rowHeight, $this->left + $this->width, $y + $rowHeight);
        $this->sheet->SetXY($this->left, $y + $rowHeight);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $allowed
     */
    private function textSize(array $options, array $allowed): float
    {
        $size = (string) ($options['size'] ?? 'normal');

        if (!in_array($size, $allowed, true)) {
            throw new PdfException("Unknown size '" . self::printable($options['size']) . "'; use " . implode(', ', $allowed));
        }

        return match ($size) {
            'small' => max($this->fontSize - 1.5, 6.0),
            'large' => $this->fontSize + 2.0,
            default => $this->fontSize,
        };
    }

    /** @param array<string, mixed> $options */
    private static function align(array $options): string
    {
        $align = strtolower((string) ($options['align'] ?? 'left'));

        if (!isset(self::ALIGN[$align])) {
            throw new PdfException("Unknown align '" . self::printable($options['align']) . "'; use left, center or right");
        }

        return self::ALIGN[$align];
    }

    /** @return array{top: float, right: float, bottom: float, left: float} */
    private static function margins(mixed $margins): array
    {
        $resolved = ['top' => 18.0, 'right' => 16.0, 'bottom' => 20.0, 'left' => 16.0];

        if ($margins === null) {
            return $resolved;
        }

        if (is_int($margins) || is_float($margins)) {
            $margins = array_fill_keys(array_keys($resolved), $margins);
        }

        if (!is_array($margins)) {
            throw new PdfException('margins must be a number of millimetres, or an array with top, right, bottom and left');
        }

        self::refuseUnknown($margins, array_keys($resolved), 'margins');

        foreach ($margins as $side => $value) {
            if (!is_int($value) && !is_float($value) || $value < 5 || $value > 100) {
                throw new PdfException("The {$side} margin must be a number of millimetres from 5 to 100");
            }

            $resolved[$side] = (float) $value;
        }

        return $resolved;
    }

    /** UTF-8 for the file's metadata: repaired, one line, no control characters. */
    private static function metadata(string $value): string
    {
        return trim((string) preg_replace('/\p{C}+/u', ' ', mb_scrub($value, 'UTF-8')));
    }

    /**
     * @param array<string|int, mixed> $given
     * @param list<string>             $allowed
     */
    private static function refuseUnknown(array $given, array $allowed, string $what): void
    {
        $unknown = array_diff(array_map('strval', array_keys($given)), $allowed);

        if ($unknown !== []) {
            throw new PdfException("Unknown {$what} option" . (count($unknown) > 1 ? 's' : '') . ': ' . implode(', ', array_map(static fn (string $key): string => self::printable($key), $unknown)) . '. Allowed: ' . implode(', ', $allowed));
        }
    }

    private static function printable(mixed $value): string
    {
        return is_scalar($value) ? substr((string) preg_replace('/[^\x20-\x7E]/', '?', (string) $value), 0, 40) : gettype($value);
    }
}
