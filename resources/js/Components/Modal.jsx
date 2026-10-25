import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';

// The modals open right now, oldest first. Escape closes only the newest,
// so a confirmation shown over a form doesn't close the form with it.
const openModals = [];

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** The elements Tab can reach inside `root`, in order, leaving out hidden ones. */
function focusables(root) {
    return [...root.querySelectorAll(FOCUSABLE)].filter((element) => element.getClientRects().length > 0);
}

let titleCount = 0;

/**
 * Rendered into <body>, so a modal opened from inside another one (or
 * from inside an animated card) still covers the whole screen. `centered`
 * puts the panel in the middle of the screen instead of near the top.
 *
 * While open, the page behind stays still (its scroll is locked), Tab and
 * Shift+Tab stay inside the newest window, and closing returns focus to
 * whatever opened it. A click outside the panel closes it only when the
 * press started outside too, so selecting text in a field and letting go
 * past the edge keeps the window open. A panel whose content doesn't name
 * its own role is announced as a dialog, titled by its first heading.
 */
export default function Modal({ show, onClose, children, maxWidth = 'md', centered = false, panelClassName = '' }) {
    const onCloseRef = useRef(onClose);
    const panelRef = useRef(null);
    const pressStartedOutside = useRef(false);
    // What had focus when the window opened (taken before any field inside it autofocuses), to go back to on close.
    const openerRef = useRef(null);

    if (show && openerRef.current === null && typeof document !== 'undefined') {
        openerRef.current = document.activeElement;
    } else if (!show) {
        openerRef.current = null;
    }

    useEffect(() => {
        onCloseRef.current = onClose;
    });

    useEffect(() => {
        if (!show) {
            return;
        }

        const modal = {};
        const opener = openerRef.current;
        const root = document.documentElement;
        openModals.push(modal);
        // The page behind stays still while any window is open (see app.css).
        root.dataset.modalOpen = '';

        const panel = panelRef.current;
        if (panel && !panel.querySelector('[role="dialog"], [role="alertdialog"]')) {
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
            const heading = panel.querySelector('h1, h2, h3');
            if (heading) {
                heading.id ||= `modal-title-${++titleCount}`;
                panel.setAttribute('aria-labelledby', heading.id);
            }
        }

        // Focus moves inside, unless something in the window already took it (an autofocus field).
        if (panel && !panel.contains(document.activeElement)) {
            (panel.querySelector('[autofocus]') ?? focusables(panel)[0] ?? panel).focus({ preventScroll: true });
        }

        function onKeyDown(e) {
            if (openModals.at(-1) !== modal) {
                return;
            }

            if (e.key === 'Escape') {
                onCloseRef.current?.();
                return;
            }

            if (e.key === 'Tab' && panelRef.current) {
                const items = focusables(panelRef.current);

                if (items.length === 0) {
                    e.preventDefault();
                    return;
                }

                const active = document.activeElement;
                const inside = panelRef.current.contains(active);

                // A calendar or list opened from the window is drawn outside it, in <body>: Tab moves on inside it as usual.
                if (!inside && active && active !== document.body) {
                    return;
                }

                if (e.shiftKey && (active === items[0] || !inside)) {
                    e.preventDefault();
                    items.at(-1).focus();
                } else if (!e.shiftKey && (active === items.at(-1) || !inside)) {
                    e.preventDefault();
                    items[0].focus();
                }
            }
        }

        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            openModals.splice(openModals.indexOf(modal), 1);

            if (openModals.length === 0) {
                delete root.dataset.modalOpen;
            }

            // Back to the button that opened it, if it is still on the page.
            if (opener instanceof HTMLElement && opener !== document.body && opener.isConnected) {
                opener.focus({ preventScroll: true });
            }
        };
    }, [show]);

    if (!show) {
        return null;
    }

    const maxWidthClass = {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        '3xl': 'sm:max-w-3xl',
        '4xl': 'sm:max-w-4xl',
        '5xl': 'sm:max-w-5xl',
        '6xl': 'sm:max-w-[1140px]',
        '7xl': 'sm:max-w-7xl',
        full: 'sm:max-w-none',
    }[maxWidth];

    return createPortal(
        <div
            className={`modal-scroll fixed inset-0 z-50 overflow-y-auto overscroll-contain px-4 py-6 sm:px-6 ${centered ? 'flex items-center' : ''}`}
            onPointerDown={(e) => {
                pressStartedOutside.current = e.target === e.currentTarget;
            }}
            onClick={(e) => {
                if (e.target === e.currentTarget && pressStartedOutside.current) {
                    onClose?.();
                }
                pressStartedOutside.current = false;
            }}
        >
            <div className="modal-glass-backdrop animate-modal-backdrop pointer-events-none fixed inset-0" aria-hidden="true" />
            <div
                ref={panelRef}
                tabIndex={-1}
                className={`modal-glass-panel animate-modal-panel relative mx-auto mb-6 mt-6 w-full overflow-hidden rounded-panel outline-none ${maxWidthClass} ${panelClassName}`}
            >
                <span aria-hidden="true" className="pointer-events-none absolute inset-x-16 top-0 z-10 h-[2px] rounded-full bg-spectrum opacity-80" />
                {children}
            </div>
        </div>,
        document.body,
    );
}
