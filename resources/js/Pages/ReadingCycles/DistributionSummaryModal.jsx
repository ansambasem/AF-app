import { useMemo } from 'react';
import { boardCounters, branchDistributionSummary } from '@/lib/readingBoard';
import { formatNumber } from '@/lib/format';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';
import DistributionDialog from './DistributionDialog';
import DistributionAreaBreakdown from './DistributionAreaBreakdown';

export default function DistributionSummaryModal({ cycle, boards, readers, paperSheets, abilities, busy, errors, result, onClose, onApprove, onPaper, onPreparePaper, onRemaining }) {
    const columns = useMemo(() => branchDistributionSummary(boards, readers), [boards, readers]);
    const counters = boardCounters(boards);
    const paper = columns.find((column) => column.key === 'paper');
    const available = columns.find((column) => column.key === 'available');
    const readerColumns = columns.filter((column) => column.key.startsWith('reader:'));
    const printed = paperSheets.filter((sheet) => sheet.printCount > 0).length;

    return <DistributionDialog title="ملخّص توزيع الفرع" subtitle={`${cycle.branchName} · قراءة ${cycle.readingDate} · يشمل جميع طبلونات الفرع دون تأثير البحث والتصفية`} busy={busy} onClose={onClose}
        footer={<><SecondaryButton disabled={busy} onClick={onClose}>العودة للتوزيع</SecondaryButton>{abilities.approve && counters.draft > 0 && <PrimaryButton disabled={busy} onClick={onApprove}>اعتماد كل الجاهز ({formatNumber(counters.draft)})</PrimaryButton>}</>}>
        {result && <p role="status" className={`rounded-control p-3 text-sm ${result.conflicts.length ? 'bg-amber-500/10 text-amber-700' : 'bg-emerald-500/10 text-emerald-700'}`}>
            {result.action === 'approve' ? 'اعتُمد' : 'حُدّث توزيع'} {formatNumber(result.moved)} طبلون.
            {result.conflicts.length > 0 && <> توجد طبلونات تعذّر تحديثها. <button type="button" onClick={onClose} className="font-bold underline">عرض أسباب التعارض</button></>}
        </p>}
        <InputError message={errors.approve} />
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
                ['إجمالي الطبلونات', boards.length], ['على القرّاء', counters.mobile], ['للكشوف الورقية', counters.paper], ['غير موزع', counters.available],
            ].map(([label, count]) => <div key={label} className="rounded-control border border-gray-100 bg-gray-50 p-3"><p className="text-xs text-gray-500">{label}</p><p className="mt-2 font-display text-2xl font-bold text-gray-900">{formatNumber(count)}</p></div>)}
        </div>
        <div>
            <div className="flex h-2.5 overflow-hidden rounded-full bg-gray-100" aria-hidden="true">{[[counters.mobile, 'bg-brand-600'], [counters.paper, 'bg-emerald-600'], [counters.available, 'bg-amber-500']].map(([count, color]) => <span key={color} className={color} style={{ width: `${boards.length ? count / boards.length * 100 : 0}%` }} />)}</div>
            <p className="mt-2 text-xs leading-6 text-gray-500">{formatNumber(counters.subscribers)} مشترك · {formatNumber(counters.approved)} طبلون معتمد · {formatNumber(counters.draft)} بانتظار الاعتماد</p>
        </div>
        <section aria-label="توزيع القرّاء" className="grid items-start gap-3 md:grid-cols-3">
            {readerColumns.map((reader) => <article key={reader.key} className="min-w-0 rounded-card border border-gray-100 p-4">
                <div className="flex items-center gap-3"><span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-500/10 font-bold text-brand-600">{reader.label.slice(0, 1)}</span><h3 className="font-bold text-gray-900">{reader.label}</h3></div>
                <p className="mt-3 text-sm text-gray-700"><b className="font-display text-xl text-brand-600">{formatNumber(reader.boxes)}</b> طبلون · {formatNumber(reader.subscribers)} مشترك</p>
                {reader.unavailable && <p className="mt-2 text-xs text-amber-700">تحتاج هذه المهام إلى مراجعة القارئ المكلّف.</p>}
                {reader.drafts > 0 && <p className="mt-2 text-xs text-amber-700">{formatNumber(reader.drafts)} بانتظار الاعتماد</p>}
                {reader.boxes ? <DistributionAreaBreakdown areas={reader.areas} /> : <p className="mt-3 text-xs text-gray-500">لا توجد طبلونات مخصصة.</p>}
            </article>)}
            {readerColumns.length === 0 && <p className="text-sm text-gray-500 md:col-span-3">لا يوجد قرّاء متاحون في الفرع.</p>}
        </section>
        <div className="grid items-start gap-4 sm:grid-cols-2">
            <section className="min-w-0 rounded-card border border-emerald-500/20 p-4" aria-label="ملخّص الكشوف الورقية">
                <h3 className="font-bold text-gray-900">الكشوف الورقية <span className="font-display">({formatNumber(paper.boxes)})</span></h3>
                <p className="mt-2 text-xs leading-6 text-gray-500">{formatNumber(paper.subscribers)} مشترك · {formatNumber(paperSheets.length)} كشف منشأ: {formatNumber(printed)} مطبوع، {formatNumber(paperSheets.length - printed)} لم يُطبع.</p>
                <DistributionAreaBreakdown areas={paper.areas} />
                {abilities.printSheets && paper.boxes > 0 && <SecondaryButton className="mt-3 w-full" disabled={busy} onClick={onPreparePaper}>تجهيز الطبلونات الورقية ({formatNumber(paper.boxes)})</SecondaryButton>}
                {(abilities.printSheets || paperSheets.length > 0) && <SecondaryButton className="mt-3 w-full" disabled={busy} onClick={onPaper}>مراجعة الكشوف والطباعة</SecondaryButton>}
            </section>
            <section className="min-w-0 rounded-card border border-amber-500/30 bg-amber-500/5 p-4" aria-label="الطبلونات المتبقية">
                <h3 className="font-bold text-gray-900">غير موزع <span className="font-display">({formatNumber(available.boxes)})</span></h3>
                <p className="mt-2 text-xs leading-6 text-gray-500">{available.boxes ? `${formatNumber(available.subscribers)} مشترك بانتظار تحديد قارئ أو كشف ورقي.` : 'تم توزيع جميع الطبلونات في الفرع.'}</p>
                <DistributionAreaBreakdown areas={available.areas} />
                {abilities.manage && available.boxes > 0 && <SecondaryButton className="mt-3 w-full" disabled={busy} onClick={onRemaining}>توزيع المتبقي ({formatNumber(available.boxes)})</SecondaryButton>}
            </section>
        </div>
        {counters.available > 0 && counters.draft > 0 && abilities.approve && <p className="text-xs leading-6 text-amber-700">الاعتماد يشمل الطبلونات الجاهزة فقط؛ تبقى الطبلونات غير الموزعة بحاجة إلى توزيع.</p>}
    </DistributionDialog>;
}
