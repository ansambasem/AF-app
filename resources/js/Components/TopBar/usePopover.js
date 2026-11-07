import { useEffect, useLayoutEffect, useRef } from 'react';

/**
 * A small menu hanging under its button in the top bar: placed just below the
 * button and kept on screen, its first item focused when it opens. The arrow
 * keys move through its items; a click outside closes it; Esc closes it and
 * returns to the button.
 */
export function usePopover(open, onClose) {
    const anchorRef = useRef(null);
    const popoverRef = useRef(null);
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    useLayoutEffect(() => {
        const anchor = anchorRef.current;
        const popover = popoverRef.current;

        if (!open || !anchor || !popover) {
            return;
        }

        const box = anchor.getBoundingClientRect();
        const viewport = document.documentElement.clientWidth;
        popover.style.top = `${box.bottom + 12}px`;
        popover.style.left = `${Math.max(12, Math.min(viewport - popover.offsetWidth - 12, box.left))}px`;
        requestAnimationFrame(() => popover.querySelector('button:not([disabled]), a')?.focus());
    }, [open]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        function onPointerDown(event) {
            if (!popoverRef.current?.contains(event.target) && !anchorRef.current?.contains(event.target)) {
                onCloseRef.current();
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                onCloseRef.current();
                anchorRef.current?.focus();
            } else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && popoverRef.current?.contains(document.activeElement)) {
                event.preventDefault();
                const items = [...popoverRef.current.querySelectorAll('a[href], button')].filter((element) => element.offsetParent && !element.disabled);
                const index = items.indexOf(document.activeElement);
                items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
            }
        }

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return { anchorRef, popoverRef };
}
