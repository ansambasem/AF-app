import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/Components/Icon';
import { formatMonthName, localDay } from '@/lib/format';
import { addDays, addMonths, clampDay, isIsoDay, isOutOfRange, monthGrid, sameMonth } from '@/lib/datePicker';
import { WEEK_DAYS, formatWeekDay } from '@/lib/weekDays';

/** Each weekday's usual one-letter mark for the calendar's head, Saturday first. */
const WEEKDAY_INITIALS = WEEK_DAYS.map((day, index) => ({ label: day.label, initial: ['س', 'ح', 'ن', 'ث', 'ر', 'خ', 'ج'][index] }));

const NAVIGATION_KEYS = { ArrowRight: -1, ArrowLeft: 1, ArrowUp: -7, ArrowDown: 7 };

/**
 * A date field in the site's own look, in place of the browser's: the
 * field shows the day as the app writes it ("الخميس 22-10-2026") and opens
 * a calendar, Saturday first, in the theme. `value` and `onChange` use
 * Y-m-d, like a native date field's value; `min` and `max` grey out the
 * days outside them. `required` (with `name`) lets the form's own check
 * report a missing date. The calendar is drawn over everything, so a
 * dialog never cuts it off, and works from the keyboard: arrows move a
 * day or a week, Page Up/Down a month, Enter picks, Escape closes.
 */
export default function DateInput({
    id,
    name,
    value,
    onChange,
    min,
    max,
    required = false,
    disabled = false,
    placeholder = 'اختر تاريخًا',
    className = '',
    onDark = false,
    buttonRef: outerButtonRef = null,
    'aria-label': ariaLabel,
}) {
    const today = localDay();
    const hasValue = isIsoDay(value);
    const [open, setOpen] = useState(false);
    const [view, setView] = useState('days');
    const [focusedDay, setFocusedDay] = useState(() => clampDay(hasValue ? value : today, min, max));
    const ownButtonRef = useRef(null);
    const buttonRef = outerButtonRef ?? ownButtonRef;
    const popoverRef = useRef(null);
    const gridRef = useRef(null);

    function openCalendar() {
        setFocusedDay(clampDay(hasValue ? value : today, min, max));
        setView('days');
        setOpen(true);
    }

    function close(returnFocus = true) {
        setOpen(false);

        if (returnFocus) {
            buttonRef.current?.focus();
        }
    }

    function pick(day) {
        if (isOutOfRange(day, min, max)) {
            return;
        }

        onChange(day);
        close();
    }

    // Placed under the field, or above it when there's no room, kept on screen.
    useLayoutEffect(() => {
        const button = buttonRef.current;
        const popover = popoverRef.current;

        if (!open || !button || !popover) {
            return;
        }

        const box = button.getBoundingClientRect();
        const viewport = document.documentElement.clientWidth;
        const below = box.bottom + 8 + popover.offsetHeight <= window.innerHeight || box.top < popover.offsetHeight + 8;
        popover.style.top = `${below ? box.bottom + 8 : box.top - popover.offsetHeight - 8}px`;
        popover.style.left = `${Math.max(12, Math.min(viewport - popover.offsetWidth - 12, box.right - popover.offsetWidth))}px`;
    }, [open, view]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        function onPointerDown(event) {
            if (!popoverRef.current?.contains(event.target) && !buttonRef.current?.contains(event.target)) {
                close(false);
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                // Only the calendar closes, not the dialog under it.
                event.stopPropagation();
                close();
            }
        }

        function onScroll(event) {
            if (!(event.target instanceof Node) || !popoverRef.current?.contains(event.target)) {
                close(false);
            }
        }

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown, true);
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onScroll);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown, true);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        };
    }, [open]);

    // The keyboard's place follows the focused day.
    useEffect(() => {
        if (open && view === 'days') {
            gridRef.current?.querySelector(`[data-day="${focusedDay}"]`)?.focus();
        }
    }, [open, view, focusedDay]);

    function onGridKeyDown(event) {
        let next = null;

        if (event.key in NAVIGATION_KEYS) {
            next = addDays(focusedDay, NAVIGATION_KEYS[event.key]);
        } else if (event.key === 'PageUp' || event.key === 'PageDown') {
            next = addMonths(focusedDay, (event.key === 'PageDown' ? 1 : -1) * (event.shiftKey ? 12 : 1));
        } else if (event.key === 'Home' || event.key === 'End') {
            const week = monthGrid(focusedDay).find((days) => days.includes(focusedDay));
            next = event.key === 'Home' ? week[0] : week[6];
        }

        if (next) {
            event.preventDefault();
            setFocusedDay(clampDay(next, min, max));
        }
    }

    const shownMonth = focusedDay;
    const year = Number(shownMonth.slice(0, 4));
    const previousMonth = addMonths(shownMonth, -1);
    const nextMonth = addMonths(shownMonth, 1);
    const canGoBack = !min || previousMonth.slice(0, 7) >= min.slice(0, 7);
    const canGoForward = !max || nextMonth.slice(0, 7) <= max.slice(0, 7);

    const headerButton =
        'flex h-9 w-9 items-center justify-center rounded-lg text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 disabled:pointer-events-none disabled:opacity-30';

    const fieldColors = onDark
        ? `bg-white/10 text-white disabled:opacity-40 focus-visible:ring-4 focus-visible:ring-white/15 ${open ? 'border-white/50 ring-4 ring-white/15' : 'border-white/20 hover:border-white/40'}`
        : `bg-surface focus-visible:border-brand-500 focus-visible:ring-4 focus-visible:ring-brand-500/15 disabled:border-dashed disabled:bg-gray-50 disabled:text-gray-500 ${open ? 'border-brand-500 ring-4 ring-brand-500/15' : 'border-gray-200 hover:border-brand-500/45'}`;

    return (
        <div className={`relative ${className}`}>
            <button
                ref={buttonRef}
                type="button"
                id={id}
                disabled={disabled}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={ariaLabel ? `${ariaLabel}${hasValue ? `: ${formatWeekDay(value)}` : ''}` : undefined}
                onClick={() => (open ? close() : openCalendar())}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown' && !open) {
                        event.preventDefault();
                        openCalendar();
                    }
                }}
                className={`flex h-[42px] w-full items-center justify-between gap-2 rounded-control border px-3 text-start text-base leading-6 transition focus:outline-none disabled:cursor-not-allowed ${fieldColors}`}
            >
                <span className={`truncate ${onDark ? (hasValue ? 'text-white' : 'text-white/60') : (hasValue ? 'text-gray-900' : 'text-gray-400/60')}`}>{hasValue ? formatWeekDay(value) : placeholder}</span>
                <Icon name="calendar" className={`h-[18px] w-[18px] shrink-0 ${onDark ? 'text-white/60' : 'text-gray-400'}`} />
            </button>

            {name && (
                <input
                    type="text"
                    name={name}
                    required={required}
                    value={hasValue ? value : ''}
                    onChange={() => {}}
                    tabIndex={-1}
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 h-full w-full opacity-0"
                    // The form focuses the first missing field; send it on to the date field.
                    onFocus={() => buttonRef.current?.focus()}
                />
            )}

            {open &&
                createPortal(
                    <div
                        ref={popoverRef}
                        role="dialog"
                        aria-label="اختيار التاريخ"
                        // Lets a popover holding this field (the ledger's custom range) treat the calendar as its own.
                        data-date-picker=""
                        dir="rtl"
                        className="animate-menu fixed z-[70] w-[19.5rem] rounded-2xl border border-gray-100 bg-surface p-3 text-gray-900 shadow-lift"
                    >
                        <div className="mb-2 flex items-center justify-between gap-2">
                            <button
                                type="button"
                                className={headerButton}
                                aria-label={view === 'days' ? 'الشهر السابق' : 'السنة السابقة'}
                                disabled={view === 'days' ? !canGoBack : Boolean(min) && year - 1 < Number(min.slice(0, 4))}
                                onClick={() => setFocusedDay(clampDay(addMonths(focusedDay, view === 'days' ? -1 : -12), min, max))}
                            >
                                <Icon name="chevron-right" className="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                aria-label={view === 'days' ? 'اختيار الشهر والسنة' : 'العودة إلى الأيام'}
                                onClick={() => setView(view === 'days' ? 'months' : 'days')}
                                className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[15px] font-bold transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"
                            >
                                {view === 'days' && formatMonthName(shownMonth)}
                                <span className="font-display">{year}</span>
                                <Icon name="chevron-down" className={`h-4 w-4 text-gray-400 transition ${view === 'months' ? 'rotate-180' : ''}`} />
                            </button>
                            <button
                                type="button"
                                className={headerButton}
                                aria-label={view === 'days' ? 'الشهر التالي' : 'السنة التالية'}
                                disabled={view === 'days' ? !canGoForward : Boolean(max) && year + 1 > Number(max.slice(0, 4))}
                                onClick={() => setFocusedDay(clampDay(addMonths(focusedDay, view === 'days' ? 1 : 12), min, max))}
                            >
                                <Icon name="chevron-left" className="h-5 w-5" />
                            </button>
                        </div>

                        {view === 'days' ? (
                            <div ref={gridRef} role="grid" aria-label={`${formatMonthName(shownMonth)} ${year}`} onKeyDown={onGridKeyDown}>
                                <div role="row" className="grid grid-cols-7 pb-1">
                                    {WEEKDAY_INITIALS.map((day) => (
                                        <abbr key={day.label} role="columnheader" title={day.label} className="py-1 text-center text-xs font-semibold text-gray-500 no-underline">
                                            {day.initial}
                                        </abbr>
                                    ))}
                                </div>
                                {monthGrid(shownMonth).map((week) => (
                                    <div key={week[0]} role="row" className="grid grid-cols-7 gap-0.5">
                                        {week.map((day) => {
                                            const selected = hasValue && day === value;
                                            const blocked = isOutOfRange(day, min, max);
                                            const inMonth = sameMonth(day, shownMonth);

                                            return (
                                                <button
                                                    key={day}
                                                    type="button"
                                                    role="gridcell"
                                                    data-day={day}
                                                    tabIndex={day === focusedDay ? 0 : -1}
                                                    aria-selected={selected}
                                                    aria-current={day === today ? 'date' : undefined}
                                                    aria-label={formatWeekDay(day)}
                                                    disabled={blocked}
                                                    onClick={() => pick(day)}
                                                    onKeyDown={(event) => {
                                                        if (event.key === 'Enter' || event.key === ' ') {
                                                            event.preventDefault();
                                                            pick(day);
                                                        }
                                                    }}
                                                    className={`relative flex h-10 items-center justify-center rounded-lg font-display text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-gray-900 disabled:cursor-not-allowed disabled:opacity-25 ${
                                                        selected
                                                            ? 'bg-gray-900 font-bold text-surface shadow-sm'
                                                            : `${inMonth ? 'text-gray-800' : 'text-gray-400'} hover:bg-gray-100 ${day === today ? 'font-bold text-brand-600' : ''}`
                                                    }`}
                                                >
                                                    {Number(day.slice(8))}
                                                    {day === today && !selected && <span aria-hidden="true" className="absolute bottom-1.5 h-1 w-1 rounded-full bg-brand-500" />}
                                                </button>
                                            );
                                        })}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="grid grid-cols-3 gap-1.5 py-1">
                                {Array.from({ length: 12 }, (_, index) => {
                                    const month = `${year}-${String(index + 1).padStart(2, '0')}`;
                                    const blocked = Boolean((min && month < min.slice(0, 7)) || (max && month > max.slice(0, 7)));
                                    const current = shownMonth.slice(0, 7) === month;

                                    return (
                                        <button
                                            key={month}
                                            type="button"
                                            disabled={blocked}
                                            onClick={() => {
                                                // The same day in the month picked (held to its last day).
                                                setFocusedDay(clampDay(addMonths(shownMonth, index + 1 - Number(shownMonth.slice(5, 7))), min, max));
                                                setView('days');
                                            }}
                                            className={`h-11 rounded-lg text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 disabled:cursor-not-allowed disabled:opacity-25 ${
                                                current ? 'bg-gray-900 text-surface' : 'text-gray-700 hover:bg-gray-100'
                                            }`}
                                        >
                                            {formatMonthName(`${month}-01`)}
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        <div className="mt-2 flex items-center justify-between gap-2 border-t border-gray-100 pt-2">
                            <button
                                type="button"
                                disabled={isOutOfRange(today, min, max)}
                                onClick={() => pick(today)}
                                className="rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-900 transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 disabled:opacity-30"
                            >
                                اليوم
                            </button>
                            {!required && hasValue && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        onChange('');
                                        close();
                                    }}
                                    className="rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"
                                >
                                    مسح
                                </button>
                            )}
                        </div>
                    </div>,
                    document.body,
                )}
        </div>
    );
}
