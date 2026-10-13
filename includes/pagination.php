<?php
/** Shared pagination helper for the administrative tables. */
require_once __DIR__ . '/helpers.php';

/**
 * @return array{page:int, per:int, offset:int, pages:int, total:int}
 */
function paginate(int $total, int $perPage = 25, string $param = 'page'): array
{
    $perPage = max(5, min(200, $perPage));
    $pages   = max(1, (int)ceil($total / $perPage));
    $page    = max(1, min($pages, (int)($_GET[$param] ?? 1)));

    return [
        'page'   => $page,
        'per'    => $perPage,
        'offset' => ($page - 1) * $perPage,
        'pages'  => $pages,
        'total'  => $total,
    ];
}

/** Render the pager, preserving every other query parameter. */
function pager(array $p, string $param = 'page'): string
{
    if ($p['pages'] <= 1) {
        return '<p class="tiny muted mt">Showing all ' . (int)$p['total'] . ' records.</p>';
    }

    $query = $_GET;
    $link = static function (int $n) use ($query, $param): string {
        $query[$param] = $n;
        return '?' . http_build_query($query);
    };

    $first = max(1, $p['page'] - 2);
    $last  = min($p['pages'], $first + 4);
    $first = max(1, $last - 4);

    $out = '<div class="pager">';
    $out .= $p['page'] > 1
        ? '<a href="' . e($link($p['page'] - 1)) . '">&larr; Previous</a>'
        : '<span class="off">&larr; Previous</span>';

    for ($n = $first; $n <= $last; $n++) {
        $out .= $n === $p['page']
            ? '<span class="on">' . $n . '</span>'
            : '<a href="' . e($link($n)) . '">' . $n . '</a>';
    }

    $out .= $p['page'] < $p['pages']
        ? '<a href="' . e($link($p['page'] + 1)) . '">Next &rarr;</a>'
        : '<span class="off">Next &rarr;</span>';
    $out .= '</div>';

    $from = $p['offset'] + 1;
    $to   = min($p['total'], $p['offset'] + $p['per']);
    $out .= '<p class="tiny muted">Showing ' . $from . '&ndash;' . $to
          . ' of ' . (int)$p['total'] . ' records.</p>';

    return $out;
}
