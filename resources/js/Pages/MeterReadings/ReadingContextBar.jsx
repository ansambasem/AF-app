import { useEffect, useState } from 'react';
import Icon from '@/Components/Icon';
import SearchableSelect from '@/Components/SearchableSelect';
import { activeFilterChips } from '@/lib/readingSheet';
import { childOptions, nestFilterGroups, parentChange, parentValue, scopedOptions, withStaleCleared } from '@/lib/filterGroups';

/** How far below the top of the window the pinned bar sits: under the app's own floating bar. */
const TOP_OFFSET = 74;

const shortDate = (date) => (date ? date.slice(5).split('-').reverse().join('/') : '');

/** The filters changed right from the bar while entering readings — the sub-area, then the meter box and its number — with what each says when none is picked. */
const QUICK_FILTERS = { sub_area_id: 'كل المناطق', meter_box_name: 'كل الطبلونات' };

/** One small labelled drop-down in the bar. */
function QuickFilter({ group, value, placeholder, onChange }) {
    return (
        <div className="re-context-field" role="group" aria-label={group.label}>
            <small aria-hidden="true">{group.label}</small>
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
 * While the sheet's filters are scrolled out of sight, a slim bar pinned
 * under the app's bar says where the readings being entered belong: the
 * week, then each filter set (branch, area, sub-area, meter box…) and the
 * search, with how many subscribers match. The sub-area, meter box and
 * box number are drop-downs in the bar itself, so the next box is picked
 * without scrolling back up: they follow the same rules as the filters
 * (a box's numbers once it is chosen; a choice that no longer fits is
 * cleared), and the sheet then goes back to its first rows. "تغيير الفلاتر"
 * scrolls back to the other filters. `anchorRef` marks the end of the
 * filters, `sectionRef` is the sheet: the bar shows once the filters pass
 * under the app's bar, and leaves again once the sheet itself has scrolled by.
 */
export default function ReadingContextBar({ anchorRef, sectionRef, week, weekEnd, groups, values, search, total, onChangeFilters }) {
    const [shown, setShown] = useState(false);
    const { topLevel, childOf } = nestFilterGroups(groups);
    const quickGroups = topLevel.filter((group) => group.key in QUICK_FILTERS);
    const quickKeys = quickGroups.flatMap((group) => [group.key, childOf[group.key]?.key].filter(Boolean));
    const chips = activeFilterChips(groups, values, search).filter((chip) => !quickKeys.includes(chip.key));

    useEffect(() => {
        let frame = null;

        function measure() {
            frame = null;
            const anchor = anchorRef.current?.getBoundingClientRect();
            const section = sectionRef.current?.getBoundingClientRect();

            setShown(Boolean(anchor && section) && anchor.bottom < TOP_OFFSET && section.bottom > TOP_OFFSET + 120);
        }

        function onScroll() {
            frame ??= window.requestAnimationFrame(measure);
        }

        measure();
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);

        return () => {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);

            if (frame !== null) {
                window.cancelAnimationFrame(frame);
            }
        };
    }, [anchorRef, sectionRef]);

    function changeFilters(changes) {
        onChangeFilters(withStaleCleared(groups, values, changes));

        // Back to the sheet's first rows, just under this bar, to show what the new filter brought.
        const anchor = anchorRef.current?.getBoundingClientRect();

        if (anchor && anchor.bottom < TOP_OFFSET) {
            window.scrollTo({ top: anchor.bottom + window.scrollY - TOP_OFFSET + 4 });
        }
    }

    function showFilters() {
        const top = (sectionRef.current?.getBoundingClientRect().top ?? 0) + window.scrollY - TOP_OFFSET - 12;
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

        window.scrollTo({ top, behavior: reduced ? 'auto' : 'smooth' });
    }

    return (
        <div className={`re-context ${shown ? 'is-shown' : ''}`} style={{ top: TOP_OFFSET }} aria-hidden={!shown} inert={!shown}>
            <div className="re-context-bar" role="region" aria-label="ما تُدخل له القراءات الآن">
                <span className="re-context-week">
                    <Icon name="calendar" className="h-4 w-4" />
                    <bdi dir="ltr">{shortDate(week)} – {shortDate(weekEnd)}</bdi>
                </span>
                {onChangeFilters && quickGroups.length > 0 && (
                    <div className="re-context-filters">
                        {quickGroups.map((group) => {
                            const child = childOf[group.key];
                            const value = child ? parentValue(group.key, child, values) : (values?.[group.key] ?? '');

                            return (
                                <div key={group.key} className="contents">
                                    <QuickFilter
                                        group={{ ...group, options: scopedOptions(group, values) }}
                                        value={value}
                                        placeholder={QUICK_FILTERS[group.key]}
                                        onChange={(next) => changeFilters(parentChange(group.key, child, next))}
                                    />
                                    {child && value && (
                                        <QuickFilter
                                            group={{ ...child, options: scopedOptions({ options: childOptions(child, value) }, values) }}
                                            value={values?.[child.key] ?? ''}
                                            placeholder={`كل ${value}`}
                                            onChange={(next) => changeFilters({ [group.key]: value, [child.key]: next })}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
                <div className="re-context-chips">
                    {chips.length === 0 ? (
                        quickKeys.some((key) => values?.[key]) ? null : <span className="re-context-chip is-all">كل المشتركين</span>
                    ) : (
                        chips.map((chip) => (
                            <span key={chip.key} className="re-context-chip">
                                <small>{chip.label}</small>
                                {chip.value}
                            </span>
                        ))
                    )}
                </div>
                <span className="re-context-count"><b>{Number(total ?? 0).toLocaleString('en')}</b> مشترك</span>
                <button type="button" className="re-context-edit" onClick={showFilters}>
                    <Icon name="filter" className="h-4 w-4" />
                    تغيير الفلاتر
                </button>
            </div>
        </div>
    );
}
