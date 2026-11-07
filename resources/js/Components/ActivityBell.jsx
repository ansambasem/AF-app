import { useState } from 'react';
import { createPortal } from 'react-dom';
import { useHttp, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { usePopover } from '@/Components/TopBar/usePopover';
import { ACTION_MESSAGES } from '@/lib/actionMessages';

const relativeTime = new Intl.RelativeTimeFormat('ar-u-nu-latn', { numeric: 'auto' });

// The count's ring pulses once per visit to the app, not on every page.
let pinged = false;

/** When an action happened: "الآن", "قبل 5 دقائق", … and a plain date after a week. */
function timeAgo(isoDate) {
    const date = new Date(isoDate);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const minutes = Math.round(seconds / 60);
    const hours = Math.round(minutes / 60);
    const days = Math.round(hours / 24);

    if (Math.abs(seconds) < 60) {
        return relativeTime.format(0, 'second');
    }
    if (Math.abs(minutes) < 60) {
        return relativeTime.format(minutes, 'minute');
    }
    if (Math.abs(hours) < 24) {
        return relativeTime.format(hours, 'hour');
    }
    if (Math.abs(days) < 7) {
        return relativeTime.format(days, 'day');
    }

    return date.toLocaleDateString('ar-u-nu-latn', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** The icon for an action: an alert, a deletion, an addition or an edit. */
function iconOf(action) {
    if (action === 'meter-reading-needs-reapproval') {
        return 'alert';
    }
    if (action.endsWith('-deleted')) {
        return 'trash';
    }

    return action.endsWith('-created') || ['payment-recorded', 'split-payment-recorded'].includes(action) ? 'plus' : 'pencil';
}

/**
 * The bell in the top bar: the user's own latest saved actions and the alerts
 * sent to them (the shared `activity` prop), with a count of the unread ones.
 * "تحديد الكل كمقروء" marks them all as read.
 */
export default function ActivityBell({ open, onToggle, onClose }) {
    const { activity } = usePage().props;
    const [tab, setTab] = useState('all');
    // The `activity` whose unread items were just marked as read here; the next page visit brings a fresh one from the server.
    const [readActivity, setReadActivity] = useState(null);
    const [ping] = useState(() => !pinged);
    const http = useHttp();
    const { anchorRef, popoverRef } = usePopover(open, onClose);

    if (!activity) {
        return null;
    }

    pinged = true;
    const allRead = readActivity === activity;
    const items = activity.recent.map((item) => ({ ...item, read: item.read || allRead }));
    const unreadCount = allRead ? 0 : activity.unreadCount;
    const shown = tab === 'unread' ? items.filter((item) => !item.read) : items;

    function markAllRead() {
        setReadActivity(activity);
        http.post('/notifications/read').catch(() => {});
        popoverRef.current?.querySelector('[aria-pressed="true"]')?.focus();
    }

    return (
        <>
            <button
                ref={anchorRef}
                type="button"
                className="tn-tool"
                data-tour="bell"
                onClick={onToggle}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={unreadCount > 0 ? `الإشعارات، ${unreadCount} جديدة` : 'الإشعارات'}
                title="الإشعارات"
            >
                <Icon name="bell" />
                {unreadCount > 0 && <span className={`tn-tool-count ${ping ? 'is-pinging' : ''}`}><bdi dir="ltr">{unreadCount > 9 ? '9+' : unreadCount}</bdi></span>}
            </button>

            {open && createPortal(
                <div ref={popoverRef} dir="rtl" className="tn-popover w-[380px] max-w-[calc(100vw-24px)] text-gray-900" role="dialog" aria-label="الإشعارات">
                    <div className="flex items-center gap-2 px-4 pb-2 pt-4">
                        <b className="text-[16px]">الإشعارات</b>
                        {unreadCount > 0 && <span className="rounded-full bg-brand-500 px-2 text-[12px] font-bold leading-5 text-white">{unreadCount}</span>}
                        <button type="button" onClick={markAllRead} disabled={unreadCount === 0} className="ms-auto text-[13px] font-semibold text-gray-500 hover:text-gray-900 disabled:opacity-40">
                            تحديد الكل كمقروء
                        </button>
                    </div>
                    <div className="tn-segments mx-4 mb-2 grid grid-cols-2 gap-1 rounded-xl bg-gray-100 p-1">
                        <button type="button" aria-pressed={tab === 'all'} onClick={() => setTab('all')}>الكل</button>
                        <button type="button" aria-pressed={tab === 'unread'} onClick={() => setTab('unread')}>غير المقروءة{unreadCount > 0 ? ` (${unreadCount})` : ''}</button>
                    </div>
                    <div className="grid max-h-[360px] gap-0.5 overflow-y-auto px-2 pb-2">
                        {shown.length > 0 ? shown.map((item) => (
                            <div key={item.id} className={`tn-note ${item.read ? '' : 'is-unread'}`}>
                                <span className="tn-note-icon"><Icon name={iconOf(item.action)} /></span>
                                <span className="min-w-0 flex-1">
                                    <b className="block text-[14.5px] font-semibold leading-snug">{ACTION_MESSAGES[item.action] ?? item.action}</b>
                                    {item.subject && <small className="block truncate text-[12.5px] text-gray-500">{item.subject}</small>}
                                    <time dateTime={item.createdAt} className="mt-0.5 block text-[12px] text-gray-400">{timeAgo(item.createdAt)}</time>
                                </span>
                                {!item.read && <span className="tn-unread-dot" aria-label="جديد" />}
                            </div>
                        )) : (
                            <div className="grid justify-items-center gap-2 px-4 py-8 text-center text-gray-500">
                                <Icon name={tab === 'unread' ? 'check' : 'clock'} className={`h-8 w-8 ${tab === 'unread' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-300'}`} />
                                <b className="text-gray-900">{tab === 'unread' ? 'لا شيء جديد' : 'لا توجد إجراءات بعد'}</b>
                                <small>{tab === 'unread' ? 'كل الإشعارات مقروءة' : 'ستظهر هنا الإضافات والتعديلات التي تحفظها.'}</small>
                            </div>
                        )}
                    </div>
                </div>,
                document.body,
            )}
        </>
    );
}
