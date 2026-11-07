import { useEffect, useState } from 'react';

function storageKey(tableKey) {
    return `filters-visible:${tableKey}`;
}

function readStored(tableKey) {
    try {
        const raw = localStorage.getItem(storageKey(tableKey));
        return raw ? JSON.parse(raw) : {};
    } catch {
        return {};
    }
}

// Which filter groups are shown in a table's filter menu, remembered per
// browser via localStorage so hiding a filter a user never uses sticks
// across visits. Defaults every group to visible until the user hides it.
export function useFilterVisibility(tableKey, groupKeys) {
    const [hidden, setHidden] = useState(() => readStored(tableKey));

    useEffect(() => {
        try {
            localStorage.setItem(storageKey(tableKey), JSON.stringify(hidden));
        } catch {
            // Browser storage may be unavailable (private mode, blocked) — the
            // toggle still works for the current page view, it just won't persist.
        }
    }, [tableKey, hidden]);

    function isVisible(key) {
        return !hidden[key];
    }

    function toggle(key) {
        setHidden((current) => ({ ...current, [key]: !current[key] }));
    }

    return {
        visibleKeys: groupKeys.filter(isVisible),
        isVisible,
        toggle,
    };
}
