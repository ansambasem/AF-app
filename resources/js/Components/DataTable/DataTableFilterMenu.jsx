import { useEffect, useRef, useState } from 'react';
import { useFilterVisibility } from '@/hooks/useFilterVisibility';
import SearchableSelect from '@/Components/SearchableSelect';
import Icon from '@/Components/Icon';
import { childOptions, nestFilterGroups, parentChange, parentValue, scopedOptions, withStaleCleared } from '@/lib/filterGroups';

/** One labelled dropdown in the filter row. */
function FilterField({ group, value, onChange, placeholder = 'الكل', className = 'sm:w-44' }) {
    return (
        <div className={`flex w-full min-w-0 flex-col gap-1.5 ${className}`}>
            <span className={`flex items-center gap-1.5 text-xs font-semibold ${value ? 'text-gray-900' : 'text-gray-500'}`}>
                {group.label}
                {value && <span className="h-1.5 w-1.5 rounded-full bg-brand-500" aria-hidden="true" />}
            </span>
            <SearchableSelect
                value={value}
                onChange={onChange}
                options={group.options}
                placeholder={placeholder}
                searchPlaceholder={`بحث في ${group.label}...`}
                emptyLabel="لا توجد نتائج"
                active={Boolean(value)}
            />
        </div>
    );
}

/**
 * The table's filter dropdowns. A group with `dependsOn` (e.g. a meter
 * box's numbers) shows beside the group it depends on (the box name) once
 * that has a value, and that pair always stays shown; `onChangeMany` sets
 * both at once when the parent changes; once both show, they sit in one
 * shared frame (pulled out by its padding, so the fields stay in line with
 * the rest) and read as one filter. Each dropdown lists only the
 * options that belong with the other filters picked (an option's `scope`):
 * pick a branch and the areas, sub-areas, meter boxes and staff narrow to
 * that branch's; changing it clears whichever of them no longer fit.
 */
export default function DataTableFilterMenu({ tableKey, groups, values, onChange, onChangeMany, onClear, children }) {
    const activeCount = Object.values(values ?? {}).filter(Boolean).length;
    const [open, setOpen] = useState(false);
    const menuRef = useRef(null);
    const { topLevel, childOf, hideable } = nestFilterGroups(groups);
    const groupKeys = hideable.map((group) => group.key);
    const { visibleKeys, isVisible, toggle } = useFilterVisibility(tableKey, groupKeys);

    useEffect(() => {
        if (!open) {
            return;
        }

        function onPointerDown(event) {
            if (menuRef.current && !menuRef.current.contains(event.target)) {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    if (!groups || groups.length === 0) {
        return null;
    }

    function changeMany(changes) {
        const updates = withStaleCleared(groups, values, changes);

        if (onChangeMany) {
            onChangeMany(updates);
        } else {
            Object.entries(updates).forEach(([key, value]) => onChange(key, value));
        }
    }

    function toggleAndClear(key) {
        toggle(key);
        if (isVisible(key) && values?.[key]) {
            changeMany({ [key]: '' });
        }
    }

    const visibleGroups = topLevel.filter((group) => childOf[group.key] || visibleKeys.includes(group.key));

    return (
        <div className="flex w-full flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
            {visibleGroups.map((group) => {
                const child = childOf[group.key];

                if (!child) {
                    return (
                        <FilterField
                            key={group.key}
                            group={{ ...group, options: scopedOptions(group, values) }}
                            value={values?.[group.key] ?? ''}
                            onChange={(next) => changeMany({ [group.key]: next })}
                        />
                    );
                }

                const value = parentValue(group.key, child, values);

                return (
                    <div
                        key={group.key}
                        role="group"
                        aria-label={group.label}
                        className={`flex w-full min-w-0 flex-col gap-3 sm:w-auto sm:flex-row sm:items-end ${
                            value ? '-my-2 rounded-2xl bg-brand-500/[0.04] px-2.5 py-2 ring-1 ring-inset ring-brand-500/20' : ''
                        }`}
                    >
                        <FilterField
                            group={{ ...group, options: scopedOptions(group, values) }}
                            value={value}
                            onChange={(next) => changeMany(parentChange(group.key, child, next))}
                        />
                        {value && (
                            <FilterField
                                group={{ ...child, options: scopedOptions({ options: childOptions(child, value) }, values) }}
                                value={values?.[child.key] ?? ''}
                                placeholder={`كل ${value}`}
                                className="sm:w-60"
                                onChange={(next) => changeMany({ [group.key]: value, [child.key]: next })}
                            />
                        )}
                    </div>
                );
            })}

            {children}
            <div className="relative" ref={menuRef}>
                <button
                    type="button"
                    onClick={() => setOpen((current) => !current)}
                    aria-expanded={open}
                    className="flex items-center gap-2 rounded-control border border-gray-200 bg-surface px-3.5 py-2.5 text-xs font-semibold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50"
                >
                    <Icon name="filter" className="h-4 w-4" />
                    الفلاتر الظاهرة
                    <span className="rounded-md bg-gray-100 px-1.5 py-0.5 font-display text-[13px] text-gray-500">
                        {visibleKeys.length}/{hideable.length}
                    </span>
                </button>

                {open && (
                    <div className="absolute end-0 z-20 mt-2 w-60 rounded-2xl border border-gray-100 bg-surface p-2 shadow-lift">
                        {hideable.map((group) => (
                            <label
                                key={group.key}
                                className="flex cursor-pointer items-center justify-between gap-3 rounded-xl px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                            >
                                {group.label}
                                <input
                                    type="checkbox"
                                    role="switch"
                                    checked={isVisible(group.key)}
                                    onChange={() => toggleAndClear(group.key)}
                                    className="peer sr-only"
                                />
                                <span
                                    aria-hidden="true"
                                    className="relative h-5 w-9 shrink-0 rounded-full bg-gray-200 transition after:absolute after:start-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-surface after:shadow after:transition-all peer-checked:bg-brand-500 peer-checked:after:start-[18px] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-gray-900"
                                />
                            </label>
                        ))}
                    </div>
                )}
            </div>

            {activeCount > 0 && (
                <button
                    type="button"
                    onClick={onClear}
                    className="rounded-control px-3 py-2.5 text-xs font-semibold text-brand-600 transition hover:bg-brand-500/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"
                >
                    مسح الفلاتر ({activeCount})
                </button>
            )}
        </div>
    );
}
