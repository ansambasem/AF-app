import Icon from '@/Components/Icon';
import { formatMoney } from '@/lib/format';

const ACTION_LABELS = {
    add: 'إضافة المشتركين',
    reschedule: 'تغيير موعد القطع',
    remove: 'الإزالة من القائمة',
    warn: 'إرسال الإنذار',
    confirm: 'تأكيد القطع',
};

/**
 * How a bulk action went for each subscriber: how many it was applied to,
 * and each one it could not be applied to with why — never "all done" when
 * some were not.
 */
export default function ResultReport({ result, onClose }) {
    const failed = result.failed ?? [];
    const tone = result.succeeded === 0 ? 'border-red-500/30 bg-red-500/5' : failed.length ? 'border-amber-500/30 bg-amber-500/5' : 'border-emerald-500/30 bg-emerald-500/5';
    const heading = result.succeeded === 0 ? 'لم يُطبَّق الإجراء' : failed.length ? 'طُبّق الإجراء جزئيًا' : 'طُبّق الإجراء بنجاح';

    return (
        <section role="status" aria-live="polite" className={`mb-5 rounded-card border p-4 ${tone}`}>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="font-bold text-gray-900">
                        {ACTION_LABELS[result.action] ?? 'الإجراء'}: {heading}
                    </p>
                    <p className="mt-1 text-sm text-gray-700">
                        نجح لـ <b className="font-display">{result.succeeded.toLocaleString('en')}</b> مشترك
                        {failed.length > 0 && <> · لم يُطبَّق على <b className="font-display">{failed.length.toLocaleString('en')}</b></>}
                        {result.action === 'confirm' && result.succeeded > 0 && <> · رسوم القطع المحمّلة <b className="font-display">{formatMoney(result.feesTotal)} ₪</b></>}
                        {result.action === 'warn' && result.batchId && (
                            <>
                                {' · '}
                                <a href={`/messages/${result.batchId}`} className="font-semibold text-brand-600 hover:underline">
                                    {result.channel === 'whatsapp' ? 'افتح الرسائل لإرسالها عبر واتساب' : 'تابع حالة الإرسال'}
                                </a>
                            </>
                        )}
                    </p>
                </div>
                <button type="button" onClick={onClose} aria-label="إغلاق التقرير" className="rounded-control p-1.5 text-gray-500 hover:bg-gray-100">
                    <Icon name="close" className="h-4 w-4" />
                </button>
            </div>
            {failed.length > 0 && (
                <ul className="mt-3 max-h-56 divide-y divide-gray-100 overflow-y-auto rounded-control border border-gray-100 bg-surface text-sm">
                    {failed.map((item, index) => (
                        <li key={`${item.id ?? item.subscriptionId ?? 'x'}-${index}`} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <span>
                                <b className="text-gray-900">{item.name}</b>
                                {item.accountNumber && <bdi dir="ltr" className="ms-2 font-display text-xs text-gray-500">{item.accountNumber}</bdi>}
                            </span>
                            <span className="text-xs text-gray-600">{item.reason}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
