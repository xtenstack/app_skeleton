<?php

declare(strict_types=1);

namespace App_skeleton\Pdf;

/**
 * Measures and breaks text that may mix code pages. Internal to
 * App_skeleton\Pdf, and pure: it needs the fonts' width tables (Fonts)
 * but no PDF, so the layout can be tested on its own.
 *
 * A "line" here is what Text::runs() returns for one line of text: a
 * list of [code page, bytes]. Its width is the sum of its runs' widths,
 * each measured in its own font. FPDF's MultiCell() is not used: it
 * knows one font at a time.
 */
final class Typesetter
{
    /** Millimetres per point. */
    public const MM_PER_PT = 0.352778;

    private const ELLIPSIS = '...';

    /**
     * $text broken into lines no wider than $maxWidth millimetres: at each
     * "\n", then at spaces, and inside a word only when the word alone is
     * wider than a line. Always at least one line (which may be empty).
     *
     * @return list<list<array{0: string, 1: string}>>
     */
    public static function lines(string $text, float $maxWidth, string $style, float $points): array
    {
        $unit     = self::unit($points);
        $maxWidth = max($maxWidth, 1.0);
        $lines    = [];

        foreach (explode("\n", Text::normalise($text)) as $paragraph) {
            $cells     = self::cells($paragraph, $style);
            $line      = [];
            $width     = 0.0;
            $lastSpace = -1;

            foreach ($cells as $cell) {
                $cellWidth = $cell[2] * $unit;
                $isSpace   = $cell[1] === ' ';

                if ($width + $cellWidth > $maxWidth && $line !== []) {
                    if ($isSpace) {
                        // The space that would overflow ends the line and is dropped.
                        $lines[]   = self::group($line);
                        $line      = [];
                        $width     = 0.0;
                        $lastSpace = -1;

                        continue;
                    }

                    if ($lastSpace >= 0) {
                        // Break at the last space; the word in progress starts the next line.
                        $lines[]   = self::group(array_slice($line, 0, $lastSpace));
                        $line      = array_slice($line, $lastSpace + 1);
                        $width     = array_sum(array_column($line, 2)) * $unit;
                        $lastSpace = -1;
                    } else {
                        // One word wider than the line: break inside it.
                        $lines[] = self::group($line);
                        $line    = [];
                        $width   = 0.0;
                    }
                }

                if ($isSpace) {
                    if ($line === []) {
                        // No line starts with the space it was broken at.
                        continue;
                    }

                    $lastSpace = count($line);
                }

                $line[] = $cell;
                $width += $cellWidth;
            }

            $lines[] = self::group($line);
        }

        return $lines;
    }

    /**
     * $text as one line no wider than $maxWidth millimetres: line breaks
     * become spaces, and text that is too wide is cut and ends in "...".
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function fit(string $text, float $maxWidth, string $style, float $points): array
    {
        $unit  = self::unit($points);
        $cells = self::cells(str_replace("\n", ' ', Text::normalise($text)), $style);

        if (array_sum(array_column($cells, 2)) * $unit <= $maxWidth) {
            return self::group($cells);
        }

        $page = $cells[0][0] ?? Fonts::CODE_PAGES[0];
        $room = $maxWidth - Fonts::width($page, $style, self::ELLIPSIS) * $unit;
        $kept = [];

        foreach ($cells as $cell) {
            $room -= $cell[2] * $unit;

            if ($room < 0) {
                break;
            }

            $kept[] = $cell;
            $page   = $cell[0];
        }

        $widths = Fonts::widths($page, $style);

        foreach (str_split(self::ELLIPSIS) as $dot) {
            $kept[] = [$page, $dot, $widths[$dot] ?? 0];
        }

        return self::group($kept);
    }

    /**
     * The width of a line in millimetres: the sum of its runs, each in its own font.
     *
     * @param array<int, array{0: string, 1: string}> $line
     */
    public static function width(array $line, string $style, float $points): float
    {
        $total = 0;

        foreach ($line as [$page, $bytes]) {
            $total += Fonts::width($page, $style, $bytes);
        }

        return $total * self::unit($points);
    }

    /**
     * A line back as UTF-8, for tests and messages.
     *
     * @param array<int, array{0: string, 1: string}> $line
     */
    public static function plain(array $line): string
    {
        $text = '';

        foreach ($line as [$page, $bytes]) {
            for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
                $text .= mb_chr(Fonts::codePoint($page, ord($bytes[$i])), 'UTF-8');
            }
        }

        return $text;
    }

    /** The height of a line of text, in millimetres. */
    public static function lineHeight(float $points): float
    {
        return $points * self::MM_PER_PT * 1.32;
    }

    /** Millimetres per thousandth of the font size. */
    private static function unit(float $points): float
    {
        return $points * self::MM_PER_PT / 1000;
    }

    /**
     * One line of text as cells: [code page, byte, width in thousandths], one per character drawn.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function cells(string $line, string $style): array
    {
        $cells = [];

        foreach (Text::runs($line) as [$page, $bytes]) {
            $widths = Fonts::widths($page, $style);

            for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
                $cells[] = [$page, $bytes[$i], $widths[$bytes[$i]] ?? 0];
            }
        }

        return $cells;
    }

    /**
     * Cells back into runs, without the spaces a line would end with.
     *
     * @param array<int, array{0: string, 1: string, 2: int}> $cells
     * @return list<array{0: string, 1: string}>
     */
    private static function group(array $cells): array
    {
        while ($cells !== [] && $cells[array_key_last($cells)][1] === ' ') {
            array_pop($cells);
        }

        $runs = [];

        foreach ($cells as [$page, $byte]) {
            $last = count($runs) - 1;

            if ($last >= 0 && $runs[$last][0] === $page) {
                $runs[$last][1] .= $byte;
            } else {
                $runs[] = [$page, $byte];
            }
        }

        return $runs;
    }
}
