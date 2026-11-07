import { useEffect, useState } from 'react';

/**
 * A number that counts up from 0 when it first shows. People who ask their
 * device for less motion see the final number straight away.
 */
export default function CountUp({ value, duration = 1100 }) {
    const target = Number(value) || 0;
    const [shown, setShown] = useState(() => (prefersReducedMotion() ? target : 0));

    useEffect(() => {
        if (prefersReducedMotion()) {
            setShown(target);
            return;
        }

        let frame;
        const start = performance.now();

        function tick(now) {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            setShown(Math.round(target * eased));

            if (progress < 1) {
                frame = requestAnimationFrame(tick);
            }
        }

        frame = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(frame);
    }, [target, duration]);

    return <span className="tabular-nums">{shown.toLocaleString('en')}</span>;
}

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}
