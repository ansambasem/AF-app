/**
 * The reading cycle board's small rules, kept apart from the page so they
 * can be tested on their own.
 */

/** How each stage of a cycle is shown. */
export const STAGES = {
    preparing: { label: 'تحضير', tone: 'blue' },
    reading_day: { label: 'يوم القراءة', tone: 'green' },
    ended: { label: 'انتهى يوم القراءة', tone: 'amber' },
    closed: { label: 'مغلقة', tone: 'gray' },
};

/** The board's columns in order: the available pool, paper, then each reader. */
export function boardColumns(readers) {
    return [
        { key: 'available', target: 'available', label: 'الطبلونات المتاحة' },
        { key: 'paper', target: 'paper', label: 'الكشوف الورقية' },
        ...readers.map((reader) => ({ key: `reader:${reader.id}`, target: 'mobile', userId: reader.id, label: reader.name })),
    ];
}

/** Search Arabic names regardless of vowel marks, hamza forms or digit style. */
function normalizeSearch(value) {
    return String(value ?? '').normalize('NFKD').replace(/\p{M}/gu, '').replace(/ـ/g, '').replace(/ى/g, 'ي')
        .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
        .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit))).toLowerCase().trim();
}

export function readingSearchMatches(value, search) {
    const haystack = normalizeSearch(value);
    return normalizeSearch(search).split(/\s+/).filter(Boolean).every((term) => haystack.includes(term));
}

/** A search result within one or more chosen areas; no chosen areas means all. */
export function boxMatches(box, search, subAreaIds, boxId = '') {
    const ids = Array.isArray(subAreaIds) ? subAreaIds : subAreaIds ? [subAreaIds] : [];
    const inSubArea = ids.length === 0 || ids.map(String).includes(String(box.subAreaId ?? 'none'));

    return inSubArea && (!boxId || String(box.id) === String(boxId)) && readingSearchMatches(`${box.number} ${box.name} ${box.subAreaName ?? 'بدون منطقة 2'}`, search);
}

/** Box choices follow the selected areas and distribution status. */
export function distributionBoxOptions(boxes, subAreaIds = [], assignmentFilter = '') {
    return boxes.filter((box) => boxMatches(box, '', subAreaIds) && assignmentMatches(box, assignmentFilter))
        .map((box) => ({ value: String(box.id), label: `${box.name || 'طبلون'} (${box.number})`, hint: box.subAreaName ?? 'بدون منطقة 2' }))
        .sort((first, second) => first.label.localeCompare(second.label, 'ar', { numeric: true }));
}

/** Toggle only this result set, preserving boxes chosen in other areas. */
export function toggleBoxSelection(selectedIds, boxes) {
    const next = new Set(selectedIds);
    const allSelected = boxes.length > 0 && boxes.every((box) => next.has(box.id));
    boxes.forEach((box) => (allSelected ? next.delete(box.id) : next.add(box.id)));
    return next;
}

/** Paper work and draft reader allocations both count as assigned. */
export function assignmentMatches(box, filter = '') {
    return !filter || (filter === 'assigned' ? box.column !== 'available' : box.column === filter);
}

export function distributionAreas(boxes, assignmentFilter = '') {
    const areas = new Map();
    boxes.forEach((box) => {
        const id = String(box.subAreaId ?? 'none');
        if (!areas.has(id)) areas.set(id, { id, name: box.subAreaName ?? 'بدون منطقة 2', boxes: 0, subscribers: 0, assigned: 0, unassigned: 0, matchingBoxes: 0, matchingSubscribers: 0 });
        const area = areas.get(id);
        area.boxes++;
        area.subscribers += box.subscribers;
        area[box.column === 'available' ? 'unassigned' : 'assigned']++;
        if (assignmentMatches(box, assignmentFilter)) {
            area.matchingBoxes++;
            area.matchingSubscribers += box.subscribers;
        }
    });
    return [...areas.values()].filter((area) => area.matchingBoxes > 0).sort((a, b) => a.name.localeCompare(b.name, 'ar'));
}

/** The boxes, subscribers, readings and drafts of one column. */
export function columnTotals(boxes) {
    return boxes.reduce(
        (totals, box) => ({
            boxes: totals.boxes + 1,
            subscribers: totals.subscribers + box.subscribers,
            read: totals.read + box.read,
            drafts: totals.drafts + (box.status === 'draft' ? 1 : 0),
        }),
        { boxes: 0, subscribers: 0, read: 0, drafts: 0 },
    );
}

/**
 * The boxes a drag carries: every chosen box when the dragged one is
 * chosen, otherwise just the dragged one.
 */
export function draggedBoxIds(boxId, selectedIds) {
    return selectedIds.has(boxId) ? [...selectedIds] : [boxId];
}

/** The request that moves the boxes to a column, each from the version shown. */
export function movePayload(boxIds, column, boards, confirmDownloaded = false) {
    const versions = Object.fromEntries(boards.filter((box) => boxIds.includes(box.id)).map((box) => [box.id, box.version]));

    return {
        box_ids: boxIds,
        target: column.target,
        user_id: column.userId ?? null,
        versions,
        confirm_downloaded: confirmDownloaded,
    };
}

/** The board's counters: available, by reader, paper, approved and draft boxes. */
export function boardCounters(boards) {
    return {
        available: boards.filter((box) => box.column === 'available').length,
        mobile: boards.filter((box) => box.column.startsWith('reader:')).length,
        paper: boards.filter((box) => box.column === 'paper').length,
        approved: boards.filter((box) => box.status === 'approved').length,
        draft: boards.filter((box) => box.status === 'draft').length,
        subscribers: boards.reduce((total, box) => total + box.subscribers, 0),
    };
}
