import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { shouldPrefetchPage, HOME_LINK, currentGroup, isActiveLink, lockedLinkProps } from '@/lib/navigation';
import { Avatar, LogoutForm } from './AccountMenu';
import { LockMark } from './MegaMenu';
import ThemeSegments from './ThemeSegments';
import { shortAppName } from './TopBar';

/**
 * The menu on phones and narrow screens: the same sections as the bar, the
 * current one open, a search, and the account with the appearance below.
 * It slides in from the side and back out when closed.
 */
export default function NavigationDrawer({ open, groups, badges, onClose, onOpenPalette, returnFocusTo }) {
    const { props, url } = usePage();
    const { appName, auth } = props;
    const user = auth?.user;
    const current = currentGroup(groups, url);
    const [shown, setShown] = useState(open);
    const [closing, setClosing] = useState(false);
    const [expanded, setExpanded] = useState(current?.id ?? null);
    const panelRef = useRef(null);

    useEffect(() => {
        if (open) {
            setShown(true);
            setClosing(false);
            setExpanded(currentGroup(groups, url)?.id ?? null);

            return undefined;
        }

        if (!shown) {
            return undefined;
        }

        setClosing(true);
        const timer = setTimeout(() => {
            setShown(false);
            setClosing(false);
        }, 230);

        return () => clearTimeout(timer);
    }, [open]);

    // While open the page behind stays still; closing returns to the menu button.
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        document.documentElement.classList.add('tn-locked');
        requestAnimationFrame(() => panelRef.current?.querySelector('button')?.focus());

        return () => {
            document.documentElement.classList.remove('tn-locked');
            returnFocusTo?.current?.focus();
        };
    }, [open, returnFocusTo]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                onClose();
            } else if (event.key === 'Tab') {
                const focusable = [...panelRef.current.querySelectorAll('button, a[href], input')].filter((element) => element.offsetParent);

                if (!focusable.length) {
                    return;
                }

                if (event.shiftKey && document.activeElement === focusable[0]) {
                    event.preventDefault();
                    focusable.at(-1).focus();
                } else if (!event.shiftKey && document.activeElement === focusable.at(-1)) {
                    event.preventDefault();
                    focusable[0].focus();
                }
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    // A page opened from the menu closes it.
    useEffect(() => {
        if (open) {
            onClose();
        }
    }, [url]);

    if (!open && !shown) {
        return null;
    }

    const onHome = isActiveLink(HOME_LINK, url);

    return createPortal(
        <div className={`tn-drawer ${closing ? 'is-closing' : ''}`} dir="rtl">
            <div className="tn-scrim" onClick={onClose} />
            <aside ref={panelRef} className="tn-drawer-panel text-gray-900" role="dialog" aria-modal="true" data-top-bar-layer aria-label="قائمة التنقل">
                <div className="flex items-center gap-2 border-b border-gray-200/70 p-3">
                    <img src="/images/logo-af.webp" alt={appName} className="h-9 w-auto" />
                    <span className="min-w-0 flex-1">
                        <b dir="auto" className="block text-[16px]">{shortAppName(appName)}</b>
                        <small dir="auto" className="block text-[12.5px] text-gray-500">{user?.branchName ?? 'نظام التحصيل الكهربائي'}</small>
                    </span>
                    <button type="button" className="tn-tool" onClick={onClose} aria-label="إغلاق القائمة"><Icon name="close" /></button>
                </div>

                <div className="flex-1 overflow-y-auto p-3">
                    <button type="button" onClick={onOpenPalette} className="mb-3 flex h-12 w-full items-center gap-2.5 rounded-2xl border border-gray-200 bg-gray-50 px-3.5 text-[15px] text-gray-500">
                        <Icon name="search" className="h-5 w-5" />
                        {props.can?.recordPayments && props.can?.viewSubscriptions ? 'ابحث عن صفحة أو مشترك…' : 'ابحث عن صفحة أو أمر…'}
                    </button>
                    <div className="grid gap-1">
                        <Link href={HOME_LINK.href} prefetch={shouldPrefetchPage(HOME_LINK.href)} className="tn-drawer-group" data-current={onHome || undefined} aria-current={onHome ? 'page' : undefined}>
                            <Icon name={HOME_LINK.icon} className="tn-section-icon" />
                            {HOME_LINK.label}
                        </Link>
                        {groups.map((group) => {
                            const isOpen = expanded === group.id;
                            const badge = group.links.reduce((sum, link) => sum + (badges[link.href] > 0 ? badges[link.href] : 0), 0);

                            return (
                                <div key={group.id}>
                                    <button
                                        type="button"
                                        className="tn-drawer-group"
                                        aria-expanded={isOpen}
                                        onClick={() => setExpanded(isOpen ? null : group.id)}
                                    >
                                        <Icon name={group.icon} className="tn-section-icon" />
                                        {group.label}
                                        {badge > 0 && <span className="rounded-full bg-brand-500 px-1.5 text-[11px] font-bold leading-[18px] text-white">{badge}</span>}
                                        <Icon name="chevron-down" className="tn-chevron" />
                                    </button>
                                    <div className={`tn-drawer-pages ${isOpen ? 'is-open' : ''}`} inert={!isOpen}>
                                        <div>
                                            <div className="grid gap-0.5 py-1 ps-3">
                                                {group.links.map((link) => (
                                                    <Link key={link.href} href={link.href} prefetch={!link.locked && shouldPrefetchPage(link.href)} className="tn-drawer-link" aria-current={isActiveLink(link, url) ? 'page' : undefined} {...lockedLinkProps(link)}>
                                                        <Icon name={link.icon} />
                                                        <span className="flex-1">{link.label}</span>
                                                        {link.locked && <LockMark />}
                                                        {badges[link.href] > 0 && <span className="rounded-full bg-brand-500 px-1.5 text-[11px] font-bold leading-[18px] text-white">{badges[link.href]}</span>}
                                                    </Link>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="border-t border-gray-200/70 p-3">
                    <div className="mb-2 flex items-center gap-3 rounded-2xl bg-gray-50 p-2.5">
                        <Link href="/profile" prefetch className="flex min-w-0 flex-1 items-center gap-3" aria-label={`${user?.name ?? ''}، الملف الشخصي`}>
                            <Avatar name={user?.name} />
                            <span className="min-w-0 flex-1">
                                <b dir="auto" className="block truncate text-[14.5px]">{user?.name}</b>
                                <small dir="auto" className="block text-[12.5px] text-gray-500">{user?.roleLabel}</small>
                            </span>
                        </Link>
                        <LogoutForm>
                            <button type="submit" className="tn-tool" aria-label="تسجيل الخروج"><Icon name="logout" /></button>
                        </LogoutForm>
                    </div>
                    <ThemeSegments />
                </div>
            </aside>
        </div>,
        document.body,
    );
}
