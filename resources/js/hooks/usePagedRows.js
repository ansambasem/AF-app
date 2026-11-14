import { useState } from 'react';

/**
 * Pages rows that are all in the page already: the rows of the current
 * page, and the `meta` the table's Pagination shows (with `setPage` as its
 * `onPageChange`). A page past the last, after the rows shrink, shows the
 * last one.
 */
export function usePagedRows(rows, perPage) {
    const [page, setPage] = useState(1);
    const last = Math.max(1, Math.ceil(rows.length / perPage));
    const current = Math.min(page, last);
    const from = rows.length ? (current - 1) * perPage + 1 : 0;

    return {
        rows: rows.slice(from ? from - 1 : 0, from ? from - 1 + perPage : 0),
        meta: { current_page: current, last_page: last, from, to: Math.min(current * perPage, rows.length), total: rows.length },
        setPage,
    };
}
