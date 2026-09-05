<?php
declare(strict_types=1);

namespace App_skeleton;

/**
 * Search/sort/pagination for a backend list-view indexAction (Session
 * 15, list-view convention). `$searchable`/`$sortable` are trusted,
 * hardcoded column names supplied by the calling controller, never
 * user input — only the search term itself is bound. Existing
 * status/filter-style conditions built by the controller (see
 * TicketsController::indexAction for the reference shape) are passed
 * in via `$conditions`/`$bind` and ANDed with the search clause, so
 * this layers on top of whatever filtering a list already has rather
 * than replacing it.
 */
class ListView
{
    /**
     * @param string   $modelClass model class name, e.g. 'Tickets'
     * @param string[] $searchable columns ILIKE-matched against the 'q' query param
     * @param array<string,string> $sortable sort key => real column; first entry is the default
     * @param string[] $conditions pre-built SQL conditions to AND in
     * @param array<string,mixed> $bind bind params matching $conditions
     * @return array{results: \Phalcon\Mvc\Model\ResultsetInterface, q: string, sort: string, dir: string, page: int, totalPages: int, total: int, perPage: int, preserve: array<string,string>}
     */
    public static function paginate(
        \Phalcon\Http\Request $request,
        string $modelClass,
        array $searchable,
        array $sortable,
        array $conditions = [],
        array $bind = [],
        int $perPage = 25,
        string $defaultDir = 'desc'
    ): array {
        $q = trim((string) $request->getQuery('q', 'string', ''));

        if ($q !== '' && $searchable) {
            $orParts = [];

            foreach ($searchable as $i => $column) {
                $key            = 'search_' . $i;
                $orParts[]      = $column . ' ILIKE :' . $key . ':';
                $bind[$key]     = '%' . $q . '%';
            }

            $conditions[] = '(' . implode(' OR ', $orParts) . ')';
        }

        $sortKeys = array_keys($sortable);
        $sort     = (string) $request->getQuery('sort', 'string', $sortKeys[0] ?? 'id');

        if (!isset($sortable[$sort])) {
            $sort = $sortKeys[0] ?? 'id';
        }

        $dir = strtolower((string) $request->getQuery('dir', 'string', $defaultDir));
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $params = [];

        if ($conditions) {
            $params['conditions'] = implode(' AND ', $conditions);
            $params['bind']       = $bind;
        }

        $total      = (int) $modelClass::count($params);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, (int) $request->getQuery('page', 'int', 1)), $totalPages);

        // NULLS LAST regardless of direction — Postgres's own default
        // (NULLS FIRST on DESC) means any column with unmatched/missing
        // data sorts to the very top of a "highest first" view, ahead of
        // every real value. That's never what "sort by X descending" means
        // to someone looking at the list (found via marketing-module's
        // Score column, where most rows have no qualification match yet —
        // 2026-08-27).
        //
        // Can't write `NULLS LAST` directly — this goes through Phalcon's
        // PHQL, not raw SQL, and PHQL's ORDER BY grammar doesn't have a
        // NULLS clause at all (confirmed the hard way: it broke every
        // list in the app the moment a real request hit it, despite
        // testing clean against psql directly — psql was never running
        // the actual code path this method uses). Equivalent PHQL-legal
        // form: sort on "is this null" first (false/0 before true/1
        // ascending, i.e. nulls last) as a tiebreak ahead of the real
        // column, which every backend here (just Postgres today) can
        // still translate correctly.
        $column           = $sortable[$sort] ?? 'id';
        $params['order']  = "({$column} IS NULL) ASC, {$column} " . strtoupper($dir);
        $params['limit']  = $perPage;
        $params['offset'] = ($page - 1) * $perPage;

        // Ticket #20: every list controller's own $preserveQuery omitted
        // q/sort/dir entirely, so a page-2 link (built by pagination()
        // below from $preserveQuery) silently dropped the current search
        // *and* sort order, landing on page 2 of the unfiltered/default-
        // sorted list instead. Returned here, once, so a controller only
        // has to merge this in rather than re-deriving it (and getting it
        // wrong the same way every existing controller already had).
        $preserve = array_filter([
            'q'    => $q !== '' ? $q : null,
            'sort' => $sort !== ($sortKeys[0] ?? 'id') ? $sort : null,
            'dir'  => $dir !== $defaultDir ? $dir : null,
        ], fn ($v) => $v !== null);

        return [
            'results'    => $modelClass::find($params),
            'q'          => $q,
            'sort'       => $sort,
            'dir'        => $dir,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
            'perPage'    => $perPage,
            'preserve'   => $preserve,
        ];
    }

    /**
     * Renders a `?key=value&...` string merging $preserve (existing
     * filter params a list already has, e.g. Tickets' status/filter)
     * with one override — used by sortLink()/pagination() so clicking a
     * sort header or a page link doesn't drop the current search/filter
     * state.
     */
    private static function queryString(array $preserve, array $override): string
    {
        return http_build_query(array_merge($preserve, $override));
    }

    /** Plain GET search form — no JS needed, works with $preserve as hidden fields. */
    public static function searchForm(string $action, string $q, array $preserve = [], string $placeholder = 'Search…'): string
    {
        $hidden = '';

        // 'q' has its own visible input below — rendering it again as a
        // hidden field too (now that $preserve includes it, per the
        // paginate()/pagination() fix above) would duplicate the name
        // attribute on this form.
        unset($preserve['q']);

        foreach ($preserve as $key => $value) {
            $hidden .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                htmlspecialchars((string) $key, ENT_QUOTES),
                htmlspecialchars((string) $value, ENT_QUOTES)
            );
        }

        $clear = $q !== ''
            ? sprintf(
                ' <a href="%s?%s" class="btn btn-sm btn-link">Clear</a>',
                htmlspecialchars($action, ENT_QUOTES),
                htmlspecialchars(self::queryString($preserve, []), ENT_QUOTES)
            )
            : '';

        return sprintf(
            '<form method="get" action="%s" class="form-inline mb-2">%s'
            . '<input type="search" name="q" class="form-control form-control-sm mr-2" style="max-width:260px;" placeholder="%s" value="%s">'
            . '<button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>%s</form>',
            htmlspecialchars($action, ENT_QUOTES),
            $hidden,
            htmlspecialchars($placeholder, ENT_QUOTES),
            htmlspecialchars($q, ENT_QUOTES),
            $clear
        );
    }

    /** A sortable `<th>`'s inner link + caret — wrap the return value in `<th>...</th>`. */
    public static function sortLink(string $action, string $label, string $key, string $currentSort, string $currentDir, array $preserve = []): string
    {
        $isActive = $key === $currentSort;
        $nextDir  = $isActive && $currentDir === 'asc' ? 'desc' : 'asc';
        $caret    = $isActive ? ($currentDir === 'asc' ? 'fa-caret-up' : 'fa-caret-down') : 'fa-sort text-muted';

        $qs = self::queryString($preserve, ['sort' => $key, 'dir' => $nextDir]);

        return sprintf(
            '<a href="%s?%s" class="text-decoration-none">%s <i class="fas %s"></i></a>',
            htmlspecialchars($action, ENT_QUOTES),
            htmlspecialchars($qs, ENT_QUOTES),
            htmlspecialchars($label, ENT_QUOTES),
            $caret
        );
    }

    /**
     * First/Prev/page-numbers/Next/Last — silently renders nothing for a
     * single page. Only the 3 smallest and 3 largest page numbers are ever
     * rendered as individual links (a large dataset's every-page-number
     * row otherwise grows to cover the whole screen width, as seen on
     * XTMK's bigger lists) — a "…" fills the gap between them when one
     * exists, wired up by app.js's `.page-jump` handler to prompt for a
     * page number rather than link to one, since there's no fixed page to
     * link to.
     */
    public static function pagination(string $action, int $page, int $totalPages, array $preserve = []): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        // $label is always developer-supplied (a page number or one of the
        // hardcoded &laquo;/&raquo; strings below), never user input — not
        // htmlspecialchars'd here so those entities render as arrows
        // instead of literal "&laquo;" text.
        $link = function (int $targetPage, string $label, bool $disabled = false, bool $active = false) use ($action, $preserve): string {
            if ($disabled) {
                return sprintf('<li class="page-item disabled"><span class="page-link">%s</span></li>', $label);
            }

            $qs = self::queryString($preserve, ['page' => $targetPage]);

            return sprintf(
                '<li class="page-item%s"><a class="page-link" href="%s?%s">%s</a></li>',
                $active ? ' active' : '',
                htmlspecialchars($action, ENT_QUOTES),
                htmlspecialchars($qs, ENT_QUOTES),
                $label
            );
        };

        $ellipsis = function () use ($action, $preserve, $totalPages): string {
            return sprintf(
                '<li class="page-item"><a class="page-link page-jump" href="#" data-action="%s" data-total-pages="%d" data-preserve="%s">&hellip;</a></li>',
                htmlspecialchars($action, ENT_QUOTES),
                $totalPages,
                htmlspecialchars(json_encode($preserve), ENT_QUOTES)
            );
        };

        $items = $link(1, '&laquo; First', $page <= 1);
        $items .= $link($page - 1, '&laquo; Prev', $page <= 1);

        // 3 smallest + 3 largest, deduplicated (they overlap once
        // totalPages <= 6) and only "…"-gapped when they don't meet.
        $lowest  = array_slice(range(1, $totalPages), 0, 3);
        $highest = array_slice(range(1, $totalPages), -3);
        $pageNumbers = array_unique(array_merge($lowest, $highest));

        $hasGap = end($lowest) < $highest[0] - 1;

        foreach ($pageNumbers as $i) {
            if ($hasGap && $i === $highest[0]) {
                $items .= $ellipsis();
            }

            $items .= $link($i, (string) $i, false, $i === $page);
        }

        $items .= $link($page + 1, 'Next &raquo;', $page >= $totalPages);
        $items .= $link($totalPages, 'Last &raquo;', $page >= $totalPages);

        return '<nav><ul class="pagination pagination-sm mb-0">' . $items . '</ul></nav>';
    }
}
