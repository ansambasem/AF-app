import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import './top-bar.css';

// Filter and sort changes refresh the page in place; their line shows only if they take a while.
const QUIET_DELAY = 250;

/** Whether a visit only refreshes the page in place (filters, sorting, a partial reload). */
function refreshesInPlace(visit) {
    return visit.method === 'get' && (Boolean(visit.preserveState) || visit.only?.length > 0);
}

/**
 * The thin burgundy line at the very top while a page opens: it runs most of
 * the way, waits for the page, then completes and fades. A page already
 * loaded ahead of time (on hover) still gets the full run when it opens;
 * the loading ahead itself shows nothing.
 */
export default function NavigationProgress() {
    const [run, setRun] = useState(null);
    const active = useRef(0);
    const timer = useRef(null);
    const progress = useRef({ finished: false, loaded: false });

    useEffect(() => {
        function start(finished) {
            progress.current = { finished, loaded: false };
            setRun({ phase: 'loading', key: Date.now() });
        }

        const offStart = router.on('start', (event) => {
            const { visit } = event.detail;

            if (!visit.showProgress) {
                return;
            }

            active.current += 1;
            clearTimeout(timer.current);

            if (refreshesInPlace(visit)) {
                timer.current = setTimeout(() => start(false), QUIET_DELAY);
            } else {
                start(false);
            }
        });

        const offFinish = router.on('finish', (event) => {
            if (!event.detail.visit.showProgress) {
                return;
            }

            active.current = Math.max(0, active.current - 1);

            if (active.current > 0) {
                return;
            }

            clearTimeout(timer.current);
            progress.current.finished = true;

            // The line completes once its run to 85% is over, so it never jumps ahead.
            setRun((current) => (current?.phase === 'loading' && progress.current.loaded ? { ...current, phase: 'done' } : current));
        });

        const offNavigate = router.on('navigate', (event) => {
            if (event.detail.cached) {
                start(true);
            }
        });

        return () => {
            offStart();
            offFinish();
            offNavigate();
            clearTimeout(timer.current);
        };
    }, []);

    function onAnimationEnd() {
        if (run?.phase === 'done') {
            setRun(null);
        } else if (run?.phase === 'loading') {
            progress.current.loaded = true;

            if (progress.current.finished) {
                setRun({ ...run, phase: 'done' });
            }
        }
    }

    return (
        <div
            key={run?.key}
            aria-hidden="true"
            className={`tn-progress ${run?.phase === 'loading' ? 'is-loading' : run?.phase === 'done' ? 'is-done' : ''}`}
            onAnimationEnd={onAnimationEnd}
        />
    );
}
