import { useEffect, useId, useRef, useState } from 'react';
import Icon from '@/Components/Icon';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { holdProgress, typedCountMatches } from '@/lib/holdConfirmation';

/**
 * A deliberate final confirmation: a button held down for three
 * seconds — with the pointer, a finger, or Space / Enter — while a bar fills
 * from 0 to 100%. Letting go, moving off it or cancelling starts it over and
 * sends nothing. At 100% `onConfirm` is called once. For someone who cannot
 * hold a button, typing the number of records is an equal, deliberate
 * way to confirm.
 */
export default function HoldToConfirm({ count, disabled, busy, onConfirm, label, tone = 'danger', noun = 'المشتركين', confirmLabel = 'تأكيد القطع', icon = 'power' }) {
    const id = useId();
    const [progress, setProgress] = useState(0);
    const [typing, setTyping] = useState(false);
    const [typed, setTyped] = useState('');
    const startedAt = useRef(null);
    const frame = useRef(null);
    const done = useRef(false);
    const wasBusy = useRef(false);
    const blocked = useRef(false);
    blocked.current = disabled || busy;
    const Button = tone === 'primary' ? PrimaryButton : 'button';

    useEffect(() => () => cancelAnimationFrame(frame.current), []);

    useEffect(() => {
        if (!busy && wasBusy.current) {
            done.current = false;
            reset();
        }
        wasBusy.current = busy;
        if (disabled || busy) {
            reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [disabled, busy]);

    useEffect(() => {
        const hide = () => { if (document.hidden) reset(); };
        window.addEventListener('blur', reset);
        document.addEventListener('visibilitychange', hide);
        return () => {
            window.removeEventListener('blur', reset);
            document.removeEventListener('visibilitychange', hide);
        };
    }, []);

    function tick(now) {
        const reached = holdProgress(now - startedAt.current);
        setProgress(reached);

        if (reached >= 100) {
            finish();

            return;
        }

        frame.current = requestAnimationFrame(tick);
    }

    function start() {
        if (disabled || busy || done.current || startedAt.current !== null) {
            return;
        }

        startedAt.current = performance.now();
        frame.current = requestAnimationFrame(tick);
    }

    function reset() {
        cancelAnimationFrame(frame.current);
        startedAt.current = null;

        if (!done.current) {
            setProgress(0);
        }
    }

    function finish() {
        cancelAnimationFrame(frame.current);
        startedAt.current = null;

        if (done.current || blocked.current) {
            return;
        }

        done.current = true;
        onConfirm();
    }

    function onKeyDown(event) {
        if ((event.key === ' ' || event.key === 'Enter') && !event.repeat) {
            event.preventDefault();
            start();
        }
    }

    function onKeyUp(event) {
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();
            reset();
        }
    }

    const typedMatches = typedCountMatches(typed, count);

    return (
        <div className="space-y-3">
            <Button
                type="button"
                disabled={disabled || busy}
                aria-describedby={`${id}-hint`}
                onPointerDown={(event) => { if (event.button === 0) start(); }}
                onPointerMove={(event) => {
                    // A finger keeps the pointer even off the button, so leaving it is checked by position.
                    const box = event.currentTarget.getBoundingClientRect();

                    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
                        reset();
                    }
                }}
                onPointerUp={reset}
                onPointerCancel={reset}
                onPointerLeave={reset}
                onLostPointerCapture={reset}
                onKeyDown={onKeyDown}
                onKeyUp={onKeyUp}
                onBlur={reset}
                onContextMenu={(event) => event.preventDefault()}
                className={`relative flex w-full touch-none select-none items-center justify-center gap-2 overflow-hidden ${tone === 'primary' ? 'py-3.5 !text-base' : 'rounded-control bg-red-700 px-5 py-3.5 text-base font-bold text-white shadow-card transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500 dark:disabled:bg-gray-700'}`}
            >
                <span aria-hidden="true" className="absolute inset-y-0 start-0 bg-red-950/45" style={{ width: `${progress}%` }} />
                <span className="relative flex items-center gap-2">
                    <Icon name={icon} className="h-5 w-5" />
                    {busy ? 'جارٍ التنفيذ…' : progress > 0 ? `استمر بالضغط… ${progress}%` : label}
                </span>
            </Button>
            <div
                role="progressbar"
                aria-label="تقدّم الضغط المستمر"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={progress}
                className="h-2 overflow-hidden rounded-full bg-gray-100"
            >
                <div className={`h-full rounded-full transition-[width] duration-75 ${tone === 'primary' ? 'bg-brand-600' : 'bg-red-600'}`} style={{ width: `${progress}%` }} />
            </div>
            <p id={`${id}-hint`} className="text-sm leading-7 text-gray-600">
                استمر ٣ ثوانٍ بالضغط أو بمفتاح Space / Enter. ترك الزر يوقف التأكيد.
            </p>

            {!typing ? (
                <button type="button" onClick={() => setTyping(true)} disabled={disabled || busy} className="min-h-11 text-sm font-semibold text-gray-700 underline-offset-2 hover:underline disabled:opacity-50">
                    أو أكّد بكتابة عدد {noun}
                </button>
            ) : (
                <div className="rounded-control border border-gray-200 bg-gray-50 p-3">
                    <label htmlFor={`${id}-count`} className="mb-1.5 block text-base font-semibold text-gray-700">
                        اكتب عدد {noun} (<b>{count.toLocaleString('en')}</b>) للتأكيد
                    </label>
                    <div className="flex gap-2">
                        <TextInput
                            id={`${id}-count`}
                            type="text"
                            inputMode="numeric"
                            autoComplete="off"
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                            disabled={disabled || busy}
                            className="block w-28 text-sm"
                        />
                        <Button
                            type="button"
                            disabled={disabled || busy || !typedMatches || done.current}
                            onClick={finish}
                            className={tone === 'primary' ? '' : 'rounded-control bg-red-700 px-4 py-2 text-sm font-bold text-white disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500'}
                        >
                            {confirmLabel}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
