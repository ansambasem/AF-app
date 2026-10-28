import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/Components/Icon';

/**
 * A small menu under a button, closed by a click outside it, Esc, or the
 * page scrolling. It is drawn over the page (a portal), so a box that clips
 * what overflows it, like the dark employee card, never cuts it off.
 */
export function MenuButton({ icon, label, children, className = '' }) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState(null);
    const ref = useRef(null);
    const menuRef = useRef(null);

    function toggle() {
        if (open) {
            setOpen(false);
            return;
        }

        const rect = ref.current.getBoundingClientRect();
        const width = Math.min(Math.max(320, rect.width), window.innerWidth - 16);
        // Its end lines up with the button's end (the left, right to left), kept inside the window.
        setPosition({ top: rect.bottom + 8, left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)), width });
        setOpen(true);
    }

    useEffect(() => {
        if (!open) {
            return;
        }

        function onPointerDown(event) {
            if (!ref.current?.contains(event.target) && !menuRef.current?.contains(event.target)) {
                setOpen(false);
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        function onScroll(event) {
            if (!menuRef.current?.contains(event.target)) {
                setOpen(false);
            }
        }

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onScroll);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        };
    }, [open]);

    return (
        <div ref={ref} className="relative">
            <button type="button" aria-haspopup="menu" aria-expanded={open} onClick={toggle} className={className}>
                <Icon name={icon} className="h-4 w-4" />
                {label}
                <Icon name="chevron-down" className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>
            {open &&
                position &&
                createPortal(
                    <div
                        ref={menuRef}
                        role="menu"
                        dir="rtl"
                        style={{ top: position.top, left: position.left, width: position.width }}
                        className="animate-dropdown fixed z-[60] max-h-80 overflow-y-auto rounded-2xl border border-gray-100 bg-surface p-1.5 text-gray-900 shadow-lift"
                    >
                        {children(() => setOpen(false))}
                    </div>,
                    document.body,
                )}
        </div>
    );
}

export function MenuItem({ title, subtitle, trailing, onSelect, leading }) {
    return (
        <button
            type="button"
            role="menuitem"
            onClick={onSelect}
            className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-start text-sm transition hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none"
        >
            {leading}
            <span className="min-w-0 flex-1">
                <span className="block truncate font-semibold text-gray-900">{title}</span>
                {subtitle && <span className="block truncate text-xs text-gray-500">{subtitle}</span>}
            </span>
            {trailing}
        </button>
    );
}
