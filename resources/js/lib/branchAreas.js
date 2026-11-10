/** How the branch areas page counts and links: a branch's areas, an area's meter boxes. */

/** "منطقة واحدة", "منطقتان", "3 مناطق", "12 منطقة". */
export function areasCountLabel(count) {
    if (count === 0) {
        return 'بلا مناطق';
    }
    if (count === 1) {
        return 'منطقة واحدة';
    }
    if (count === 2) {
        return 'منطقتان';
    }

    return count <= 10 ? `${count} مناطق` : `${count} منطقة`;
}

/** "بلا طبلونات", "طبلون واحد", "طبلونان", "3 طبلونات", "12 طبلونًا". */
export function boxesCountLabel(count) {
    if (count === 0) {
        return 'بلا طبلونات';
    }
    if (count === 1) {
        return 'طبلون واحد';
    }
    if (count === 2) {
        return 'طبلونان';
    }

    return count <= 10 ? `${count} طبلونات` : `${count} طبلونًا`;
}

/** The meter boxes page narrowed to one area of one branch. */
export function areaBoxesHref(branchId, area) {
    return `/meter-boxes?${new URLSearchParams({ 'filter[branch_id]': String(branchId), 'filter[sub_area_id]': String(area.id) })}`;
}

/** The areas of `branchId`, from the form's area options (each names the branches it belongs to). */
export function areasOfBranch(subAreas, branchId) {
    if (!branchId) {
        return [];
    }

    return subAreas.filter((area) => (area.branch_ids ?? []).some((id) => String(id) === String(branchId)));
}

/** The area kept when the branch changes: the chosen one if it is the new branch's too, else none. */
export function areaAfterBranchChange(subAreas, areaId, branchId) {
    return areaId && areasOfBranch(subAreas, branchId).some((area) => String(area.id) === String(areaId)) ? areaId : '';
}
