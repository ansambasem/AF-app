/** Read a financial export in bounded requests, keeping the branch, dates and kind on every page. */
export async function loadReportLines(query, { fetcher = fetch, signal, onProgress } = {}) {
    const lines = [];
    let cursor = null;

    do {
        const params = new URLSearchParams(query);
        if (cursor) params.set('cursor', cursor);
        const response = await fetcher(`/reports/lines.json?${params}`, { headers: { Accept: 'application/json' }, signal });
        if (!response.ok) throw new Error('تعذّر تحميل حركات التقرير.');
        const page = await response.json();
        lines.push(...page.lines);
        onProgress?.(lines.length);
        cursor = page.nextCursor;
    } while (cursor);

    return lines;
}
