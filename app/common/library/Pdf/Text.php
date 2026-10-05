<?php

declare(strict_types=1);

namespace App_skeleton\Pdf;

/**
 * The one place a caller's string is made ready for the PDF's fonts.
 *
 * The fonts are single-byte, one per code page (Fonts), so a UTF-8
 * string becomes runs: stretches of text that one font can draw. runs()
 * never throws and never returns bytes that could corrupt the file:
 * invalid UTF-8 is repaired, control characters are dropped, and a
 * character no code page has (Chinese, Arabic, an emoji) becomes the
 * plain-ASCII equivalent the platform's iconv offers, or "?".
 */
final class Text
{
    /** Settled here, because iconv treats them differently from one platform to the next, or no font has them. */
    private const REPLACEMENTS = [
        "\u{2028}" => "\n",  // line separator
        "\u{2029}" => "\n",  // paragraph separator
        "\u{00A0}" => ' ',   // no-break space
        "\u{202F}" => ' ',   // narrow no-break space
        "\u{2009}" => ' ',   // thin space
        "\u{2002}" => ' ',
        "\u{2003}" => ' ',
        "\u{2010}" => '-',   // hyphen
        "\u{2011}" => '-',   // non-breaking hyphen
        "\u{2212}" => '-',   // minus sign
        "\u{2015}" => "\u{2014}", // horizontal bar -> em dash
    ];

    /**
     * UTF-8 in, clean UTF-8 out: valid, line breaks as "\n", a tab as four
     * spaces, no control or invisible formatting characters.
     */
    public static function normalise(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        $text = strtr($text, self::REPLACEMENTS);

        // Control and format characters (but not the line break) have no
        // glyph, and a stray one can upset a PDF reader.
        return preg_replace('/[^\P{C}\n]/u', '', $text) ?? '';
    }

    /**
     * A UTF-8 string as runs of [code page, bytes in that code page], in
     * order. Each character goes to the code page of the character before
     * it when that page has it, so a run is not broken needlessly;
     * otherwise to the first page that has it, Western (cp1252) first.
     * Plain ASCII is in every page, so it never starts a run of its own
     * (text that is ASCII and Cyrillic is one cp1251 run). A character in
     * no page is transliterated to ASCII if iconv can, else it is "?".
     * A line break stays in its run as "\n". '' gives no runs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function runs(string $text): array
    {
        $text = self::normalise($text);

        if ($text === '') {
            return [];
        }

        $runs    = [];
        $page    = null;   // the current run's code page
        $bytes   = '';     // the current run's bytes
        $isAscii = true;   // the current run is plain ASCII so far, so it can still take any page

        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            if (strlen($character) === 1) {
                $bytes .= $character;

                continue;
            }

            $codePoint = mb_ord($character, 'UTF-8');
            $found     = null;
            $byte      = null;

            foreach ($page !== null ? array_merge([$page], Fonts::CODE_PAGES) : Fonts::CODE_PAGES as $candidate) {
                $byte = Fonts::byte($candidate, (int) $codePoint);

                if ($byte !== null) {
                    $found = $candidate;

                    break;
                }
            }

            if ($found === null || $byte === null) {
                // In no code page: ASCII, which fits whatever run this is.
                $bytes .= self::transliterate($character);

                continue;
            }

            if ($found !== $page) {
                if (!$isAscii && $page !== null) {
                    $runs[] = [$page, $bytes];
                    $bytes  = '';
                }

                // (An all-ASCII run so far simply becomes a run of the new page.)
                $page = $found;
            }

            $bytes  .= chr($byte);
            $isAscii = false;
        }

        if ($bytes !== '') {
            $runs[] = [$page ?? Fonts::CODE_PAGES[0], $bytes];
        }

        return $runs;
    }

    /** One character that no code page has, as printable ASCII: iconv's nearest equivalent, or "?". */
    private static function transliterate(string $character): string
    {
        if (!function_exists('iconv')) {
            return '?';
        }

        // iconv raises a notice for a character it cannot convert; that is
        // an answer here, not an error.
        set_error_handler(static fn (): bool => true);

        try {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $character);
        } finally {
            restore_error_handler();
        }

        $ascii = is_string($ascii) ? (string) preg_replace('/[^\x20-\x7E]/', '', $ascii) : '';

        return $ascii === '' ? '?' : $ascii;
    }
}
