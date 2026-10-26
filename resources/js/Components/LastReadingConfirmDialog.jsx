import { useEffect, useId, useState } from 'react';
import DialogFrame from '@/Components/DialogFrame';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import { formatMoney } from '@/lib/format';

const PURPOSES = [
    { value: 'correction', label: 'تصحيح خطأ إدخال', hint: 'إعادة حساب الفاتورة بالقراءة الصحيحة.' },
    { value: 'meter_change', label: 'عداد جديد / مستبدل', hint: 'الفاتورة السابقة محفوظة، والبداية من العداد الجديد.' },
];

/** Purpose, value, then the financial effect; the current reading is entered later in the table. */
export default function LastReadingConfirmDialog({ editor, subscriptionName }) {
    const id = useId();
    const [confirmedUnusual, setConfirmedUnusual] = useState(false);
    const preview = editor.preview;
    const week = preview?.weeks?.[0];
    const meterChange = editor.adjustmentType === 'meter_change';
    const unusual = !meterChange && week?.isApproved && week?.unusual;
    const phase = editor.phase;
    const steps = ['reason', 'reading', 'review'];
    const stepIndex = steps.indexOf(phase);
    const purpose = PURPOSES.find((option) => option.value === editor.adjustmentType);
    const formId = `${id}-form`;

    useEffect(() => setConfirmedUnusual(false), [preview]);

    function submit(event) {
        event.preventDefault();
        if (phase === 'reason') editor.beginReading();
        else if (phase === 'reading') editor.review();
        else editor.confirm({ confirmUnusual: confirmedUnusual });
    }

    return <DialogFrame show={Boolean(phase)} onClose={editor.cancel} busy={editor.saving} title="تعديل القراءة السابقة" description={subscriptionName} icon="pencil" maxWidth="2xl" footer={<>
        <SecondaryButton onClick={phase === 'reason' ? editor.cancel : editor.back} disabled={editor.saving}>{phase === 'reason' ? 'إلغاء' : 'رجوع'}</SecondaryButton>
        <PrimaryButton type="submit" form={formId} disabled={editor.saving || (phase === 'reason' && !editor.canContinue) || (phase === 'review' && (!preview || (unusual && !confirmedUnusual)))}>{editor.saving ? 'جارٍ المعالجة…' : phase === 'reason' ? 'متابعة' : phase === 'reading' ? 'مراجعة التعديل' : meterChange ? 'حفظ نقطة البداية' : 'حفظ التصحيح'}</PrimaryButton>
    </>}>
        <ol aria-label="خطوات تعديل القراءة" className="grid grid-cols-3 gap-2 text-sm sm:gap-5">
            {['سبب التعديل', 'القراءة', 'المراجعة'].map((label, index) => <li key={label} aria-current={index === stepIndex ? 'step' : undefined} className={`flex flex-col items-center gap-2 text-center sm:flex-row sm:text-start ${index === stepIndex ? 'font-semibold text-brand-600' : 'text-gray-500'}`}><span className={index === stepIndex ? 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-500/10' : 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-50'}>{index + 1}</span>{label}</li>)}
        </ol>
        <form id={formId} onSubmit={submit} noValidate className="space-y-5">
            {phase === 'reason' && <>
                <fieldset className="space-y-3">
                    <legend className="mb-3 text-lg font-bold text-gray-900">لماذا تريد تعديل القراءة؟</legend>
                    {PURPOSES.map((option) => <label key={option.value} className={`flex cursor-pointer items-start gap-3 rounded-control border p-4 transition ${editor.adjustmentType === option.value ? 'border-brand-500 bg-brand-500/5' : 'border-gray-200 hover:border-gray-400'}`}>
                        <input type="radio" name={`${id}-purpose`} value={option.value} checked={editor.adjustmentType === option.value} onChange={() => editor.chooseType(option.value)} className="mt-2 h-5 w-5 shrink-0 border-gray-300 text-brand-600 focus:ring-brand-500" />
                        <span><b className="block text-lg text-gray-900">{option.label}</b><span className="mt-1 block text-base text-gray-600">{editor.isInitial ? 'تعديل قراءة بداية الاشتراك التي تبدأ منها القراءة الحالية.' : option.hint}</span></span>
                    </label>)}
                </fieldset>
                {meterChange && <div>
                    <label htmlFor={`${id}-reason`} className="mb-2 block text-base font-semibold text-gray-900">سبب تغيير العداد <span className="text-red-600">*</span></label>
                    <textarea id={`${id}-reason`} value={editor.reason} onChange={(event) => editor.setReason(event.target.value)} maxLength={1000} required rows={3} className="block w-full rounded-control border-gray-200 text-base leading-8" placeholder="سرقة، تلف، أو استبدال العداد…" />
                </div>}
            </>}
            {phase === 'reading' && <>
                <p className="text-base font-semibold text-brand-600">{purpose?.label}</p>
                <div className="flex items-baseline justify-between gap-3 border-b border-gray-100 pb-4"><span>القراءة المسجلة</span><bdi dir="ltr" className="font-display text-2xl font-semibold text-gray-900">{formatMoney(editor.savedValue)} <span className="text-base font-normal">كيلو</span></bdi></div>
                <div>
                    <label htmlFor={`${id}-reading`} className="mb-3 block text-lg font-bold text-gray-900">{meterChange ? 'قراءة بداية العداد الجديد' : 'القراءة الصحيحة'}</label>
                    <div className="flex items-center gap-3" dir="ltr">
                        <SecondaryButton aria-label="إنقاص القراءة السابقة" onClick={() => editor.step(-1)} disabled={editor.saving || Number(editor.value) <= Number(meterChange ? 0 : editor.floor)} className="h-14 w-14 shrink-0 !px-0 !text-2xl">−</SecondaryButton>
                        <TextInput {...editor.inputProps} id={`${id}-reading`} aria-label="القراءة السابقة الجديدة" className="h-14 min-w-0 flex-1 py-3 !text-center font-display !text-2xl" />
                        <SecondaryButton aria-label="زيادة القراءة السابقة" onClick={() => editor.step(1)} disabled={editor.saving} className="h-14 w-14 shrink-0 !px-0 !text-2xl">+</SecondaryButton>
                    </div>
                    {!meterChange && !editor.isInitial && <p className="mt-3 text-base text-gray-600">بداية الفترة: <bdi dir="ltr">{formatMoney(editor.floor)}</bdi> كيلو.</p>}
                </div>
            </>}
            {phase === 'review' && preview && <>
                <div className="grid grid-cols-2 divide-x divide-x-reverse divide-gray-200 rounded-control bg-gray-50 py-5 text-center">
                    <div className="min-w-0 px-3"><p className="text-base text-gray-600">قبل التعديل</p><bdi dir="ltr" className="mt-2 block break-words text-center font-display text-2xl font-semibold text-gray-500">{formatMoney(preview.before)}</bdi></div>
                    <div className="min-w-0 px-3"><p className="text-base text-gray-600">بعد التعديل</p><bdi dir="ltr" className="mt-2 block break-words text-center font-display text-2xl font-bold text-gray-900">{formatMoney(preview.after)}</bdi></div>
                </div>
                <div><b className="text-gray-900">{purpose?.label}</b>{meterChange && <p className="mt-1 whitespace-pre-wrap text-base">{editor.reason}</p>}</div>
                {editor.isInitial ? <p className="rounded-control bg-emerald-500/10 p-4 font-semibold text-emerald-800 dark:text-emerald-300">هذه قراءة بداية الاشتراك، ولا توجد فاتورة سابقة لتعديلها.</p> : meterChange ? <p className="rounded-control bg-emerald-500/10 p-4 font-semibold text-emerald-800 dark:text-emerald-300">الفاتورة السابقة وحركاتها محفوظة كما هي.</p> : week && <dl className="space-y-2 border-y border-gray-100 py-4">
                    <div className="flex flex-wrap items-center justify-between gap-2"><dt>الاستهلاك المصحّح</dt><dd className="font-semibold text-gray-900"><bdi dir="ltr">{formatMoney(week.after.consumption)}</bdi> كيلو</dd></div>
                    <div className="flex flex-wrap items-center justify-between gap-2"><dt>قيمة الفاتورة</dt><dd><bdi dir="ltr" className="me-2 text-gray-500 line-through">{formatMoney(week.before.amountDue)}</bdi><bdi dir="ltr" className="font-display text-xl font-bold text-gray-900">{formatMoney(week.after.amountDue)}</bdi> ₪</dd></div>
                </dl>}
                <p className="font-semibold text-gray-900">أدخل القراءة الحالية لاحقًا من خانتها في الجدول.</p>
                {Number(preview.removedCurrentAmount ?? 0) !== 0 && <p className="text-base">مبلغ الحالية الذي سيُلغى لحين إعادة إدخالها واعتمادها: <bdi dir="ltr" className="font-semibold text-gray-900">{formatMoney(preview.removedCurrentAmount)}</bdi> ₪.</p>}
                {(week?.isApproved && !meterChange || preview.clearsCurrentReading) && <details className="text-base">
                    <summary className="cursor-pointer font-semibold text-gray-700">تفاصيل الأثر المالي</summary>
                    <ul className="mt-3 list-inside list-disc space-y-2">
                        {!meterChange && week?.isApproved && <li>يُلغى المبلغ القديم بقيد عكسي محفوظ، ثم تُعتمد الفاتورة المصحّحة.</li>}
                        {preview.clearsCurrentReading && <li>تُفرّغ خانة الحالية، ويُحفظ الرقم القديم في سجل التعديلات.</li>}
                        {Number(preview.removedCurrentAmount ?? 0) !== 0 && <li>يُلغى مبلغ الحالية السابق ({formatMoney(preview.removedCurrentAmount)} ₪) حتى إدخالها واعتمادها مجددًا.</li>}
                    </ul>
                </details>}
                {unusual && <label className="flex items-start gap-3 rounded-control border border-amber-300 p-4 text-base text-amber-800 dark:text-amber-300"><input type="checkbox" checked={confirmedUnusual} onChange={(event) => setConfirmedUnusual(event.target.checked)} className="mt-2 h-5 w-5 shrink-0 rounded border-gray-300 text-amber-600" />أؤكد صحة الاستهلاك رغم أنه أعلى من المعتاد.</label>}
            </>}
            {editor.error && <div role="alert"><InputError message={editor.error} /></div>}
        </form>
    </DialogFrame>;
}
