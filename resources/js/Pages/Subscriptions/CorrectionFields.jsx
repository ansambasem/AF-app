import ChoiceChips from '@/Components/ChoiceChips';
import Icon from '@/Components/Icon';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { formatAmount } from '@/lib/currency';
import { formatDate } from '@/lib/format';

/**
 * The line being corrected, cancelled or erased, in that action's colour.
 */
export function OriginalLine({ entry, tone = 'correction' }) {
    const styles = {
        correction: ['border-amber-500/25 bg-amber-500/5', 'bg-amber-500/10 text-amber-700', 'text-amber-700', 'repeat'],
        cancel: ['border-brand-500/25 bg-brand-500/5', 'bg-brand-500/10 text-brand-600', 'text-brand-700', 'close'],
        erase: ['border-red-900/20 bg-red-900/5', 'bg-red-900/10 text-red-800', 'text-red-800', 'trash'],
    }[tone];

    return (
        <div className={`flex items-start gap-3 rounded-xl border px-4 py-3 text-sm ${styles[0]}`}>
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${styles[1]}`}>
                <Icon name={styles[3]} className="h-[18px] w-[18px]" />
            </span>
            <div className="min-w-0">
                <p className={`text-xs font-semibold ${styles[2]}`}>الحركة الأصلية</p>
                <p className="mt-0.5 font-semibold text-gray-900">
                    {entry.description} ·{' '}
                    <span className="font-display tabular-nums">
                        {formatAmount(entry.amount)} {entry.currencyLabel}
                    </span>
                </p>
                <p className="mt-0.5 text-xs text-gray-500">
                    <bdi dir="ltr">{formatDate(entry.date)}</bdi>
                    {entry.voucherNumber && ` · سند ${entry.voucherNumber}`}
                    {entry.recordedByName && ` · سجّلها ${entry.recordedByName}`}
                </p>
            </div>
        </div>
    );
}

/**
 * Why the line is corrected or deleted: one of `reasons` and a required
 * explanation, kept with the line in the statement.
 */
export function CorrectionReasonFields({ form, reasons, action = 'التصحيح' }) {
    const { data, setData, errors } = form;

    return (
        <div className="space-y-4">
            <div>
                <InputLabel htmlFor="correction_reason" value={`سبب ${action}`} />
                <div className="mt-1.5">
                    <ChoiceChips
                        id="correction_reason"
                        name="correction_reason"
                        label={`سبب ${action}`}
                        required
                        value={data.correction_reason}
                        onChange={(value) => {
                            setData('correction_reason', value);
                            form.clearErrors('correction_reason');
                        }}
                        options={reasons}
                    />
                </div>
                <InputError message={errors.correction_reason} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor="correction_notes" value={`شرح ${action} (يظهر تحت الحركة في كشف الحساب)`} />
                <textarea
                    id="correction_notes"
                    name="correction_notes"
                    rows={2}
                    required
                    maxLength={1000}
                    className="mt-1 block w-full"
                    placeholder={action === 'الإلغاء' ? 'مثال: الدفعة سُجّلت مرتين بالخطأ، أو استرجع المشترك المبلغ' : 'مثال: المشترك دفع 100 شيكل وليس 80'}
                    value={data.correction_notes}
                    onChange={(e) => setData('correction_notes', e.target.value)}
                />
                <InputError message={errors.correction_notes} className="mt-2" />
            </div>
        </div>
    );
}

/** The fields a correction adds to its form, empty. */
export const EMPTY_CORRECTION = { correction_reason: '', correction_notes: '' };
