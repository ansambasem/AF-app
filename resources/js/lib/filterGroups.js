/**
 * Linked dropdowns in a table's Filter menu: a group with `dependsOn` is
 * shown beside the group it names and lists only the options whose
 * `parent` is that group's chosen value — e.g. a meter box's numbers
 * beside its name.
 */

/**
 * The top-level groups, each child group keyed by its parent's key, and
 * the groups a user may hide: a parent with a child (the box name) always
 * stays, with its child beside it.
 */
export function nestFilterGroups(groups) {
    const childOf = {};

    for (const group of groups ?? []) {
        if (group.dependsOn) {
            childOf[group.dependsOn] = group;
        }
    }

    const topLevel = (groups ?? []).filter((group) => !group.dependsOn);

    return { topLevel, childOf, hideable: topLevel.filter((group) => !childOf[group.key]) };
}

/**
 * The parent's value to show: the one picked, or else the parent of a
 * picked child option (a link that only carries the child, e.g. one box).
 */
export function parentValue(parentKey, child, values) {
    if (values?.[parentKey]) {
        return values[parentKey];
    }

    const picked = child?.options.find((option) => String(option.value) === String(values?.[child.key] ?? ''));

    return picked?.parent ?? '';
}

/** The child's options for the chosen parent value; none until one is chosen. */
export function childOptions(child, parent) {
    return parent ? child.options.filter((option) => option.parent === parent) : [];
}

/** The filter updates for a new parent value: the child resets, since its choice belonged to the old one. */
export function parentChange(parentKey, child, value) {
    return child ? { [parentKey]: value, [child.key]: '' } : { [parentKey]: value };
}

/**
 * Whether an option belongs with the filters chosen so far: every chosen
 * filter its `scope` names (a branch, an area, a tariff…) must be one it
 * belongs to. An option that names none of them always shows.
 */
function inScope(option, values) {
    return Object.entries(option.scope ?? {}).every(([key, belongsTo]) => {
        const chosen = values?.[key];

        if (!chosen) {
            return true;
        }

        return Array.isArray(belongsTo) ? belongsTo.includes(String(chosen)) : belongsTo === String(chosen);
    });
}

/** The group's options that belong with the filters chosen so far, e.g. only the chosen branch's meter boxes. */
export function scopedOptions(group, values) {
    return group.options.filter((option) => inScope(option, values));
}

/**
 * The filter updates with every other filter that no longer fits cleared:
 * picking another branch drops a meter box, area or employee of the old
 * one — and whatever hung on those in turn.
 */
export function withStaleCleared(groups, values, updates) {
    const next = { ...values, ...updates };
    const cleared = {};
    let changed = true;

    while (changed) {
        changed = false;

        for (const group of groups ?? []) {
            const value = next[group.key];

            if (!value || group.key in updates) {
                continue;
            }

            const option = group.options.find((candidate) => String(candidate.value) === String(value));

            if (option && !inScope(option, next)) {
                next[group.key] = '';
                cleared[group.key] = '';
                changed = true;
            }
        }
    }

    return { ...updates, ...cleared };
}
