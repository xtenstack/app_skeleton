<?php
declare(strict_types=1);

namespace App_skeleton;

use League\CommonMark\CommonMarkConverter;

/**
 * Server-side markdown rendering for KB articles' `body` (REQ-225) — the
 * one place raw markdown is turned into HTML for display. Always safe
 * mode: raw HTML in the source is escaped rather than passed through,
 * and unsafe link schemes (e.g. javascript:) are stripped, since an
 * article can be authored by any operator/admin, not just a fully
 * trusted superuser, and (per Knowledge-Base-Module-Plan.md section 5)
 * may eventually start as an agent-drafted submission awaiting review.
 * Volt's own auto-escaping stays on around the *rest* of a view as
 * usual — this class's output is the one deliberate, narrow exception,
 * always rendered through Phalcon's `raw()`/`|raw` filter at the call
 * site, and only ever fed the DB's `body` column, never raw request
 * input directly.
 */
class Markdown
{
    private static ?CommonMarkConverter $converter = null;

    public static function toHtml(string $markdown): string
    {
        return self::converter()->convert($markdown)->getContent();
    }

    private static function converter(): CommonMarkConverter
    {
        if (self::$converter === null) {
            self::$converter = new CommonMarkConverter([
                'html_input'         => 'escape',
                'allow_unsafe_links' => false,
            ]);
        }

        return self::$converter;
    }
}
