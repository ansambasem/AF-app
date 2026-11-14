import ConfirmDialog from '@/Components/ConfirmDialog';
import { formatShekels } from '@/lib/currency';

export default function ReadingApprovalDialog({ row, onConfirm, onCancel }) {
    if (!row) return null;

    return <ConfirmDialog show title="اعتماد هذه القراءة؟" onConfirm={onConfirm} onCancel={onCancel} confirmLabel="اعتماد القراءة" cancelLabel="العودة للمراجعة" message={`سيُضاف مبلغ قراءة ${row.fullName} إلى حسابه. يشمل هذا التأكيد هذه القراءة فقط.`}>
        <dl className="grid grid-cols-2 gap-4 rounded-control bg-gray-50 p-4">
            {[['القراءة السابقة', row.previousReading], ['القراءة الجديدة', row.reading.currentReading], ['الاستهلاك (كيلو)', row.reading.consumption], ['المبلغ المستحق', formatShekels(row.reading.amountDue)]].map(([label, value]) => <div key={label}><dt className="text-sm text-gray-600">{label}</dt><dd className="mt-1 font-display text-xl font-bold text-gray-900">{value}</dd></div>)}
        </dl>
        {row.reading.unusual && <p className="mt-4 rounded-control bg-amber-500/10 p-4 text-base leading-7 text-amber-800 dark:text-amber-300">هذه القراءة أعلى بكثير من الاستهلاك المعتاد. باعتمادها تؤكد أنك راجعتها وأنها صحيحة.</p>}
    </ConfirmDialog>;
}
