import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/Components/Icon';

/**
 * The one place a page, a section or a field keeps its guidance: a small
 * "!" beside its title that shows the note on hover, focus or a tap, so the
 * screen itself stays quiet. The note is drawn over everything (a portal),
 * so a dialog's scrolling body never cuts it off. `tone="warning"` is for a
 * note the user must not miss (amber instead of grey). The note is always
 * linked to the button for screen readers, open or not.
 */
export default function InfoTip({ children, label = 'معلومة', tone = 'info', className = '' }) {
    const id = useId();
    const buttonRef = useRef(null);
    const bubbleRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [pinned, setPinned] = useState(false);

    useLayoutEffect(() => {
        const button = buttonRef.current;
        const bubble = bubbleRef.current;

        if (!open || !button || !bubble) {
            return;
        }

        const box = button.getBoundingClientRect();
        const viewport = document.documentElement.clientWidth;
        const below = box.bottom + 8 + bubble.offsetHeight < window.innerHeight;
        bubble.style.top = `${below ? box.bottom + 8 : box.top - bubble.offsetHeight - 8}px`;
        // Right to left: the note hangs from the button's right edge, kept on screen.
        bubble.style.left = `${Math.max(12, Math.min(viewport - bubble.offsetWidth - 12, box.right - bubble.offsetWidth))}px`;
    }, [open]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        function close() {
            setOpen(false);
            setPinned(false);
        }

        function onPointerDown(event) {
            if (!buttonRef.current?.contains(event.target) && !bubbleRef.current?.contains(event.target)) {
                close();
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                // Only the note closes, not the dialog under it.
                event.stopPropagation();
                close();
            }
        }

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown, true);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown, true);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
    }, [open]);

    const warning = tone === 'warning';

    return (
        <>
            <button
                ref={buttonRef}
                type="button"
                aria-label={label}
                aria-describedby={id}
                aria-expanded={open}
                onClick={() => {
                    setPinned(!pinned);
                    setOpen(!pinned);
                }}
                onMouseEnter={() => setOpen(true)}
                onMouseLeave={() => !pinned && setOpen(false)}
                onFocus={() => setOpen(true)}
                onBlur={() => !pinned && setOpen(false)}
                className={`tap-expand inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full align-middle transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-gray-900 ${
                    warning ? 'text-amber-600 hover:bg-amber-500/10 dark:text-amber-400' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700'
                } ${open ? (warning ? 'bg-amber-500/10' : 'bg-gray-100 text-gray-700') : ''} ${className}`}
            >
                <Icon name="alert" className="h-[18px] w-[18px]" />
            </button>
            <span id={id} className="sr-only">
                {children}
            </span>
            {open &&
                createPortal(
                    <div
                        ref={bubbleRef}
                        role="tooltip"
                        dir="rtl"
                        className={`animate-menu fixed z-[70] w-max max-w-[min(20rem,calc(100vw-24px))] whitespace-pre-line rounded-control border px-3.5 py-2.5 text-start text-sm font-normal leading-6 shadow-lift ${
                            warning ? 'border-amber-500/30 bg-amber-50 text-amber-900 dark:bg-gray-100 dark:text-amber-200' : 'border-gray-200 bg-surface text-gray-700'
                        }`}
                        onMouseEnter={() => setOpen(true)}
                        onMouseLeave={() => !pinned && setOpen(false)}
                    >
                        {children}
                    </div>,
                    document.body,
                )}
        </>
    );
}
