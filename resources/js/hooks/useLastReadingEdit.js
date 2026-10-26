import { useRef, useState } from 'react';
import { router, useHttp } from '@inertiajs/react';
import { steppedReading } from '@/lib/readings';

/** Pick the purpose before changing a baseline; the current reading stays a separate table entry. */
export function useLastReadingEdit({ readingId, savedValue, floor, previewUrl, saveUrl, weekStart, isInitial = false }) {
    const saved = String(savedValue);
    const [phase, setPhase] = useState(null);
    const [adjustmentType, setAdjustmentType] = useState('');
    const [value, setValue] = useState(saved);
    const [reason, setReason] = useState('');
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [preview, setPreview] = useState(null);
    const previewRequest = useHttp({});
    const busy = useRef(false);
    const inputRef = useRef(null);
    const canContinue = Boolean(adjustmentType && (adjustmentType === 'correction' || reason.trim()));

    function open() {
        busy.current = false;
        setValue(saved);
        setAdjustmentType('');
        setReason('');
        setError(null);
        setPreview(null);
        setPhase('reason');
    }

    function close() {
        setPhase(null);
        setPreview(null);
        setError(null);
    }

    function payload() {
        return { current_reading: value, expected_reading: saved, adjustment_type: adjustmentType, reason, ...(weekStart ? { week_start: weekStart } : {}) };
    }

    function review() {
        if (busy.current) { return; }
        if (!canContinue) {
            setPhase('reason');
            return;
        }
        if (value === '' || !Number.isFinite(Number(value)) || Number(value) < 0) {
            setError('أدخل قراءة صحيحة، صفرًا أو أكثر.');
            return;
        }
        if (adjustmentType === 'correction' && Number(value) === Number(saved)) {
            setError('أدخل القراءة المصحّحة بدل القيمة المسجلة.');
            return;
        }
        busy.current = true;
        setSaving(true);
        setError(null);
        previewRequest.transform(payload);
        previewRequest.post(previewUrl ?? `/meter-readings/${readingId}/adjustment-preview`, {
            onError: (errors) => setError(Object.values(errors)[0] ?? 'تعذّر حساب التعديل.'),
        }).then((loaded) => {
            if (loaded) {
                setPreview(loaded);
                setPhase('review');
            }
        }).catch(() => setError('تعذّر حساب التعديل؛ حاول مرة أخرى.'))
            .finally(() => { busy.current = false; setSaving(false); });
    }

    function confirm({ confirmUnusual = false } = {}) {
        if (busy.current || phase !== 'review' || !preview || !canContinue) { return; }
        busy.current = true;
        router.put(saveUrl ?? `/meter-readings/${readingId}/last-reading`, {
            ...payload(), confirm_unusual: confirmUnusual,
        }, {
            preserveScroll: true, preserveState: true,
            onStart: () => setSaving(true),
            onFinish: () => { busy.current = false; setSaving(false); },
            onSuccess: close,
            onError: (errors) => {
                setError(Object.values(errors)[0] ?? 'تعذّر حفظ القراءة السابقة.');
                if (errors.reason || errors.adjustment_type) { setPhase('reason'); }
            },
        });
    }

    return {
        phase, adjustmentType, value, savedValue: saved, floor, reason, error, saving, preview, canContinue, isInitial,
        open, cancel: close, review, confirm,
        chooseType: (type) => { setAdjustmentType(type); setPreview(null); setError(null); },
        setReason: (text) => { setReason(text); setError(null); },
        beginReading: () => { if (canContinue) { setError(null); setPhase('reading'); } },
        back: () => { setError(null); setPhase(phase === 'review' ? 'reading' : 'reason'); },
        step: (direction) => {
            setValue((current) => steppedReading(current, adjustmentType === 'correction' ? floor : 0, direction));
            setError(null);
            inputRef.current?.focus();
        },
        inputProps: {
            ref: inputRef, type: 'number', inputMode: 'decimal', step: '0.01',
            min: adjustmentType === 'correction' ? floor : 0, max: '9999999999.99',
            dir: 'ltr', autoFocus: true, value, disabled: saving, 'aria-invalid': Boolean(error),
            onFocus: (event) => event.target.select(),
            onChange: (event) => { setValue(event.target.value); setError(null); },
            onKeyDown: (event) => {
                if (event.key === 'ArrowUp' || event.key === 'ArrowDown') { event.preventDefault(); }
            },
        },
    };
}
