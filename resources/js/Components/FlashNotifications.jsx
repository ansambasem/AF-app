import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { ACTION_MESSAGES, visitReturnsStatus } from '@/lib/actionMessages';
import { createNotificationQueue } from '@/lib/notificationQueue';

/**
 * Solid cards, in shades dark enough to keep white text readable: green for
 * success, red for a failed save (which also gets a title above its reason).
 */
const APPEARANCE = {
    success: {
        icon: 'check',
        card: 'bg-gradient-to-br from-emerald-600 to-emerald-700 shadow-[0_18px_40px_-14px_rgba(4,120,87,0.7)]',
    },
    error: {
        title: 'تعذّر الحفظ',
        icon: 'alert',
        card: 'bg-gradient-to-br from-red-600 to-red-700 shadow-[0_18px_40px_-14px_rgba(185,28,28,0.7)]',
    },
    warning: {
        title: 'تنبيه',
        icon: 'alert',
        card: 'bg-gradient-to-br from-amber-500 to-amber-600 shadow-[0_18px_40px_-14px_rgba(180,83,9,0.7)]',
    },
    deleteError: {
        title: 'تعذّر الحذف',
        icon: 'alert',
        card: 'bg-gradient-to-br from-red-600 to-red-700 shadow-[0_18px_40px_-14px_rgba(185,28,28,0.7)]',
    },
};

/** Only the newest few cards are shown at once; hidden older ones still close on their own timer. */
const MAX_VISIBLE = 4;

export default function FlashNotifications({ initialStatus }) {
    const [notifications, setNotifications] = useState([]);
    const queue = useRef(null);

    useEffect(() => {
        const currentQueue = createNotificationQueue(setNotifications);
        queue.current = currentQueue;
        function showStatus(status) {
            currentQueue.push(ACTION_MESSAGES[status] ?? status);
        }
        showStatus(initialStatus);
        // The visits whose response carries a fresh status; a partial reload keeps the page's old one.
        const visitsWithStatus = new Set();
        const unsubscribeStart = router.on('start', (event) => {
            if (visitReturnsStatus(event.detail.visit)) {
                visitsWithStatus.add(event.detail.visit.id);
            }
        });
        const unsubscribeSuccess = router.on('success', (event) => {
            if (visitsWithStatus.delete(event.detail.visitId)) {
                showStatus(event.detail.page.props.status);
            }
        });
        // A single-field failure (e.g. a reading lower than the last one)
        // is shown with its own message; anything else gets the general one.
        const unsubscribeError = router.on('error', (event) => {
            const errors = event.detail.errors ?? {};
            const messages = Object.values(errors);

            // A refused delete says what still uses the record.
            if (errors.delete) {
                currentQueue.push(errors.delete, 'deleteError');

                return;
            }

            currentQueue.push(messages.length === 1 ? messages[0] : 'يرجى مراجعة الحقول المحددة والمحاولة مجددًا.', 'error');
        });

        // A message raised from the page itself, with notify().
        function onNotify(event) {
            currentQueue.push(event.detail?.message, event.detail?.type);
        }
        window.addEventListener('app:notify', onNotify);

        return () => {
            window.removeEventListener('app:notify', onNotify);
            unsubscribeStart();
            unsubscribeSuccess();
            unsubscribeError();
            currentQueue.dispose();
        };
    }, [initialStatus]);

    return (
        <div
            dir="rtl"
            role="region"
            aria-label="الإشعارات"
            className="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-[calc(100%-2rem)] max-w-md flex-col gap-3 sm:bottom-6 sm:right-6"
        >
            {notifications.slice(-MAX_VISIBLE).map((notification) => {
                const appearance = APPEARANCE[notification.type] ?? APPEARANCE.success;

                return (
                    <div
                        key={notification.id}
                        role={notification.type !== 'success' ? 'alert' : 'status'}
                        style={{ '--flash-duration': `${notification.duration}ms` }}
                        onMouseEnter={() => queue.current.pause(notification.id)}
                        onMouseLeave={() => queue.current.resume(notification.id)}
                        className={`flash-notification pointer-events-auto relative shrink-0 overflow-hidden rounded-row text-white ring-1 ring-inset ring-white/15 ${appearance.card} ${
                            notification.type !== 'success' ? 'flash-notification-error' : ''
                        } ${notification.leaving ? 'flash-notification-leaving' : ''}`}
                    >
                        <div className={`flex gap-3 py-4 pe-3 ps-4 ${appearance.title ? 'items-start' : 'items-center'}`}>
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/20 ring-1 ring-inset ring-white/25">
                                <Icon name={appearance.icon} strokeWidth={2.25} />
                            </span>
                            <div className="min-w-0 flex-1">
                                {appearance.title && <p className="text-lg font-bold leading-8">{appearance.title}</p>}
                                <p className={`break-words text-[18px] leading-8 ${appearance.title ? 'text-white/95' : 'font-semibold'}`}>
                                    {notification.message}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => queue.current.dismiss(notification.id)}
                                aria-label="إغلاق الإشعار"
                                className="rounded-lg p-1.5 text-white/70 transition hover:bg-white/15 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-white"
                            >
                                <Icon name="close" className="h-4 w-4" strokeWidth={2} />
                            </button>
                        </div>
                        <div aria-hidden="true" className="h-1 bg-black/15">
                            <div className="flash-notification-countdown h-full bg-white/70" />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
