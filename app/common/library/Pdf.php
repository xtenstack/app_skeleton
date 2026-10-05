<?php

declare(strict_types=1);

namespace App_skeleton;

use App_skeleton\Pdf\Document;
use App_skeleton\Pdf\PdfException;

/**
 * The `pdf` service ($di->getShared('pdf'), on web and CLI): one way for
 * the core and every module to make a PDF document — an invoice, a
 * statement, a quote, a report — without touching a PDF library.
 *
 *   $bytes = $this->getDI()->getShared('pdf')
 *       ->document(['title' => 'Statement 42', 'pageNumbers' => true])
 *       ->heading('Statement of account')
 *       ->table($columns, $rows)
 *       ->totals([['Total', '45.60', true]])
 *       ->output();
 *
 * Callers pass plain UTF-8 strings and get the file's bytes back. Latin
 * (Western, Central European, Turkish, Baltic), Greek and Cyrillic text
 * prints as written; other scripts are replaced (Pdf\Text). The library
 * underneath is FPDF (setasign/fpdf) with embedded DejaVu Sans; neither
 * is exposed, so both can be replaced without changing a caller. See
 * docs/MODULE-SPEC.md, "PDF documents", for the interface a module codes
 * against.
 *
 * A module that may run on a skeleton older than this service guards its
 * use: `$di->has('pdf') && $di->getShared('pdf')->supports('1.0')`.
 *
 * Holds no state: every document() call gives a new, independent
 * document.
 */
class Pdf
{
    /** Semantic version of the service's interface (this class and Pdf\Document), separate from the app's own version. */
    public const VERSION = '1.0.0';

    /**
     * A new, empty document.
     *
     * @param array<string, mixed> $options any of:
     *   title, author, subject (strings: the file's metadata),
     *   size ('A4' default, 'A3', 'A5', 'Letter', 'Legal'),
     *   orientation ('portrait' default, 'landscape'),
     *   margins (millimetres: one number for all four sides, or
     *            ['top' => , 'right' => , 'bottom' => , 'left' => ]; default 18 / 16 / 20 / 16),
     *   fontSize (points, 6 to 16, default 10: the body text; headings scale from it),
     *   footer (string: text repeated at the foot of every page),
     *   pageNumbers (bool, default false: "Page n of N" at the foot of every page),
     *   compress (bool, default true; false leaves the page content readable in the bytes, for tests)
     *
     * @throws PdfException for an unknown option or a value outside what is listed
     */
    public function document(array $options = []): Document
    {
        return new Document($options);
    }

    /** e.g. supports('1.1') — true when this interface is that version or later within the same major. */
    public function supports(string $version): bool
    {
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', trim($version), $want)) {
            return false;
        }

        [$major, $minor, $patch] = array_map('intval', explode('.', self::VERSION));

        if ((int) $want[1] !== $major) {
            return false;
        }

        return [$minor, $patch] >= [(int) ($want[2] ?? 0), (int) ($want[3] ?? 0)];
    }

    public function interfaceVersion(): string
    {
        return self::VERSION;
    }
}
