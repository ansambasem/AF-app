import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/Components/Icon';

/**
 * A drop-down with a search box. Each option is `{ value, label, hint?, icon? }`:
 * `hint` is a quiet second part (a price, say) shown beside the label,
 * in the list and on the closed field, and searched with it. `required`
 * (with `name`) makes the form's own check report a missing choice.
 *
 * For a list too long to send with the page, pass `loadOptions(search)`,
 * which resolves `{ options, hasMore }`: the list is then fetched as the
 * box opens and as the user types, instead of filtered here. `options`
 * is what shows before any search, such as the option already chosen.
 * `onChange` also gets the option picked, so a caller can read its extras.
 * `clearable={false}` leaves out the empty choice, for a field that always
 * holds one (a sort order, say); `searchable={false}` leaves out the search
 * box, for a list of a few choices. `onDark` draws the closed field for a
 * dark card (translucent, with white text), as in the branch banner.
 */
export default function SearchableSelect({
    id,
    name,
    required = false,
    value,
    onChange,
    options,
    placeholder = '---',
    searchPlaceholder = 'بحث...',
    emptyLabel = 'لا توجد نتائج',
    disabled = false,
    active = false,
    className = '',
    loadOptions = null,
    clearable = true,
    searchable = true,
    portal = false,
    onDark = false,
    onCreate = null,
    createLabel = 'إضافة جديد',
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    // With loadOptions: what the last search found (null before any), whether more matched, and whether one is under way or failed.
    const [found, setFound] = useState(null);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [chosen, setChosen] = useState(null);
    const requestNumber = useRef(0);
    const containerRef = useRef(null);
    const searchRef = useRef(null);
    const buttonRef = useRef(null);
    const popupRef = useRef(null);
    const [popupStyle, setPopupStyle] = useState({});

    useLayoutEffect(() => {
        if (!open || !portal) {
            return;
        }

        function positionPopup() {
            const field = buttonRef.current.getBoundingClientRect();
            const below = window.innerHeight - field.bottom - 12;
            const above = field.top - 12;
            const opensBelow = below >= 290 || below >= above;
            const width = Math.min(field.width, window.innerWidth - 24);
            setPopupStyle({
                position: 'fixed', zIndex: 80, marginTop: 0, width,
                left: Math.max(12, Math.min(field.left, window.innerWidth - width - 12)),
                ...(opensBelow ? { top: field.bottom + 8 } : { bottom: window.innerHeight - field.top + 8 }),
                maxHeight: Math.max(80, (opensBelow ? below : above) - 8),
            });
        }

        positionPopup();
        window.addEventListener('resize', positionPopup);
        window.addEventListener('scroll', positionPopup, true);
        return () => {
            window.removeEventListener('resize', positionPopup);
            window.removeEventListener('scroll', positionPopup, true);
        };
    }, [open, portal]);

    const isMatch = (option) => String(option.value) === String(value);
    const selected = options.find(isMatch) ?? found?.find(isMatch) ?? (chosen && isMatch(chosen) ? chosen : undefined);

    const filtered = useMemo(() => {
        if (loadOptions) {
            return found ?? [];
        }

        const q = query.trim().toLowerCase();
        return q ? options.filter((option) => `${option.label} ${option.hint ?? ''}`.toLowerCase().includes(q)) : options;
    }, [options, query, loadOptions, found]);

    // A new way of searching (another branch picked, say) makes the old results stale.
    useEffect(() => {
        setFound(null);
    }, [loadOptions]);

    // Search as the box opens and as the user types; only the latest search counts.
    useEffect(() => {
        if (!loadOptions || !open) {
            return undefined;
        }

        const request = ++requestNumber.current;
        setLoading(true);
        setFailed(false);

        const timeout = setTimeout(
            () => {
                Promise.resolve(loadOptions(query.trim()))
                    .then((result) => {
                        if (request === requestNumber.current) {
                            setFound(result.options);
                            setHasMore(Boolean(result.hasMore));
                            setLoading(false);
                        }
                    })
                    .catch(() => {
                        if (request === requestNumber.current) {
                            setFailed(true);
                            setLoading(false);
                        }
                    });
            },
            query ? 250 : 0,
        );

        return () => clearTimeout(timeout);
    }, [open, query, loadOptions]);

    useEffect(() => {
        if (!open) {
            return;
        }

        function onPointerDown(event) {
            if (containerRef.current && !containerRef.current.contains(event.target) && !popupRef.current?.contains(event.target)) {
                setOpen(false);
                setQuery('');
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                setOpen(false);
                setQuery('');
                buttonRef.current?.focus();
            }
        }

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown, true);
        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown, true);
        };
    }, [open]);

    useEffect(() => {
        if (open) {
            // A portalled list is placed (fixed, beside the field) only after this first render, so focusing it must not scroll the page to where it was drawn before.
            searchRef.current?.focus({ preventScroll: portal });
        }
    }, [open]);

    function select(option) {
        setChosen(option);
        onChange(option ? String(option.value) : '', option);
        setOpen(false);
        setQuery('');
        buttonRef.current?.focus();
    }

    const renderPopup = (content) => portal ? createPortal(content, document.body) : content;

    return (
        <div ref={containerRef} className={`relative ${className}`}>
            <button
                ref={buttonRef}
                type="button"
                id={id}
                disabled={disabled}
                aria-expanded={open}
                data-active={active ? '' : undefined}
                onClick={() => setOpen((current) => !current)}
                className={
                    onDark
                        ? `flex h-[46px] w-full items-center justify-between gap-2 rounded-control border bg-white/10 px-3.5 text-start text-base leading-6 text-white transition focus:outline-none focus-visible:border-white/50 focus-visible:ring-4 focus-visible:ring-white/15 disabled:cursor-not-allowed disabled:opacity-50 ${
                              open ? 'border-white/50 ring-4 ring-white/15' : active ? 'border-white/35' : 'border-white/15 hover:border-white/35 hover:bg-white/15'
                          }`
                        : `flex h-[42px] w-full items-center justify-between gap-2 rounded-control border bg-surface px-3 text-start text-base leading-6 transition focus:outline-none focus-visible:border-brand-500 focus-visible:ring-4 focus-visible:ring-brand-500/15 disabled:cursor-not-allowed disabled:border-dashed disabled:bg-gray-50 disabled:text-gray-500 ${
                              open ? 'border-brand-500 ring-4 ring-brand-500/15' : active ? 'border-brand-500/60' : 'border-gray-200 hover:border-brand-500/45'
                          }`
                }
            >
                <span className={`flex min-w-0 gap-2 ${selected?.icon ? 'items-center' : 'items-baseline'} ${onDark ? (selected ? 'font-bold text-white' : 'text-white/70') : selected ? 'text-gray-900' : 'text-gray-400/60'}`}>
                    {selected?.icon && <span aria-hidden="true" className="inline-flex shrink-0">{selected.icon}</span>}
                    <span className="truncate">{selected ? selected.label : placeholder}</span>
                    {selected?.hint && <span className={`shrink-0 text-sm ${onDark ? 'text-white/60' : 'text-gray-500'}`}>{selected.hint}</span>}
                </span>
                <Icon name="chevron-down" className={`h-4 w-4 shrink-0 transition ${onDark ? 'text-white/70' : 'text-gray-400'} ${open ? 'rotate-180' : ''}`} />
            </button>

            {required && (
                <input
                    type="text"
                    name={name}
                    required
                    value={value ?? ''}
                    onChange={() => {}}
                    tabIndex={-1}
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 h-full w-full opacity-0"
                    // The form focuses the first missing field; send it on to the drop-down.
                    onFocus={() => buttonRef.current?.focus()}
                />
            )}

            {open && renderPopup(
                <div ref={popupRef} style={portal ? popupStyle : undefined} className="animate-modal-panel absolute z-20 mt-2 flex w-full min-w-[12rem] flex-col overflow-hidden rounded-2xl border border-gray-100 bg-surface shadow-lift">
                    {searchable && (
                        <div className="shrink-0 border-b border-gray-100 p-2">
                            <input
                                ref={searchRef}
                                type="text"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder={searchPlaceholder}
                                className="block w-full py-2 text-sm"
                            />
                        </div>
                    )}
                    {onCreate && <button
                        type="button"
                        onClick={() => { const name = query.trim(); setOpen(false); setQuery(''); onCreate(name); }}
                        className="mx-1.5 mt-1.5 flex items-center gap-2 rounded-lg bg-brand-500/10 px-3 py-2.5 text-start text-sm font-bold text-brand-600 transition hover:bg-brand-500/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500"
                    >
                        <Icon name="plus" className="h-4 w-4 shrink-0" />
                        <span className="min-w-0 truncate">{createLabel}{query.trim() && <>: <bdi>{query.trim()}</bdi></>}</span>
                    </button>}
                    <ul className="min-h-0 max-h-56 overflow-y-auto p-1.5 text-sm">
                        {clearable && (
                            <li>
                                <button
                                    type="button"
                                    onClick={() => select(null)}
                                    className="block w-full rounded-lg px-3 py-2 text-start text-gray-500 hover:bg-gray-50"
                                >
                                    {placeholder}
                                </button>
                            </li>
                        )}
                        {failed ? (
                            <li className="px-3 py-2 text-red-600">تعذّر تحميل الخيارات، حاول مرة أخرى.</li>
                        ) : loading && found === null ? (
                            <li className="px-3 py-2 text-gray-400">جارٍ التحميل...</li>
                        ) : filtered.length === 0 ? (
                            <li className="px-3 py-2 text-gray-400">{emptyLabel}</li>
                        ) : (
                            filtered.map((option) => {
                                const isSelected = String(option.value) === String(value);

                                return (
                                    <li key={option.value}>
                                        <button
                                            type="button"
                                            onClick={() => select(option)}
                                            className={`flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-start hover:bg-gray-50 ${
                                                isSelected ? 'font-semibold text-gray-900' : 'text-gray-700'
                                            }`}
                                        >
                                            <span className="flex min-w-0 items-center gap-2">
                                                {option.icon && <span aria-hidden="true" className="inline-flex shrink-0">{option.icon}</span>}
                                                <span className="truncate">{option.label}</span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                {option.hint && <span className="text-xs text-gray-500">{option.hint}</span>}
                                                {isSelected && <Icon name="check" className="h-4 w-4 shrink-0 text-brand-500" strokeWidth={2} />}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })
                        )}
                    </ul>
                    {loadOptions && hasMore && !failed && (
                        <p className="border-t border-gray-100 px-3 py-2 text-xs text-gray-500">اكتب للبحث عن المزيد من النتائج.</p>
                    )}
                </div>
            )}
        </div>
    );
}
