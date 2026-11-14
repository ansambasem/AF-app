import { createPortal } from 'react-dom';
import { Link, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import ThemeSegments from './ThemeSegments';
import { usePopover } from './usePopover';

/** The user's avatar: their initial on graphite, with a green dot for signed in. */
export function Avatar({ name, large = false }) {
    return (
        <span className={`tn-avatar ${large ? 'is-large' : ''}`} aria-hidden="true">
            {(name ?? '').trim().substring(0, 1)}
            <span className="tn-avatar-online" />
        </span>
    );
}

/** Signs out (the form posts to /logout with the page's CSRF token). */
export function LogoutForm({ children, className }) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    return (
        <form method="POST" action="/logout" className={className}>
            <input type="hidden" name="_token" value={csrfToken} />
            {children}
        </form>
    );
}

/**
 * The account button at the bar's end and its menu: who is signed in, the
 * appearance, the profile, the keys and the tour, and signing out.
 */
export default function AccountMenu({ open, onToggle, onClose, onShowShortcuts, onStartTour }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const { anchorRef, popoverRef } = usePopover(open, onClose);

    return (
        <>
            <button
                ref={anchorRef}
                type="button"
                className="tn-account"
                data-tour="account"
                onClick={onToggle}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={`حسابك: ${user?.name ?? ''}`}
            >
                <Avatar name={user?.name} />
                <span className="tn-account-text text-start leading-tight">
                    <b dir="auto" className="block whitespace-nowrap text-[14px] font-semibold text-gray-900">{user?.name}</b>
                    <small dir="auto" className="block whitespace-nowrap text-[12px] text-gray-500">{user?.roleLabel}</small>
                </span>
                <Icon name="chevron-down" className="tn-chevron h-3.5 w-3.5 text-gray-400" />
            </button>

            {open && createPortal(
                <div ref={popoverRef} dir="rtl" className="tn-popover w-[290px] p-2 text-gray-900" role="dialog" aria-label="الحساب">
                    <div className="flex items-center gap-3 border-b border-gray-200/70 px-2 pb-3 pt-2">
                        <Avatar name={user?.name} large />
                        <div className="min-w-0">
                            <b dir="auto" className="block truncate text-[15px]">{user?.name}</b>
                            <small dir="auto" className="block text-[12.5px] text-gray-500">{[user?.roleLabel, user?.branchName].filter(Boolean).join(' · ')}</small>
                        </div>
                    </div>
                    <div className="mt-1.5 px-2 text-[12px] font-bold text-gray-500">المظهر</div>
                    <ThemeSegments className="mx-1 mb-1.5 mt-1" />
                    <Link href="/profile" prefetch className="tn-menu-item" onClick={onClose}><Icon name="user" />الملف الشخصي</Link>
                    <button type="button" className="tn-menu-item" onClick={onShowShortcuts}>
                        <Icon name="keyboard" />اختصارات لوحة المفاتيح<span className="tn-kbd ms-auto">?</span>
                    </button>
                    <button type="button" className="tn-menu-item" onClick={onStartTour}><Icon name="sparkles" />جولة سريعة في الشريط</button>
                    <div className="my-1 h-px bg-gray-200/70" />
                    <LogoutForm>
                        <button type="submit" className="tn-menu-item is-danger"><Icon name="logout" />تسجيل الخروج</button>
                    </LogoutForm>
                </div>,
                document.body,
            )}
        </>
    );
}
