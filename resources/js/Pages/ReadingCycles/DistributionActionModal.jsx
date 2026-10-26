import { useState } from 'react';
import { distributionAreaBreakdown, distributionTargetPreview, columnTotals } from '@/lib/readingBoard';
import { formatNumber } from '@/lib/format';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';
import DistributionDialog from './DistributionDialog';
import DistributionAreaBreakdown from './DistributionAreaBreakdown';

export default function DistributionActionModal({ boards, selectedBoxes, readers, abilities, busy, errors, target, onTargetChange, recurring, onRecurringChange, hiddenSelected, onRemoveHidden, onClose, onAssign, onCreateSheet, allocationReport }) {
    const [mode, setMode] = useState(target === 'paper' || target === 'available' ? target : 'reader');
    const totals = columnTotals(selectedBoxes);
    const preview = distributionTargetPreview(boards, selectedBoxes, target);
    const paperCount = selectedBoxes.filter((box) => box.column === 'paper' && box.status === 'approved').length;

    function changeMode(value) {
        setMode(value);
        onTargetChange(value === 'reader' ? (target.startsWith('reader:') ? target : '') : value);
    }

    return <DistributionDialog title="إجراء على الطبلونات المحددة" subtitle={`${formatNumber(totals.boxes)} طبلون · ${formatNumber(totals.subscribers)} مشترك · يشمل التحديد جميع صفحات النتائج`} busy={busy} onClose={onClose}
        footer={<><SecondaryButton disabled={busy} onClick={onClose}>متابعة التحديد</SecondaryButton><div className="flex flex-wrap gap-2">
            {abilities.printSheets && paperCount > 0 && <SecondaryButton disabled={busy || paperCount > 200} onClick={onCreateSheet}>إنشاء كشف للطباعة ({formatNumber(paperCount)})</SecondaryButton>}
            {abilities.manage && <PrimaryButton onClick={onAssign} disabled={busy || !target || selectedBoxes.length === 0 || selectedBoxes.length > 1000}>{busy ? 'جارٍ الحفظ…' : abilities.approve ? 'حفظ التوزيع' : 'حفظ للمراجعة'}</PrimaryButton>}
        </div></>}>
        {hiddenSelected > 0 && <div role="status" className="rounded-control bg-amber-500/10 p-3 text-xs leading-6 text-amber-700">يشمل التحديد {formatNumber(hiddenSelected)} طبلون خارج النتائج الظاهرة، وسيشملها الحفظ أيضًا. <button type="button" disabled={busy} onClick={onRemoveHidden} className="font-bold underline">إلغاء تحديد المخفي</button></div>}
        {allocationReport}
        <div className="max-h-60 overflow-y-auto rounded-control border border-gray-100 px-4"><DistributionAreaBreakdown areas={distributionAreaBreakdown(boards, selectedBoxes)} /></div>
        {abilities.manage && <>
            <fieldset disabled={busy}>
                <legend className="mb-3 text-sm font-bold text-gray-900">توزيع المحدد إلى</legend>
                <div className="grid gap-2 sm:grid-cols-3">{[['reader', 'قارئ عدادات'], ['paper', 'كشف ورقي'], ['available', 'إلغاء التخصيص']].map(([value, label]) => <label key={value} className={`flex cursor-pointer items-center gap-2 rounded-control border p-3 text-sm font-semibold ${mode === value ? 'border-brand-500 bg-brand-500/5 text-brand-600' : 'border-gray-200 text-gray-700'}`}><input type="radio" name="distribution-mode" value={value} checked={mode === value} onChange={() => changeMode(value)} className="border-gray-300 text-brand-600" />{label}</label>)}</div>
            </fieldset>
            {mode === 'reader' && <div><label htmlFor="distribution-reader" className="mb-2 block text-sm font-semibold text-gray-700">القارئ المكلّف</label><select id="distribution-reader" className="w-full text-sm" disabled={busy} value={target.startsWith('reader:') ? target : ''} onChange={(event) => onTargetChange(event.target.value)}><option value="">اختر القارئ</option>{readers.map((reader) => <option key={reader.id} value={`reader:${reader.id}`}>{reader.name}</option>)}</select>{readers.length === 0 && <p className="mt-2 text-xs text-amber-700">لا يوجد قرّاء متاحون في هذا الفرع.</p>}</div>}
            {target && <div className="rounded-control bg-gray-50 p-4 text-sm text-gray-700" role="status"><p className="font-bold text-gray-900">{mode === 'reader' ? readers.find((reader) => `reader:${reader.id}` === target)?.name : mode === 'paper' ? 'الكشوف الورقية' : 'غير موزع'}</p><p className="mt-2">حاليًا: {formatNumber(preview.before.boxes)} طبلون · {formatNumber(preview.before.subscribers)} مشترك</p><p className="mt-1 font-semibold text-brand-600">بعد الحفظ: {formatNumber(preview.after.boxes)} طبلون · {formatNumber(preview.after.subscribers)} مشترك</p></div>}
            {abilities.approve && <label className="flex items-start gap-2 text-sm leading-6 text-gray-700"><input type="checkbox" checked={recurring} disabled={busy} onChange={(event) => onRecurringChange(event.target.checked)} className="mt-1 rounded border-gray-300 text-brand-600" /><span>حفظ هذا التوزيع للقراءات القادمة أيضًا<span className="block text-xs text-gray-500">{recurring ? 'يصبح التوزيع الثابت للطبلونات المحددة.' : 'لهذه القراءة فقط؛ يبقى التوزيع الثابت للقراءات التالية.'}</span></span></label>}
            {selectedBoxes.length > 1000 && <p className="text-sm text-amber-700">يمكن توزيع 1,000 طبلون في المرة الواحدة. قلّل التحديد ووزّع البقية بعدها.</p>}
        </>}
        {paperCount > 200 && <p className="text-sm text-amber-700">يمكن وضع 200 طبلون في الكشف الواحد. حدّد منطقة أصغر لإنشاء الكشف.</p>}
        {!abilities.manage && paperCount === 0 && <p className="text-sm text-gray-500">إنشاء الكشف يتطلب تحديد طبلونات مخصصة للورق ومعتمدة.</p>}
        {Object.entries(errors).filter(([key]) => /^(box_ids|versions|target|user_id|recurring|publish)(\.|$)/.test(key)).map(([key, message]) => <InputError key={key} message={message} />)}
    </DistributionDialog>;
}
