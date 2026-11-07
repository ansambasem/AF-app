import { useCallback, useState } from 'react';

/** One menu shared by a row's action button, context click and keyboard shortcut. */
export function useRowActionsMenu() {
    const [opener, setOpener] = useState(null);

    const toggleMenu = useCallback((event) => {
        const anchor = event.currentTarget;
        setOpener((current) => current ? null : { anchor });
    }, []);

    const closeMenu = useCallback((restoreFocus) => {
        if (restoreFocus) {
            opener?.anchor.focus({ preventScroll: true });
        }
        setOpener(null);
    }, [opener]);

    function rowProps(enabled) {
        if (!enabled) {
            return {};
        }

        function belongsToRow(event) {
            return event.currentTarget.contains(event.target)
                && !event.target.closest('input, textarea, select, [contenteditable="true"]');
        }

        return {
            tabIndex: 0,
            'aria-haspopup': 'menu',
            onContextMenu(event) {
                if (!belongsToRow(event)) {
                    return;
                }
                event.preventDefault();
                setOpener({ anchor: event.currentTarget, point: { x: event.clientX, y: event.clientY }, contextual: true });
            },
            onKeyDown(event) {
                if (belongsToRow(event) && (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10'))) {
                    event.preventDefault();
                    event.stopPropagation();
                    setOpener({ anchor: event.currentTarget, contextual: true });
                }
            },
        };
    }

    return { opener, toggleMenu, closeMenu, rowProps };
}
