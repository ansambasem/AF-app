import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { router, useHttp } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { formatMoney } from '@/lib/format';
import { balanceInText, balanceTone } from '@/lib/balance';

const SEARCH_DELAY = 200;

/** Arabic text compared loosely: without diacritics or tatweel, alef forms, taa marbuta and alef maqsura folded. */
export function normalizeSearch(text) {
    return String(text ?? '')
        .replace(/[ً-ْـ]/g, '')
        .replace(/[أإآ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .toLowerCase();
}

/** The text with the searched part marked. */
function Highlighted({ text, query }) {
    const trimmed = query.trim();
    const index = trimmed ? normalizeSearch(text).indexOf(normalizeSearch(trimmed)) : -1;

    if (index < 0) {
        return text;
    }

    return <>{text.slice(0, index)}<mark>{text.slice(index, index + trimmed.length)}</mark>{text.slice(index + trimmed.length)}</>;
}

/**
 * Search and commands (Ctrl+K, ⌘K or / from any page): every page the user
 * may open by section, then commands; typing narrows them and, for staff who
 * take payments, finds subscribers by name or account. Arrows move, Enter opens.
 * `sections` are [title, links] pairs; `commands` are {id, label, icon, key}.
 */
export default function CommandPalette({ open, onOpenChange, sections, commands, onCommand, searchSubscribers }) {
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const [subscribers, setSubscribers] = useState([]);
    const inputRef = useRef(null);
    const dialogRef = useRef(null);
    const returnFocus = useRef(null);
    const http = useHttp();
    const httpRef = useRef(http);
    httpRef.current = http;

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        returnFocus.current = document.activeElement;
        setQuery('');
        setActiveIndex(0);
        setSubscribers([]);
        document.documentElement.classList.add('tn-locked');
        inputRef.current?.focus();

        return () => {
            document.documentElement.classList.remove('tn-locked');

            if (returnFocus.current?.isConnected) {
                returnFocus.current.focus({ preventScroll: true });
            }
        };
    }, [open]);

    // Subscribers whose name or account matches, once two letters are typed.
    useEffect(() => {
        const term = query.trim();

        if (!open || !searchSubscribers || term.length < 2) {
            setSubscribers([]);

            return undefined;
        }

        // An answer to an older search is ignored once the search has changed.
        let current = true;
        const timer = setTimeout(() => {
            httpRef.current
                .get(`/payments/search?${new URLSearchParams({ search: term })}`)
                .then((result) => current && setSubscribers(result.subscriptions.slice(0, 4)))
                .catch(() => current && setSubscribers([]));
        }, SEARCH_DELAY);

        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [open, query, searchSubscribers]);

    const groups = useMemo(() => {
        const q = normalizeSearch(query.trim());
        const hit = (text) => !q || normalizeSearch(text).includes(q);
        const result = [];

        if (!q) {
            sections.forEach(([title, links]) => result.push([title, links.map((link) => ({ kind: 'page', ...link }))]));
        } else {
            const pages = sections.flatMap(([, links]) => links).filter((link) => hit(link.label) || hit(link.description ?? ''));
            const unique = [...new Map(pages.map((link) => [link.href, link])).values()];

            if (unique.length) {
                result.push(['الصفحات', unique.slice(0, 7).map((link) => ({ kind: 'page', ...link }))]);
            }
        }

        const matchingCommands = commands.filter((command) => hit(command.label)).map((command) => ({ kind: 'command', ...command }));

        if (matchingCommands.length) {
            result.push(['أوامر', matchingCommands]);
        }

        if (subscribers.length) {
            result.push(['المشتركون', subscribers.map((subscriber) => ({
                kind: 'subscriber',
                id: subscriber.id,
                label: subscriber.fullName,
                description: `حساب ${subscriber.accountNumber}${balanceTone(subscriber.balance) !== 'settled' ? ` · الرصيد ${balanceInText(subscriber.balance)}` : ''}`,
                icon: 'user',
            }))]);
        }

        return result;
    }, [sections, commands, subscribers, query]);

    const items = groups.flatMap(([, entries]) => entries);
    const active = Math.min(activeIndex, Math.max(0, items.length - 1));

    useEffect(() => {
        dialogRef.current?.querySelector('.tn-palette-item.is-active')?.scrollIntoView({ block: 'nearest' });
    }, [active, open]);

    if (!open) {
        return null;
    }

    function run(item) {
        if (!item) {
            return;
        }

        onOpenChange(false);

        if (item.kind === 'page') {
            router.visit(item.href);
        } else if (item.kind === 'subscriber') {
            router.visit(`/subscriptions/${item.id}/statement`);
        } else {
            onCommand(item.id);
        }
    }

    function onKeyDown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            onOpenChange(false);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const count = items.length || 1;
            setActiveIndex((active + (event.key === 'ArrowDown' ? 1 : -1) + count) % count);
        } else if (event.key === 'Enter' && event.target === inputRef.current) {
            event.preventDefault();
            run(items[active]);
        } else if (event.key === 'Tab') {
            const focusable = [...dialogRef.current.querySelectorAll('button, input')].filter((element) => element.offsetParent);

            if (event.shiftKey && document.activeElement === focusable[0]) {
                event.preventDefault();
                focusable.at(-1).focus();
            } else if (!event.shiftKey && document.activeElement === focusable.at(-1)) {
                event.preventDefault();
                focusable[0].focus();
            }
        }
    }

    let position = 0;

    return (
        <div className="tn-scrim flex items-start justify-center px-4 pt-[11vh]" dir="rtl" onClick={(event) => event.target === event.currentTarget && onOpenChange(false)}>
            <div
                ref={dialogRef}
                className="tn-palette flex max-h-[min(580px,80dvh)] w-full max-w-[660px] flex-col overflow-hidden rounded-[26px] border border-gray-200/70 bg-surface text-gray-900 tn-lift"
                role="dialog"
                aria-modal="true"
                data-top-bar-layer
                aria-label="بحث وأوامر"
                onKeyDown={onKeyDown}
            >
                <div className="flex items-center gap-3 border-b border-gray-200/70 px-5 py-4">
                    <Icon name="search" className="h-5 w-5 text-gray-400" />
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setActiveIndex(0);
                        }}
                        placeholder={searchSubscribers ? 'اكتب اسم صفحة، أو اسم مشترك، أو أمراً…' : 'اكتب اسم صفحة أو أمراً…'}
                        autoComplete="off"
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="tn-palette-list"
                        aria-activedescendant={items.length ? `tn-palette-item-${active}` : undefined}
                        className="min-w-0 flex-1 border-0 bg-transparent p-0 text-[17px] shadow-none outline-none placeholder:text-gray-400 focus:border-0 focus:ring-0"
                    />
                    <span className="tn-kbd">Esc</span>
                </div>

                <div id="tn-palette-list" className="flex-1 overflow-y-auto p-2" role="listbox">
                    {items.length === 0 ? (
                        <div className="grid justify-items-center gap-2 px-6 py-10 text-center text-gray-500">
                            <Icon name="search" className="h-8 w-8 text-gray-300" />
                            <b className="text-gray-900">لا نتائج لـ «{query}»</b>
                            <small>{searchSubscribers ? 'جرّب اسم صفحة مثل «الصناديق» أو اسم مشترك' : 'جرّب اسم صفحة مثل «الصناديق»'}</small>
                        </div>
                    ) : groups.map(([title, entries]) => (
                        <Fragment key={title}>
                            <div className="px-2.5 pb-1 pt-3 text-[12px] font-bold text-gray-500">{title}</div>
                            {entries.map((item) => {
                                const index = position++;

                                return (
                                    <button
                                        key={`${item.kind}-${item.href ?? item.id}`}
                                        type="button"
                                        id={`tn-palette-item-${index}`}
                                        role="option"
                                        aria-selected={index === active}
                                        className={`tn-palette-item ${index === active ? 'is-active' : ''}`}
                                        onClick={() => run(item)}
                                    >
                                        <span className="tn-tile"><Icon name={item.icon} /></span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate"><Highlighted text={item.label} query={query} /></span>
                                            {item.description && <small className="block truncate text-[12.5px] text-gray-500">{item.description}</small>}
                                        </span>
                                        {item.key && <span className="tn-kbd">{item.key}</span>}
                                        <Icon name="arrow-left" className="tn-enter" />
                                    </button>
                                );
                            })}
                        </Fragment>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-4 border-t border-gray-200/70 bg-gray-50 px-4 py-2.5 text-[12.5px] text-gray-500">
                    <span className="inline-flex items-center gap-1.5"><span className="tn-kbd">↑ ↓</span>اختيار</span>
                    <span className="inline-flex items-center gap-1.5"><span className="tn-kbd">Enter</span>فتح</span>
                    <span className="ms-auto">{searchSubscribers ? 'يبحث في الصفحات والمشتركين والأوامر' : 'يبحث في الصفحات والأوامر'}</span>
                </div>
            </div>
        </div>
    );
}
