import { useCallback, useRef } from 'react';

/** What already does its own thing when clicked inside a row. */
const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [role="menu"], [contenteditable="true"]';

/** How far the pointer may move between press and release and still count as a click, not a text selection. */
const CLICK_SLOP = 5;

/**
 * Lets a whole table row be clicked to open it, so nobody has to aim for
 * the button. Returns a function giving a row's props:
 *
 *     const rowClick = useRowClick();
 *     <tr {...rowClick(() => open(record))}>
 *
 * Clicks on the row's own buttons, links and fields keep their own
 * behaviour, and dragging to select text doesn't open the row. Pass
 * nothing (or null) for a row that doesn't open.
 */
export function useRowClick() {
    const pressedAt = useRef(null);

    return useCallback(
        (open) =>
            open
                ? {
                      'data-clickable': '',
                      onPointerDown: (event) => {
                          pressedAt.current = { x: event.clientX, y: event.clientY };
                      },
                      onClick: (event) => {
                          const start = pressedAt.current;
                          const dragged = start && Math.hypot(event.clientX - start.x, event.clientY - start.y) > CLICK_SLOP;
                          // React passes clicks up from portals too (a row's menu, a dialog): only the row's own cells count.
                          const outsideRow = !event.currentTarget.contains(event.target);

                          if (outsideRow || dragged || event.target.closest(INTERACTIVE) || window.getSelection()?.toString()) {
                              return;
                          }

                          open();
                      },
                  }
                : {},
        [],
    );
}
