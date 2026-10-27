import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import ActivityBell from '@/Components/ActivityBell';
import { shouldPrefetchPage, HOME_LINK, currentGroup, groupLandingLink, isActiveLink, topBarItems } from '@/lib/navigation';
import MegaMenu, { megaWidth } from './MegaMenu';
import AccountMenu from './AccountMenu';
import { pageWindowOpen } from './shortcuts';
import './top-bar.css';

/** The app name without the "AF" the logo already shows. */
export function shortAppName(appName) {
    return (appName ?? '').replace(/^AF\s+/i, '');
}

/** How many things wait in a section's pages, from the page's badges. */
function badgeOf(group, badges) {
    return group.links.reduce((sum, link) => sum + (badges[link.href] > 0 ? badges[link.href] : 0), 0);
}

/**
 * The floating glass bar at the top of every page: the logo, the sections in
 * the middle with a dark light that follows the pointer, and the tools (search,
 * activity, account; the menu button on phones). Once the page's title has
 * scrolled away, `where` ({ section, page, scope? }) takes the branch's place
 * under the name, and the page's name sits beside the logo on phones, so the
 * user still sees where they are — and, with `scope`, whose figures they read. A section's pages open in one panel under it
 * that reshapes itself as the pointer moves between sections; a mouse click
 * on a section opens its first page, while touch and the keyboard, with no
 * hover, open the panel.
 */
export default function TopBar({ groups, badges, where, onOpenPalette, onOpenDrawer, drawerOpen, layerOpen, onShowShortcuts, onStartTour }) {
    const { props, url } = usePage();
    const { appName, auth } = props;
    const user = auth?.user;
    const items = topBarItems(groups);
    const current = currentGroup(groups, url);
    const pageLabel = where ? (where.scope ? [where.page, where.scope] : [where.section, where.page]).filter(Boolean).join(' · ') : null;

    const [scrolled, setScrolled] = useState(false);
    const [hoverKey, setHoverKey] = useState(null);
    const [openId, setOpenId] = useState(null);
    const [pinned, setPinned] = useState(false);
    const [popover, setPopover] = useState(null);
    const [panels, setPanels] = useState([]);
    const [still, setStill] = useState(false);
    const [geometry, setGeometry] = useState({ width: 420, height: 300, x: 0, top: 92 });

    const barRef = useRef(null);
    const navRef = useRef(null);
    const highlightRef = useRef(null);
    const megaRef = useRef(null);
    const itemRefs = useRef(new Map());
    const panelRef = useRef(null);
    const timers = useRef({ open: null, close: null, leaving: new Set() });
    const focusedPanel = useRef(null);
    const pointerType = useRef(null);
    const panelsRef = useRef([]);
    panelsRef.current = panels;
    const state = useRef({ openId: null, pinned: false, popover: null, popoverY: 0 });
    state.current = { ...state.current, openId, pinned, popover, layerOpen };

    const currentKey = groups.length === 1
        ? items.find((item) => item.link && isActiveLink(item.link, url))?.key
        : isActiveLink(HOME_LINK, url) ? 'home' : current?.id;
    const litKey = hoverKey ?? openId ?? currentKey;

    const clearTimers = useCallback(() => {
        clearTimeout(timers.current.open);
        clearTimeout(timers.current.close);
    }, []);

    const closeMega = useCallback((returnFocus = false) => {
        clearTimeout(timers.current.open);
        const id = state.current.openId;

        if (!id) {
            return;
        }

        setOpenId(null);
        setPinned(false);

        if (returnFocus) {
            itemRefs.current.get(id)?.focus();
        }
    }, []);

    const openMega = useCallback((id, { pin = false, focus = false } = {}) => {
        const group = groups.find((candidate) => candidate.id === id);
        const button = itemRefs.current.get(id);

        if (!group || !button) {
            return;
        }

        setPopover(null);

        if (state.current.openId === id) {
            if (pin) {
                setPinned(true);
            }

            if (focus) {
                megaRef.current?.querySelector('.tn-mega-content:not(.is-leaving) .tn-feature, .tn-mega-content:not(.is-leaving) .tn-mega-link')?.focus();
            }

            return;
        }

        const first = !state.current.openId;
        const previousIndex = groups.findIndex((candidate) => candidate.id === state.current.openId);
        const index = groups.findIndex((candidate) => candidate.id === id);
        const shift = first ? 0 : index > previousIndex ? -24 : 24;
        const width = megaWidth(group);
        const buttonBox = button.getBoundingClientRect();
        const viewport = document.documentElement.clientWidth;
        const x = Math.max(16, Math.min(viewport - width - 16, buttonBox.left + buttonBox.width / 2 - width / 2));

        setStill(first);
        setGeometry((previous) => ({ ...previous, width, x, top: barRef.current.getBoundingClientRect().bottom + 10 }));
        // Each panel fades out for 200ms from the moment it starts leaving, then goes.
        if (!first) {
            panelsRef.current.filter((panel) => !panel.leaving).forEach((panel) => {
                const timer = setTimeout(() => {
                    timers.current.leaving.delete(timer);
                    setPanels((current) => current.filter((candidate) => candidate.key !== panel.key));
                }, 200);
                timers.current.leaving.add(timer);
            });
        }

        setPanels((previous) => [
            ...(first ? [] : previous.map((panel) => (panel.leaving ? panel : { ...panel, leaving: true, shift }))),
            { id, key: `${id}-${Date.now()}`, shift, leaving: false, focus },
        ]);
        setOpenId(id);
        setPinned(pin);
    }, [groups]);

    // The panel takes the height of the section it shows, and focuses its first page when opened from the keyboard.
    useLayoutEffect(() => {
        const content = panelRef.current;

        if (!content) {
            return undefined;
        }

        setGeometry((previous) => ({ ...previous, height: content.offsetHeight }));
        const observer = new ResizeObserver(() => setGeometry((previous) => (previous.height === content.offsetHeight ? previous : { ...previous, height: content.offsetHeight })));
        observer.observe(content);

        const newest = panels.at(-1);
        if (newest?.focus && focusedPanel.current !== newest.key) {
            focusedPanel.current = newest.key;
            requestAnimationFrame(() => content.querySelector('.tn-feature, .tn-mega-link')?.focus());
        }

        return () => observer.disconnect();
    }, [panels]);

    // On the first open the panel appears at its size, then reshapes smoothly from there.
    useEffect(() => {
        if (!still) {
            return undefined;
        }

        const frame = requestAnimationFrame(() => setStill(false));

        return () => cancelAnimationFrame(frame);
    }, [still]);

    // The dark light sits under the hovered, open or current section.
    const syncHighlight = useCallback((instant = false) => {
        const highlight = highlightRef.current;
        const element = itemRefs.current.get(litKey);

        if (!highlight) {
            return;
        }

        if (!element || !element.offsetWidth) {
            highlight.style.opacity = 0;

            return;
        }

        if (instant || highlight.style.opacity !== '1') {
            highlight.style.transition = 'none';
            requestAnimationFrame(() => {
                highlight.style.transition = '';
            });
        }

        highlight.style.width = `${element.offsetWidth}px`;
        highlight.style.transform = `translateX(${element.offsetLeft}px)`;
        highlight.style.opacity = 1;
    }, [litKey]);

    useLayoutEffect(() => syncHighlight(), [syncHighlight]);

    // A change of size or font puts the light in place at once; set up once, so it never cuts a slide short.
    const syncRef = useRef(syncHighlight);
    syncRef.current = syncHighlight;

    useEffect(() => {
        const nav = navRef.current;

        if (!nav) {
            return undefined;
        }

        const observer = new ResizeObserver(() => syncRef.current(true));
        observer.observe(nav);
        document.fonts?.ready.then(() => syncRef.current(true));

        return () => observer.disconnect();
    }, []);

    // A window over the page (search, keys, tour, the phone menu) closes the bar's menus.
    useEffect(() => {
        if (layerOpen) {
            closeMega();
            setPopover(null);
        }
    }, [layerOpen, closeMega]);

    // Opening a page closes the menus.
    useEffect(() => {
        setOpenId(null);
        setPinned(false);
        setPopover(null);
        setHoverKey(null);
    }, [url]);

    // The bar slims once the page scrolls; the open panel follows its edge, and a small menu closes once the page moves away from it.
    useEffect(() => {
        function onScroll() {
            const y = window.scrollY;
            // Two edges, so a page resting near one cannot flip the bar back and forth.
            setScrolled((wasScrolled) => (wasScrolled ? y > 4 : y > 24));

            if (state.current.openId && barRef.current) {
                setGeometry((previous) => ({ ...previous, top: barRef.current.getBoundingClientRect().bottom + 10 }));
            }

            if (state.current.popover && Math.abs(y - state.current.popoverY) > 24) {
                setPopover(null);
            } else if (!state.current.popover) {
                state.current.popoverY = y;
            }
        }

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    // A new width closes the menus and puts the light back in place at once.
    useEffect(() => {
        let lastWidth = window.innerWidth;
        let frame;

        function onResize() {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                if (window.innerWidth !== lastWidth) {
                    lastWidth = window.innerWidth;
                    closeMega();
                    setPopover(null);
                }

                syncHighlight(true);
            });
        }

        window.addEventListener('resize', onResize);

        return () => {
            cancelAnimationFrame(frame);
            window.removeEventListener('resize', onResize);
        };
    }, [closeMega, syncHighlight]);

    // Clicks and focus outside the bar and the panel close it; Esc closes it and returns to its section; Alt + a number opens a section.
    useEffect(() => {
        function inside(target) {
            return target?.closest?.('.tn-mega, .tn-sections');
        }

        function onClick(event) {
            if (!inside(event.target)) {
                closeMega();
            }
        }

        function onFocusIn(event) {
            if (state.current.openId && !inside(event.target)) {
                closeMega();
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape' && state.current.openId) {
                closeMega(true);

                return;
            }

            const digit = /^Digit[1-9]$/.test(event.code) ? Number(event.code.slice(5)) : /^[1-9]$/.test(event.key) ? Number(event.key) : null;

            if (event.altKey && !event.ctrlKey && digit && navRef.current?.offsetParent && !state.current.layerOpen && !pageWindowOpen()) {
                const item = items[digit - 1];

                if (!item) {
                    return;
                }

                event.preventDefault();

                if (item.group) {
                    openMega(item.group.id, { pin: true, focus: true });
                } else {
                    router.visit(item.link.href);
                }
            }
        }

        document.addEventListener('click', onClick);
        document.addEventListener('focusin', onFocusIn);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('click', onClick);
            document.removeEventListener('focusin', onFocusIn);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [closeMega, openMega, items]);

    useEffect(() => () => {
        clearTimers();
        timers.current.leaving.forEach(clearTimeout);
    }, [clearTimers]);

    function hoverItem(item) {
        clearTimeout(timers.current.close);
        setHoverKey(item.key);

        if (item.group) {
            clearTimeout(timers.current.open);
            timers.current.open = setTimeout(() => openMega(item.group.id), state.current.openId ? 70 : 110);
        } else {
            clearTimeout(timers.current.open);

            if (state.current.openId && !state.current.pinned) {
                timers.current.close = setTimeout(() => closeMega(), 160);
            }
        }
    }

    function leaveZone() {
        clearTimers();
        timers.current.close = setTimeout(() => {
            setHoverKey(null);

            if (!state.current.pinned) {
                closeMega();
            }
        }, 220);
    }

    function onSectionClick(event, group) {
        const id = group.id;
        const landing = groupLandingLink(group);

        if (event.detail && pointerType.current === 'mouse' && landing) {
            closeMega();
            router.visit(landing.href);
        } else if (openId === id && !pinned && event.detail) {
            openMega(id, { pin: true });
        } else if (openId === id) {
            closeMega();
        } else {
            openMega(id, { pin: true, focus: !event.detail });
        }
    }

    function onSectionKeyDown(event, item) {
        const keys = items.map((candidate) => candidate.key);
        const index = keys.indexOf(item.key);

        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            const next = items[(index + (event.key === 'ArrowLeft' ? 1 : -1) + items.length) % items.length];
            itemRefs.current.get(next.key)?.focus();
            setHoverKey(next.key);

            if (state.current.openId && next.group) {
                openMega(next.group.id, { pin: true });
            } else if (state.current.openId) {
                closeMega();
            }
        } else if (event.key === 'ArrowDown' && item.group) {
            event.preventDefault();
            openMega(item.group.id, { pin: true, focus: true });
        }
    }

    function onMegaKeyDown(event) {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        event.preventDefault();
        const links = [...megaRef.current.querySelectorAll('.tn-mega-content:not(.is-leaving) .tn-feature, .tn-mega-content:not(.is-leaving) .tn-mega-link')];
        const index = links.indexOf(document.activeElement);

        if (index <= 0 && event.key === 'ArrowUp') {
            closeMega(true);

            return;
        }

        links[(index + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length]?.focus();
    }

    function togglePopover(kind) {
        closeMega();
        state.current.popoverY = window.scrollY;
        setPopover((open) => (open === kind ? null : kind));
    }

    const openGroup = groups.find((group) => group.id === openId);

    return (
        <>
            <header className={`tn-header ${scrolled ? 'is-scrolled' : ''}`}>
                <div
                    ref={barRef}
                    className="tn-glass"
                    onTransitionEnd={(event) => {
                        if (event.target === barRef.current) {
                            syncHighlight(true);

                            if (state.current.openId) {
                                setGeometry((previous) => ({ ...previous, top: barRef.current.getBoundingClientRect().bottom + 10 }));
                            }
                        }
                    }}
                >
                    <div className="flex min-w-0 items-center">
                        <Link href={HOME_LINK.href} prefetch={shouldPrefetchPage(HOME_LINK.href)} className="tn-brand" data-tour="brand" aria-label={`${appName}، ${user?.branchName ?? 'نظام التحصيل الكهربائي'}، الصفحة الرئيسية`}>
                            <img src="/images/logo-af.webp" alt={appName} />
                            <span className="tn-brand-divider" aria-hidden="true" />
                            <span className="tn-brand-text">
                                <span className="tn-brand-name" dir="auto">{shortAppName(appName)}</span>
                                <span className="tn-brand-branch" dir="auto">
                                    <span key={pageLabel ?? ''} className={`tn-brand-swap ${pageLabel ? 'is-page' : ''}`}>{pageLabel ?? user?.branchName ?? 'نظام التحصيل الكهربائي'}</span>
                                </span>
                            </span>
                        </Link>
                        {where && <span key={where.page} className="tn-where" aria-hidden="true">{[where.page, where.scope].filter(Boolean).join(' · ')}</span>}
                    </div>

                    <nav
                        ref={navRef}
                        className="tn-sections"
                        data-tour="sections"
                        aria-label="أقسام النظام"
                        onPointerOver={(event) => {
                            const element = event.target.closest('[data-item]');
                            const item = element && items.find((candidate) => candidate.key === element.dataset.item);

                            if (item && event.pointerType === 'mouse') {
                                hoverItem(item);
                            }
                        }}
                        onPointerLeave={(event) => event.pointerType === 'mouse' && leaveZone()}
                        onFocus={(event) => {
                            const element = event.target.closest('[data-item]');

                            if (element && event.target.matches(':focus-visible')) {
                                setHoverKey(element.dataset.item);
                            }
                        }}
                        onBlur={(event) => {
                            if (!event.relatedTarget?.closest?.('.tn-sections')) {
                                setHoverKey(null);
                            }
                        }}
                    >
                        <span ref={highlightRef} className="tn-highlight" aria-hidden="true" />
                        {items.map((item, index) => {
                            const isCurrent = item.key === currentKey;
                            const register = (element) => (element ? itemRefs.current.set(item.key, element) : itemRefs.current.delete(item.key));

                            if (item.link) {
                                return (
                                    <Link
                                        key={item.key}
                                        ref={register}
                                        href={item.link.href}
                                        prefetch={shouldPrefetchPage(item.link.href)}
                                        className="tn-section"
                                        data-item={item.key}
                                        data-lit={litKey === item.key}
                                        data-current={isCurrent || undefined}
                                        data-tour={isCurrent ? 'current' : undefined}
                                        aria-current={isCurrent ? 'page' : undefined}
                                        onKeyDown={(event) => onSectionKeyDown(event, item)}
                                    >
                                        <Icon name={item.link.icon} className="tn-section-icon" />
                                        <span>{item.link.label}</span>
                                        {badges[item.link.href] > 0 && <span className="tn-badge" aria-hidden="true">{badges[item.link.href]}</span>}
                                    </Link>
                                );
                            }

                            const badge = badgeOf(item.group, badges);

                            return (
                                <button
                                    key={item.key}
                                    ref={register}
                                    type="button"
                                    className="tn-section"
                                    data-item={item.key}
                                    data-lit={litKey === item.key}
                                    data-current={isCurrent || undefined}
                                    data-tour={isCurrent ? 'current' : undefined}
                                    aria-expanded={openId === item.group.id}
                                    aria-controls="tn-mega"
                                    aria-current={isCurrent ? 'true' : undefined}
                                    title={`${item.group.label} · Alt+${index + 1}`}
                                    onPointerDown={(event) => { pointerType.current = event.pointerType; }}
                                    onClick={(event) => onSectionClick(event, item.group)}
                                    onKeyDown={(event) => onSectionKeyDown(event, item)}
                                >
                                    <Icon name={item.group.icon} className="tn-section-icon" />
                                    <span className="tn-label-short">{item.group.shortLabel}</span>
                                    <span className="tn-label-full">{item.group.label}</span>
                                    {badge > 0 && (
                                        <>
                                            <span className="tn-badge" aria-hidden="true">{badge}</span>
                                            <span className="sr-only">، {badge} بانتظارك</span>
                                        </>
                                    )}
                                    <Icon name="chevron-down" className="tn-chevron" />
                                </button>
                            );
                        })}
                    </nav>

                    <div className="flex items-center gap-1">
                        <button type="button" className="tn-search" data-tour="search" onClick={onOpenPalette} aria-label="بحث وأوامر (Ctrl K)" title="بحث وأوامر · Ctrl K">
                            <Icon name="search" />
                            <span className="tn-search-text flex-1 text-start">{props.can?.recordPayments && props.can?.viewSubscriptions ? 'ابحث عن صفحة أو مشترك…' : 'ابحث عن صفحة أو أمر…'}</span>
                            <span className="tn-kbd" dir="ltr">Ctrl K</span>
                        </button>
                        <ActivityBell open={popover === 'bell'} onToggle={() => togglePopover('bell')} onClose={() => setPopover(null)} />
                        <span className="tn-separator" aria-hidden="true" />
                        <AccountMenu
                            open={popover === 'account'}
                            onToggle={() => togglePopover('account')}
                            onClose={() => setPopover(null)}
                            onShowShortcuts={() => {
                                setPopover(null);
                                onShowShortcuts();
                            }}
                            onStartTour={() => {
                                setPopover(null);
                                onStartTour();
                            }}
                        />
                        <button type="button" className="tn-tool tn-mobile-only" data-tour="menu" onClick={onOpenDrawer} aria-label="القائمة" aria-expanded={drawerOpen}>
                            <Icon name="menu" />
                        </button>
                    </div>
                </div>
            </header>

            <div
                id="tn-mega"
                ref={megaRef}
                role="region"
                aria-label={openGroup?.label ?? 'صفحات القسم'}
                inert={!openId}
                className={`tn-mega ${openId ? 'is-open' : ''} ${still ? 'is-still' : ''}`}
                style={{ '--tn-mega-width': `${geometry.width}px`, '--tn-mega-height': `${geometry.height}px`, '--tn-mega-x': `${geometry.x}px`, '--tn-mega-top': `${geometry.top}px` }}
                onPointerEnter={clearTimers}
                onPointerLeave={(event) => event.pointerType === 'mouse' && leaveZone()}
                onKeyDown={onMegaKeyDown}
            >
                {panels.map((panel, index) => {
                    const group = groups.find((candidate) => candidate.id === panel.id);

                    return group && (
                        <MegaMenu
                            key={panel.key}
                            contentRef={index === panels.length - 1 ? panelRef : undefined}
                            group={group}
                            url={url}
                            badges={badges}
                            width={megaWidth(group)}
                            shift={panel.shift}
                            leaving={panel.leaving}
                            onSearch={() => {
                                closeMega();
                                onOpenPalette();
                            }}
                        />
                    );
                })}
            </div>
        </>
    );
}
