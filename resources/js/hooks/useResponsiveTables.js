import { useEffect } from 'react';

/**
 * Gives every cell of every `.data-table` a `data-label` with its column's
 * title, so on a narrow screen (see app.css) each row can show as a card
 * with the title above each value. The row buttons' column (ActionsTh)
 * gets none. Re-runs whenever the page's rows change, at most once a
 * frame however many changes come in at once.
 */
export function useResponsiveTables() {
    useEffect(() => {
        let frame = null;

        function label() {
            frame = null;

            document.querySelectorAll('table.data-table').forEach((table) => {
                const titles = [...table.querySelectorAll('thead th')].map((th) => (th.hasAttribute('data-actions') ? '' : th.textContent.trim()));

                table.querySelectorAll('tbody tr').forEach((row) => {
                    [...row.children].forEach((cell, index) => {
                        const title = titles[index] ?? '';

                        if (cell.dataset.label !== title) {
                            cell.dataset.label = title;
                        }
                    });
                });
            });
        }

        function schedule() {
            frame ??= requestAnimationFrame(label);
        }

        label();
        const observer = new MutationObserver(schedule);
        observer.observe(document.body, { childList: true, subtree: true });

        return () => {
            observer.disconnect();

            if (frame !== null) {
                cancelAnimationFrame(frame);
            }
        };
    }, []);
}
