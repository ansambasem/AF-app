import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/Components/Icon';
import { initials } from '@/lib/format';

const MENU_WIDTH = 290;

/**
 * The colours of an item's icon tile, by its `tone`: a soft tint of the
 * tone at rest, filled solid when the item is hovered or focused. Written
 * out whole so Tailwind keeps every class.
 */
const TONES = {
    graphite: {
        rest: 'bg-gray-100 text-gray-700',
        active: 'group-hover/item:bg-graphite-gradient group-focus-visible/item:bg-graphite-gradient',
    },
    blue: {
        rest: 'bg-blue-500/10 text-blue-600',
        active: 'group-hover/item:bg-blue-600 group-focus-visible/item:bg-blue-600',
    },
    indigo: {
        rest: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
        active: 'group-hover/item:bg-indigo-600 group-focus-visible/item:bg-indigo-600',
    },
    sky: {
        rest: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
        active: 'group-hover/item:bg-sky-600 group-focus-visible/item:bg-sky-600',
    },
    emerald: {
        rest: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        active: 'group-hover/item:bg-emerald-600 group-focus-visible/item:bg-emerald-600',
    },
    amber: {
        rest: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        active: 'group-hover/item:bg-amber-600 group-focus-visible/item:bg-amber-600',
    },
    red: {
        rest: 'bg-red-500/10 text-red-700 dark:text-red-400',
        active: 'group-hover/item:bg-red-700 group-focus-visible/item:bg-red-700',
    },
    violet: {
        rest: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        active: 'group-hover/item:bg-violet-600 group-focus-visible/item:bg-violet-600',
    },
    teal: {
        rest: 'bg-teal-500/10 text-teal-600 dark:text-teal-400',
        active: 'group-hover/item:bg-teal-600 group-focus-visible/item:bg-teal-600',
    },
    brand: {
        rest: 'bg-brand-500/10 text-brand-600',
        active: 'group-hover/item:bg-brand-gradient group-focus-visible/item:bg-brand-gradient',
    },
};
/**
 * A colourful menu's items (`menu.colorful`): each row softly tinted in
 * its tone with its title in that tone and its icon tile filled solid, so
 * every action reads at a glance. Written out whole so Tailwind keeps them.
 */
const COLORFUL = {
    graphite: { row: 'bg-gray-500/[0.06] hover:bg-gray-500/[0.14] focus-visible:bg-gray-500/[0.14]', label: 'text-gray-900', tile: 'bg-graphite-gradient text-white' },
    blue: { row: 'bg-blue-500/[0.07] hover:bg-blue-500/[0.15] focus-visible:bg-blue-500/[0.15]', label: 'text-blue-700 dark:text-blue-500', tile: 'bg-blue-600 text-white' },
    indigo: { row: 'bg-indigo-500/[0.07] hover:bg-indigo-500/[0.15] focus-visible:bg-indigo-500/[0.15]', label: 'text-indigo-700 dark:text-indigo-300', tile: 'bg-indigo-600 text-white' },
    sky: { row: 'bg-sky-500/[0.07] hover:bg-sky-500/[0.15] focus-visible:bg-sky-500/[0.15]', label: 'text-sky-700 dark:text-sky-300', tile: 'bg-sky-600 text-white' },
    emerald: { row: 'bg-emerald-500/[0.07] hover:bg-emerald-500/[0.15] focus-visible:bg-emerald-500/[0.15]', label: 'text-emerald-700 dark:text-emerald-300', tile: 'bg-emerald-600 text-white' },
    amber: { row: 'bg-amber-500/[0.08] hover:bg-amber-500/[0.16] focus-visible:bg-amber-500/[0.16]', label: 'text-amber-800 dark:text-amber-300', tile: 'bg-amber-500 text-white' },
    red: { row: 'bg-red-500/[0.07] hover:bg-red-500/[0.15] focus-visible:bg-red-500/[0.15]', label: 'text-red-700 dark:text-red-400', tile: 'bg-red-600 text-white' },
    violet: { row: 'bg-violet-500/[0.07] hover:bg-violet-500/[0.15] focus-visible:bg-violet-500/[0.15]', label: 'text-violet-700 dark:text-violet-300', tile: 'bg-violet-600 text-white' },
    teal: { row: 'bg-teal-500/[0.07] hover:bg-teal-500/[0.15] focus-visible:bg-teal-500/[0.15]', label: 'text-teal-700 dark:text-teal-300', tile: 'bg-teal-600 text-white' },
    brand: { row: 'bg-brand-500/[0.07] hover:bg-brand-500/[0.15] focus-visible:bg-brand-500/[0.15]', label: 'text-brand-600', tile: 'bg-brand-gradient text-white' },
};
const GAP = 6;

/** Phones get the menu as a sheet rising from the bottom of the screen. */
function isPhone() {
    return window.matchMedia('(max-width: 639px)').matches;
}

/**
 * Where the menu opens beside its button: under it, or over it when there
 * is no room below, with its left edge on the button's (the row's end in
 * right-to-left), kept on screen.
 */
function placeBeside(anchor, menuElement, width, point) {
    if (point) {
        const maxHeight = window.innerHeight - GAP * 2;
        const height = Math.min(menuElement.offsetHeight, maxHeight);

        return {
            top: Math.max(GAP, Math.min(point.y, window.innerHeight - height - GAP)),
            left: Math.max(GAP, Math.min(point.x, window.innerWidth - width - GAP)),
            maxHeight,
        };
    }

    const rect = anchor.getBoundingClientRect();
    const height = menuElement.offsetHeight;
    const below = window.innerHeight - rect.bottom - GAP;
    const top = below >= height || below >= rect.top ? rect.bottom + GAP : Math.max(GAP, rect.top - GAP - height);
    const left = Math.min(Math.max(GAP, rect.left), window.innerWidth - width - GAP);

    return { top, left, maxHeight: below >= height || below >= rect.top ? below : rect.top - GAP * 2 };
}

/**
 * A row's "more" menu: a header naming the record, then its actions in
 * titled groups, each with its own icon. `menu` is
 * { title, description?, subtitle, width?, groups: [{ label, items: [{ label, description?, icon, onSelect, tone?, shortcut?, hint?, disabled?, lockedReason? }] }] }.
 * `tone` (see TONES) tints the icon tile at rest, filling it solid on hover/focus; graphite by default.
 * `colorful` tints each whole row in its tone instead (see COLORFUL), for menus whose actions differ a lot.
 * An item with `lockedReason` is shown faded with a lock ("بدون صلاحية")
 * and the reason as its tooltip; `disabled` with `hint` explains why it
 * can't be used now. Arrow keys move, Enter runs, a shortcut letter runs
 * its item, Esc or a click outside closes and returns focus to `anchor`.
 */
export default function RowMenu({ anchor, point = null, contextual = false, menu, onClose }) {
    const menuRef = useRef(null);
    const [phone] = useState(isPhone);
    const [position, setPosition] = useState(null);
    const items = menu.groups.flatMap((group) => group.items);
    const width = menu.width ?? MENU_WIDTH;

    // Hidden until placed, and a hidden item can't take focus: focus the first one once it shows.
    const placed = phone || position !== null;

    useLayoutEffect(() => {
        if (!phone) {
            setPosition(placeBeside(anchor, menuRef.current, width, point));
        }
    }, [anchor, phone, width, point]);

    useEffect(() => {
        if (placed) {
            const firstItem = menuRef.current?.querySelector('[role="menuitem"]:not([aria-disabled="true"])');
            (firstItem ?? menuRef.current)?.focus({ preventScroll: true });
        }
    }, [placed]);

    useEffect(() => {
        const anchorPosition = anchor.getBoundingClientRect();

        function onPointerDown(event) {
            if (!menuRef.current?.contains(event.target) && (contextual || !anchor.contains(event.target))) {
                onClose(false);
            }
        }

        // The menu is placed once; it closes instead of drifting away when the page moves.
        function onMove(event) {
            if (event.target instanceof Node && menuRef.current?.contains(event.target)) {
                return;
            }

            const current = anchor.getBoundingClientRect();
            if (event.type === 'resize' || current.top !== anchorPosition.top || current.left !== anchorPosition.left) {
                onClose(false);
            }
        }

        document.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('resize', onMove);
        if (!phone) {
            window.addEventListener('scroll', onMove, true);
        }

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            window.removeEventListener('resize', onMove);
            window.removeEventListener('scroll', onMove, true);
        };
    }, [anchor, onClose, phone, contextual]);

    function run(item) {
        if (item.disabled || item.lockedReason) {
            return;
        }

        onClose(false);
        item.onSelect();
    }

    function onKeyDown(event) {
        const enabled = [...menuRef.current.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])')];
        const index = enabled.indexOf(document.activeElement);
        const move = { ArrowDown: 1, ArrowUp: -1 }[event.key];

        if (move) {
            event.preventDefault();
            enabled[(index + move + enabled.length) % enabled.length]?.focus();
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            enabled[event.key === 'Home' ? 0 : enabled.length - 1]?.focus();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            onClose(true);
        } else if (event.key === 'Tab') {
            onClose(false);
        } else if (!event.ctrlKey && !event.metaKey && !event.altKey && event.key.length === 1) {
            const item = items.find((candidate) => candidate.shortcut?.toLowerCase() === event.key.toLowerCase());

            if (item) {
                event.preventDefault();
                run(item);
            }
        }
    }

    const list = (
        <div
            ref={menuRef}
            role="menu"
            tabIndex={-1}
            aria-label={`إجراءات ${menu.title}`}
            onKeyDown={onKeyDown}
            style={
                phone
                    ? undefined
                    : {
                          top: position?.top ?? 0,
                          left: position?.left ?? 0,
                          maxHeight: position?.maxHeight,
                          width,
                          visibility: position ? 'visible' : 'hidden',
                      }
            }
            className={
                phone
                    ? 'animate-sheet fixed inset-x-0 bottom-0 z-50 max-h-[80dvh] overflow-y-auto rounded-t-[24px] border-t border-gray-200 bg-surface p-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-lift'
                    : 'animate-menu fixed z-50 overflow-y-auto rounded-[20px] border border-gray-200 bg-surface p-2 shadow-lift'
            }
        >
            {phone && <div className="mx-auto mb-1 mt-1 h-1 w-10 rounded-full bg-gray-200" aria-hidden="true" />}

            <div className="mb-1 flex items-center gap-2.5 border-b border-gray-100 px-2 pb-3 pt-2">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-graphite-gradient font-display text-[12px] font-bold text-white dark:ring-1 dark:ring-white/10">
                    {initials(menu.title)}
                </span>
                <span className="min-w-0">
                    <b className={`block text-sm text-gray-900 ${menu.description ? 'whitespace-normal break-words' : 'truncate'}`}>{menu.title}</b>
                    {menu.description && <span className="mt-1 block whitespace-pre-wrap break-words text-sm leading-relaxed text-gray-700">{menu.description}</span>}
                    {menu.subtitle && (
                        <span className="block truncate text-xs text-gray-500" dir="ltr" style={{ textAlign: 'right' }}>
                            {menu.subtitle}
                        </span>
                    )}
                </span>
            </div>

            {menu.groups.map((group) => (
                <div key={group.label} role="group" aria-label={group.label}>
                    <p className="px-2.5 pb-0.5 pt-2 text-[12px] font-bold text-gray-500" aria-hidden="true">
                        {group.label}
                    </p>
                    {group.items.map((item) => {
                        const unavailable = Boolean(item.disabled || item.lockedReason);
                        const tone = TONES[item.tone] ?? TONES.graphite;
                        const colorful = menu.colorful ? (COLORFUL[item.tone] ?? COLORFUL.graphite) : null;

                        return (
                            <button
                                key={item.label}
                                type="button"
                                role="menuitem"
                                tabIndex={-1}
                                aria-disabled={unavailable || undefined}
                                aria-keyshortcuts={item.shortcut}
                                title={item.lockedReason ?? item.hint}
                                onClick={() => run(item)}
                                className={`group/item flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-start text-sm font-medium outline-none transition ${
                                    unavailable
                                        ? 'cursor-not-allowed opacity-55'
                                        : colorful
                                          ? `${colorful.row} mb-1 last:mb-0`
                                          : 'text-gray-900 hover:bg-gray-100 focus-visible:bg-gray-100'
                                }`}
                            >
                                <span
                                    className={`flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[10px] transition ${
                                        unavailable
                                            ? 'bg-gray-100 text-gray-500'
                                            : colorful
                                              ? `${colorful.tile} shadow-sm`
                                              : `${tone.rest} ${tone.active} group-hover/item:text-white group-focus-visible/item:text-white`
                                    }`}
                                >
                                    <Icon name={item.icon} className="h-[17px] w-[17px]" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className={`block truncate ${colorful && !unavailable ? `font-semibold ${colorful.label}` : ''}`}>{item.label}</span>
                                    {item.description && <span className="block text-[12px] font-normal leading-tight text-gray-500">{item.description}</span>}
                                </span>
                                {item.lockedReason ? (
                                    <span className="inline-flex shrink-0 items-center gap-1 text-[12px] text-gray-500">
                                        <Icon name="lock" className="h-3.5 w-3.5" />
                                        بدون صلاحية
                                    </span>
                                ) : item.disabled && item.hint ? (
                                    <span className="shrink-0 text-[12px] text-gray-500">{item.hint}</span>
                                ) : (
                                    item.shortcut &&
                                    !phone && (
                                        <kbd className="kbd shrink-0" dir="ltr">
                                            {item.shortcut}
                                        </kbd>
                                    )
                                )}
                            </button>
                        );
                    })}
                </div>
            ))}
        </div>
    );

    return createPortal(
        phone ? (
            <>
                <div className="animate-modal-backdrop fixed inset-0 z-50 bg-graphite-900/50" aria-hidden="true" />
                {list}
            </>
        ) : (
            list
        ),
        document.body,
    );
}
