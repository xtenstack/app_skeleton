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

        $params['order']  = ($sortable[$sort] ?? 'id') . ' ' . strtoupper($dir);
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

    /** Prev/page-numbers/next — silently renders nothing for a single page. */
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

        $items = $link($page - 1, '&laquo; Prev', $page <= 1);

        for ($i = 1; $i <= $totalPages; $i++) {
            $items .= $link($i, (string) $i, false, $i === $page);
        }

        $items .= $link($page + 1, 'Next &raquo;', $page >= $totalPages);

        return '<nav><ul class="pagination pagination-sm mb-0">' . $items . '</ul></nav>';
    }
}
