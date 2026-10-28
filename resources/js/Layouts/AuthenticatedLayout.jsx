import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import TopBar from '@/Components/TopBar/TopBar';
import NavigationDrawer from '@/Components/TopBar/NavigationDrawer';
import { Breadcrumb, SectionPages } from '@/Components/TopBar/PageTrail';
import { handlePaletteShortcut, pageWindowOpen } from '@/Components/TopBar/shortcuts';
import { useTheme } from '@/hooks/useTheme';
import { currentPrintSettings } from '@/lib/print';
import AppFooter from '@/Components/AppFooter';
import { watchSessionStatus } from '@/lib/sessionStatus';
import { HOME_LINK, MAIN_LINKS, allowedNavigationGroups, navigationGroups, pageTrail } from '@/lib/navigation';
import { useResponsiveTables } from '@/hooks/useResponsiveTables';
import { QUICK_MESSAGE_EVENT, openQuickMessage } from '@/lib/quickMessage';
const CommandPalette = lazy(() => import('@/Components/CommandPalette'));
const PrintDesigner = lazy(() => import('@/Components/Print/PrintDesigner'));
const TableExcelDesigner = lazy(() => import('@/Components/TableExcelDesigner'));
const TableExcelActions = lazy(() => import('@/Components/TableExcelActions'));
const KeyboardShortcuts = lazy(() => import('@/Components/TopBar/KeyboardShortcuts'));
const QuickTour = lazy(() => import('@/Components/TopBar/QuickTour'));
const QuickMessageModal = lazy(() => import('@/Pages/Messages/QuickMessageModal'));

const loadingTool = <p role="status" className="fixed bottom-6 left-1/2 z-[100] -translate-x-1/2 rounded-control border border-gray-200 bg-surface px-4 py-3 text-sm text-gray-600 shadow-card">جارٍ تحميل الأدوات…</p>;

const NO_BADGES = {};

// The first page of a visit to the app keeps the browser's own focus; later pages put it on their content.
let openedOnce = false;

// How far down the floating bar reaches, so content under it counts as scrolled away.
const BAR_CLEARANCE = 90;

/** Whether the user is typing, so a single-key shortcut must not fire. */
function isTyping(target) {
    return Boolean(target?.closest?.('input, textarea, select, [contenteditable="true"]'));
}

/**
 * Every signed-in page: the floating top bar with the sections, the trail to
 * the page, the section's other pages, the page's title, then the page itself.
 * Only the top bar stays on screen while scrolling, so tables keep the room.
 * `badges` counts what waits on a page, by its link: { '/payments/summary': 2 }.
 * `scope` names the branch (or "جميع الفروع") whose figures the page shows;
 * the bar keeps it beside the page's name once the title has scrolled away.
 */
export default function AuthenticatedLayout({ header, badges = NO_BADGES, scope = null, children, beforePrint }) {
    const { props, url } = usePage();
    const { can } = props;
    const { dark, changePreference } = useTheme();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);
    const [shortcutsOpen, setShortcutsOpen] = useState(false);
    const [tourOpen, setTourOpen] = useState(false);
    // The quick message window, with the subscriptions it was opened for (see lib/quickMessage.js); null when closed.
    const [quickMessage, setQuickMessage] = useState(null);
    const printSettings = useMemo(() => currentPrintSettings(new URL(url, window.location.origin).search), [url]);
    useEffect(() => watchSessionStatus(() => window.location.replace('/login')), []);
    const menuButtonRef = useRef(null);
    const titleEndRef = useRef(null);
    const [pastTitle, setPastTitle] = useState(false);
    useResponsiveTables();

    const groups = navigationGroups(can);
    const trail = pageTrail(groups, url);
    const searchesSubscribers = Boolean(can?.recordPayments && can?.viewSubscriptions);
    const anyLayerOpen = drawerOpen || paletteOpen || shortcutsOpen || tourOpen;

    const paletteSections = [[HOME_LINK.label, [MAIN_LINKS[0]]], ...allowedNavigationGroups(can).map((group) => [group.label, group.links])];
    const commands = [
        ...(can?.recordPayments ? [{ id: 'pay', label: 'تسجيل دفعة', icon: 'banknotes', key: 'N' }] : []),
        ...(can?.sendMessages ? [{ id: 'message', label: 'رسالة سريعة لمشترك أو أكثر', icon: 'send', key: 'M' }] : []),
        { id: 'theme', label: 'تبديل الوضع الفاتح والداكن', icon: dark ? 'sun' : 'moon' },
        { id: 'tour', label: 'جولة سريعة في الشريط', icon: 'sparkles' },
        { id: 'keys', label: 'اختصارات لوحة المفاتيح', icon: 'keyboard', key: '?' },
    ];

    // The search replaces whatever else is open, as one layer at a time.
    const onPaletteOpenChange = useCallback((open) => {
        if (open) {
            setDrawerOpen(false);
            setShortcutsOpen(false);
            setTourOpen(false);
        }

        setPaletteOpen(open);
    }, []);

    const closeShortcuts = useCallback(() => setShortcutsOpen(false), []);
    const closeTour = useCallback(() => setTourOpen(false), []);
    const closeDrawer = useCallback(() => setDrawerOpen(false), []);

    function runCommand(id) {
        if (id === 'pay') {
            router.visit('/payments');
        } else if (id === 'message') {
            openQuickMessage();
        } else if (id === 'theme') {
            changePreference(dark ? 'light' : 'dark');
        } else if (id === 'tour') {
            setTourOpen(true);
        } else if (id === 'keys') {
            setShortcutsOpen(true);
        }
    }

    // From any page: / opens the search, ? the keys, N the payment page. Listened on the window, after the page's own
    // keys: a page that uses one of them (a table's / to its search) keeps it, and nothing fires over a page's window.
    useEffect(() => {
        function onKeyDown(event) {
            if (handlePaletteShortcut(event, paletteOpen, onPaletteOpenChange)) {
                return;
            }
            if (event.defaultPrevented || isTyping(event.target) || event.ctrlKey || event.metaKey || event.altKey || anyLayerOpen || pageWindowOpen()) {
                return;
            }

            if (event.key === '?' || event.key === '؟' || (event.code === 'Slash' && event.shiftKey)) {
                event.preventDefault();
                setShortcutsOpen(true);
            } else if (event.key === '/' || event.code === 'Slash') {
                event.preventDefault();
                setPaletteOpen(true);
            } else if (can?.recordPayments && (event.key === 'n' || event.key === 'N' || event.code === 'KeyN')) {
                event.preventDefault();
                router.visit('/payments');
            } else if (can?.sendMessages && (event.key === 'm' || event.key === 'M' || event.code === 'KeyM')) {
                event.preventDefault();
                openQuickMessage();
            }
        }

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [anyLayerOpen, paletteOpen, onPaletteOpenChange, can?.recordPayments, can?.sendMessages]);

    // Any page opens the quick message window through openQuickMessage().
    useEffect(() => {
        if (!can?.sendMessages) {
            return undefined;
        }

        function onOpen(event) {
            setPaletteOpen(false);
            setQuickMessage({ subscriptionIds: event.detail?.subscriptionIds ?? [], selection: event.detail?.selection ?? null, openedAt: Date.now() });
        }

        window.addEventListener(QUICK_MESSAGE_EVENT, onOpen);

        return () => window.removeEventListener(QUICK_MESSAGE_EVENT, onOpen);
    }, [can?.sendMessages]);

    // After opening a page from the bar, the search or a link, focus lands on its content, not at the top of the document.
    useEffect(() => {
        if (openedOnce && (!document.activeElement || document.activeElement === document.body)) {
            document.getElementById('main-content')?.focus({ preventScroll: true });
        }

        openedOnce = true;
    }, []);

    // Whether the page's title has scrolled up under the bar.
    useEffect(() => {
        const marker = titleEndRef.current;

        if (!marker) {
            return undefined;
        }

        const observer = new IntersectionObserver(([entry]) => setPastTitle(!entry.isIntersecting && entry.boundingClientRect.top < BAR_CLEARANCE), {
            rootMargin: `-${BAR_CLEARANCE}px 0px 0px 0px`,
        });
        observer.observe(marker);

        return () => observer.disconnect();
    }, []);

    // The phone menu is for narrow screens only.
    useEffect(() => {
        const media = window.matchMedia('(min-width: 1024px)');
        const onChange = () => media.matches && setDrawerOpen(false);

        media.addEventListener('change', onChange);

        return () => media.removeEventListener('change', onChange);
    }, []);

    // Opened from a table's print button: the page without the menus (see lib/print.js).
    if (printSettings) {
        if (new URL(url, window.location.origin).searchParams.get('excel') === '1') {
            return <Suspense fallback={loadingTool}><TableExcelDesigner settings={printSettings}>{children}</TableExcelDesigner></Suspense>;
        }
        return <Suspense fallback={loadingTool}><PrintDesigner settings={printSettings} beforePrint={beforePrint}>{children}</PrintDesigner></Suspense>;
    }

    return (
        <div className="min-h-screen text-gray-900">
            <div className="tn-backdrop" aria-hidden="true" />
            <a href="#main-content" className="fixed start-4 -top-24 z-[100] rounded-xl bg-surface px-4 py-3 font-semibold tn-lift focus:top-3">
                تخطي إلى المحتوى
            </a>

            <TopBar
                groups={groups}
                badges={badges}
                where={pastTitle && trail ? { section: trail.group?.shortLabel, page: trail.link.label, scope } : null}
                drawerOpen={drawerOpen}
                layerOpen={anyLayerOpen}
                onOpenPalette={() => onPaletteOpenChange(true)}
                onOpenDrawer={(event) => {
                    menuButtonRef.current = event.currentTarget;
                    setDrawerOpen(true);
                }}
                onShowShortcuts={() => setShortcutsOpen(true)}
                onStartTour={() => setTourOpen(true)}
            />

            <main id="main-content" tabIndex={-1} className="mx-auto max-w-[1720px] px-[var(--tn-gutter)] pb-16 pt-8 outline-none">
                <Breadcrumb trail={trail} />
                <SectionPages trail={trail} url={url} badges={badges} />
                {header && <div className="rise-in mb-7 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">{header}</div>}
                <div ref={titleEndRef} aria-hidden="true" />
                {children}
            </main>
            <AppFooter />

            <NavigationDrawer
                open={drawerOpen}
                groups={groups}
                badges={badges}
                onClose={closeDrawer}
                onOpenPalette={() => onPaletteOpenChange(true)}
                returnFocusTo={menuButtonRef}
            />
            <Suspense fallback={loadingTool}>
                <TableExcelActions />
                {paletteOpen && <CommandPalette
                    open={paletteOpen}
                    onOpenChange={onPaletteOpenChange}
                    sections={paletteSections}
                    commands={commands}
                    onCommand={runCommand}
                    searchSubscribers={searchesSubscribers}
                />}
                {shortcutsOpen && <KeyboardShortcuts
                    open={shortcutsOpen}
                    onClose={closeShortcuts}
                    canRecordPayments={Boolean(can?.recordPayments)}
                    canSendMessages={Boolean(can?.sendMessages)}
                    searchesSubscribers={searchesSubscribers}
                />}
                {quickMessage && (
                    <QuickMessageModal key={quickMessage.openedAt} subscriptionIds={quickMessage.subscriptionIds} selection={quickMessage.selection} onClose={() => setQuickMessage(null)} />
                )}
                {tourOpen && <QuickTour open={tourOpen} onClose={closeTour} searchesSubscribers={searchesSubscribers} />}
            </Suspense>
        </div>
    );
}
