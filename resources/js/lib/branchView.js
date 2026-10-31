/** Keep the page and report period while dropping selections belonging to the previous branch. */
export function branchViewUrl(branchId, href) {
    const url = new URL(href);
    for (const key of [
        'branch', 'branch_id', 'filter[branch_id]', 'governorate', 'area', 'area_id',
        'filter[governorate_id]', 'filter[area_id]', 'filter[sub_area_id]', 'filter[meter_box_id]', 'filter[user_id]',
        'page', 'box', 'closing', 'statement', 'subscription', 'selected', 'week',
    ]) {
        url.searchParams.delete(key);
    }
    url.searchParams.set('branch', branchId ? String(branchId) : 'all');

    return `${url.pathname}${url.search}`;
}
