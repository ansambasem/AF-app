import BankReceivedDate from '@/Components/BankReceivedDate';
import { useEffect, useRef, useState } from 'react';
import Icon from '@/Components/Icon';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusPill from '@/Components/DataTable/StatusPill';
import InputError from '@/Components/InputError';
import LatePaymentDetails from '@/Components/LatePaymentDetails';
import SearchableSelect from '@/Components/SearchableSelect';
import { formatMoney } from '@/lib/format';
import './financial-boxes.css';
import DateInput from '@/Components/DateInput';

const outcomes = { matched: ['مطابقة', 'green'], missing: ['غير موجودة', 'red'], short: ['نقص في المبلغ', 'amber'], over: ['زيادة في المبلغ', 'amber'] };

function Amount({ value, currency }) {
    return <span className="whitespace-nowrap"><bdi dir="ltr" className="font-display font-bold">{formatMoney(value)}</bdi> <span className="text-sm font-normal text-gray-500">{currency}</span></span>;
}

function Result({ entry, branchName, onEdit, disabled }) {
    const { ledger, audit } = entry;
    const [label, tone] = outcomes[audit?.outcome] ?? ['لم تُراجع', 'gray'];
    const fields = [
        ['المشترك', <span className="font-semibold text-gray-900">{entry.transaction.subscription.subscription_name || entry.transaction.subscription.full_name}</span>],
        ['تاريخ الحركة', ledger.paidOn ? <LatePaymentDetails paidOn={ledger.paidOn} recordedAt={ledger.date} reason={ledger.lateReason} /> : <bdi dir="ltr" className="font-display text-sm">{ledger.date}</bdi>],
        ['تاريخ وصول البنك', ledger.bankReceivedOn ? <BankReceivedDate value={ledger.bankReceivedOn} /> : '—'],
        ['رقم السند', <bdi dir="ltr" className="font-display font-semibold">{ledger.voucherNumber || '—'}</bdi>],
        ['الرقم المرجعي', <span className="break-all"><bdi dir="ltr" className="font-display font-semibold">{ledger.referenceNumber || '—'}</bdi><span className="mt-1 block text-sm text-gray-500">{ledger.bankName}</span></span>],
        ['مبلغ الدفعة', <Amount value={ledger.paidAmount} currency={ledger.currencyLabel} />],
        ['وصل البنك', audit ? <Amount value={audit.bank_amount} currency={ledger.currencyLabel} /> : <span className="text-sm text-gray-500">لم يُسجّل بعد</span>],
        ['الفرق', audit ? <span className={Number(audit.difference) > 0 ? 'text-red-700 dark:text-red-300' : Number(audit.difference) < 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-700'}><Amount value={audit.difference} currency="شيكل" /></span> : '—'],
        ['الصندوق والمحصل', <span><span className="block font-semibold text-gray-900">صندوق {entry.box.number}</span><span className="mt-1 block text-sm text-gray-500">{entry.box.collector || '—'} · {branchName}</span></span>],
        ['حالة التدقيق', <div className="space-y-2"><StatusPill label={label} tone={tone} /><p className="text-sm text-gray-500">{entry.finalized ? 'معتمدة نهائيًا' : audit ? 'مسودة محفوظة' : 'بانتظار المراجعة'}</p>{audit?.reason && <p className="break-words text-sm leading-6 text-gray-600">{audit.reason}</p>}</div>],
    ];
    const action = entry.canReview ? <button type="button" disabled={disabled} onClick={() => onEdit(entry)} className="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-control border border-brand-500/25 bg-brand-500/5 px-3 py-2.5 text-sm font-semibold text-brand-700 transition hover:bg-brand-500/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 disabled:cursor-wait disabled:opacity-50 dark:text-brand-300"><Icon name="pencil" className="h-4 w-4" />تعديل الحوالة</button> : <span className="inline-flex items-center gap-1 text-sm text-gray-500"><Icon name="lock" className="h-4 w-4" />{entry.finalized ? 'مقفلة' : 'عرض فقط'}</span>;

    return <>
        <tr className="hidden align-top md:table-row">{fields.map(([name, content]) => <td key={name} className="!whitespace-normal !px-4 !py-5">{content}</td>)}<td className="!px-4 !py-5">{action}</td></tr>
        <tr className="md:hidden"><td colSpan="10" className="!whitespace-normal !p-4"><dl className="grid grid-cols-2 gap-x-4 gap-y-4">{fields.map(([name, content]) => <div key={name} className={['المشترك', 'حالة التدقيق'].includes(name) ? 'col-span-2' : 'min-w-0'}><dt className="mb-1 text-sm text-gray-500">{name}</dt><dd className="text-base">{content}</dd></div>)}</dl><div className="mt-5 border-t border-gray-100 pt-4">{action}</div></td></tr>
    </>;
}

/** Direct exception entry, independent of the box browser and the approval preview. */
export default function AuditTransferSearch({ data, branchName, busy = false, errors = {}, onSearch, onPage, onEdit }) {
    const [search, setSearch] = useState(data.search);
    const [state, setState] = useState(data.state);
    const [dates, setDates] = useState({ transfer_date_basis: data.dateBasis ?? 'recorded', transfer_from: data.from ?? '', transfer_to: data.to ?? '' });
    const [waiting, setWaiting] = useState(false);
    const searchTimer = useRef(null);
    useEffect(() => () => clearTimeout(searchTimer.current), []);
    const updating = waiting || busy;
    const stale = search.trim() !== data.search || state !== data.state || dates.transfer_date_basis !== (data.dateBasis ?? 'recorded') || dates.transfer_from !== (data.from ?? '') || dates.transfer_to !== (data.to ?? '');
    const attempted = Boolean(data.search || data.state || data.from);
    const results = data.results;

    function submitSearch(value = search, nextState = state, nextDates = dates) {
        clearTimeout(searchTimer.current);
        setWaiting(false);
        onSearch({ transfer_search: value.trim(), transfer_state: nextState, ...nextDates });
    }

    function changeDate(field, value) {
        const next = { ...dates, [field]: value };
        setDates(next);
        clearTimeout(searchTimer.current);
        if ((next.transfer_from && next.transfer_to && next.transfer_from <= next.transfer_to) || (!next.transfer_from && !next.transfer_to)) submitSearch(search, state, next);
    }

    function changeSearch(value, composing = false) {
        setSearch(value);
        clearTimeout(searchTimer.current);
        setWaiting(true);
        if (!composing) {
            searchTimer.current = setTimeout(() => submitSearch(value), 350);
        }
    }

    return <section aria-label="البحث عن حوالة" className="space-y-4">
        <div className="rounded-card border border-gray-100 bg-surface p-5 shadow-card sm:p-6">
            <div className="mb-5 flex items-start gap-3"><span className="rounded-control bg-brand-500/10 p-3 text-brand-600"><Icon name="search" className="h-6 w-6" /></span><div><h3 className="text-xl font-bold text-gray-900">ابحث عن الحركة، وعدّلها مباشرة</h3><p className="mt-1 text-base leading-7 text-gray-600">برقم السند أو المرجع الكامل، ضمن الفرع والفترة المحددين أعلاه.</p></div></div>
            <form onSubmit={(event) => { event.preventDefault(); submitSearch(); }} className="flex flex-wrap items-end gap-3">
                <div className="min-w-0 flex-1 basis-72"><label htmlFor="audit-transfer-search" className="mb-2 block text-base font-semibold text-gray-900">رقم السند أو الرقم المرجعي</label><input id="audit-transfer-search" type="search" maxLength="100" autoComplete="off" dir="auto" value={search} onChange={(event) => changeSearch(event.target.value, event.nativeEvent.isComposing)} onCompositionEnd={(event) => changeSearch(event.target.value)} aria-describedby="audit-transfer-search-hint" placeholder="مثال: 000125 أو BANK-2026-125" className="block w-full rounded-control border-gray-200 px-4 py-3 text-base" /><InputError message={errors.transfer_search} /></div>
                <div className="w-full sm:w-56"><label htmlFor="audit-transfer-state" className="mb-2 block text-base font-semibold text-gray-900">حالة التدقيق</label><select id="audit-transfer-state" value={state} onChange={(event) => { setState(event.target.value); submitSearch(search, event.target.value); }} className="block w-full rounded-control border-gray-200 py-3 text-base"><option value="">كل الحالات</option><option value="differences">الحوالات غير المطابقة</option><option value="missing">غير موجودة</option><option value="short">نقص في المبلغ</option><option value="over">زيادة في المبلغ</option><option value="draft">مسودات محفوظة</option><option value="unreviewed">لم تُراجع</option><option value="matched">مطابقة</option></select><InputError message={errors.transfer_state} /></div>
                {(search || state || attempted) && <SecondaryButton onClick={() => { const cleared = {transfer_date_basis:'recorded',transfer_from:'',transfer_to:''}; setSearch(''); setState(''); setDates(cleared); submitSearch('', '', cleared); }}>مسح البحث</SecondaryButton>}
            </form>
            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div><label htmlFor="audit-date-basis" className="mb-1.5 block text-sm font-semibold text-gray-700">تصفية حسب</label><SearchableSelect id="audit-date-basis" clearable={false} searchable={false} value={dates.transfer_date_basis} options={[{value:'recorded',label:'تاريخ التسجيل بالنظام'},{value:'bank',label:'تاريخ وصول الحوالة إلى البنك'},{value:'paid',label:'تاريخ الدفع السابق (أرشيف)'}]} onChange={value => changeDate('transfer_date_basis', value)} /></div>
                <div><label htmlFor="audit-date-from" className="mb-1.5 block text-sm font-semibold text-gray-700">من</label><DateInput id="audit-date-from" max={dates.transfer_to || undefined} value={dates.transfer_from} onChange={(value) => changeDate('transfer_from', value)} /><InputError message={errors.transfer_from} /></div>
                <div><label htmlFor="audit-date-to" className="mb-1.5 block text-sm font-semibold text-gray-700">إلى</label><DateInput id="audit-date-to" min={dates.transfer_from || undefined} value={dates.transfer_to} onChange={(value) => changeDate('transfer_to', value)} /><InputError message={errors.transfer_to} /></div>
            </div>
            <p id="audit-transfer-search-hint" className="mt-3 text-sm text-gray-500">تظهر النتائج تلقائيًا بعد توقف الكتابة. التواريخ تُصفّي الحوالات داخل فترة الصناديق المختارة أعلاه؛ لا تغيّر أرصدة الفترة.</p>
        </div>
        <div className="rounded-card border border-gray-100 bg-surface shadow-card" aria-busy={updating}>
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-4"><h3 className="text-lg font-bold text-gray-900">نتائج البحث <span className="font-display text-gray-500">({results.total})</span></h3><p role="status" aria-live="polite" className="text-sm text-gray-500">{updating ? 'جارٍ تحديث النتائج…' : stale ? 'النتائج المعروضة تخص البحث السابق.' : 'الحوالات السارية في الصناديق المغلقة'}</p></div>
            {!results.data.length ? <div className="flex flex-col items-center gap-3 px-5 py-14 text-center"><Icon name="search" className="h-9 w-9 text-gray-400" /><h4 className="text-lg font-semibold text-gray-900">{attempted ? 'لا توجد حوالة مطابقة للبحث' : 'ابدأ برقم الحركة التي تريد مراجعتها'}</h4><p className="max-w-lg text-base leading-7 text-gray-500">{attempted ? 'تحقق من الرقم والفرع والفترة، أو غيّر حالة التدقيق.' : 'يمكنك أيضًا اختيار حالة التدقيق لعرض الحوالات المسجلة بهذه الحالة.'}</p></div> : <>
                <div className={`overflow-x-auto ${updating || stale ? 'opacity-60' : ''}`}><table className="data-table w-full md:min-w-[1250px]"><thead className="hidden md:table-header-group"><tr>{['المشترك', 'تاريخ الحركة', 'تاريخ وصول البنك', 'رقم السند', 'الرقم المرجعي', 'مبلغ الدفعة', 'وصل البنك', 'الفرق (شيكل)', 'الصندوق والمحصل', 'حالة التدقيق', 'الإجراء'].map((label) => <th key={label} className="!px-4 !py-4 !text-sm">{label}</th>)}</tr></thead><tbody>{results.data.map((entry) => <Result key={entry.id} entry={entry} branchName={branchName} disabled={updating || stale} onEdit={onEdit} />)}</tbody></table></div>
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-5 py-4"><p className="text-sm text-gray-500">المعروض {results.from}–{results.to} من {results.total} حوالة</p><div className="flex gap-2"><SecondaryButton disabled={updating || stale || !results.prev_page_url} onClick={() => onPage(results.prev_page_url)}>السابق</SecondaryButton><SecondaryButton disabled={updating || stale || !results.next_page_url} onClick={() => onPage(results.next_page_url)}>التالي</SecondaryButton></div></div>
            </>}
        </div>
        <p className="text-sm leading-7 text-gray-500">الفرق بالشيكل وفق سعر صرف الدفعة: الموجب يُحمّل على المشترك، والسالب يُضاف لصالحه عند الاعتماد. الحفظ وحده لا يعتمد الفترة.</p>
    </section>;
}
