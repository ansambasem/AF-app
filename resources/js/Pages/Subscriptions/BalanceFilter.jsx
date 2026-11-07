import { useEffect, useId, useRef, useState } from 'react';
import Icon from '@/Components/Icon';
import SearchableSelect from '@/Components/SearchableSelect';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { normalizeDecimalInput } from '@/lib/format';
import { BALANCE_FILTER_KEYS, BALANCE_OPERATORS, BALANCE_SIDES, balanceFilterLabel } from '@/lib/subscriptionBalance';

export default function BalanceFilter({ values, onChange, errors = {} }) {
    const id = useId();
    const root = useRef(null);
    const trigger = useRef(null);
    const [open, setOpen] = useState(false);
    const [side, setSide] = useState('debit');
    const [operator, setOperator] = useState('gt');
    const [amount, setAmount] = useState('');
    const [to, setTo] = useState('');
    const label = balanceFilterLabel(values);
    const validAmount = /^\d+(\.\d{1,2})?$/.test(amount) && Number(amount) <= 9999999999;
    const valid = validAmount && (operator !== 'between' || (/^\d+(\.\d{1,2})?$/.test(to) && Number(to) >= Number(amount) && Number(to) <= 9999999999));

    useEffect(() => {
        if (!open) { return; }
        function dismiss(event) {
            if (event.type === 'keydown' && event.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            } else if (event.type === 'pointerdown' && !root.current?.contains(event.target)) {
                setOpen(false);
            }
        }
        document.addEventListener('pointerdown', dismiss);
        document.addEventListener('keydown', dismiss);
        return () => {
            document.removeEventListener('pointerdown', dismiss);
            document.removeEventListener('keydown', dismiss);
        };
    }, [open]);

    function toggle() {
        if (!open) {
            setSide(values.balance_side || 'debit');
            setOperator(values.balance_operator || 'gt');
            setAmount(String(values.balance_amount ?? ''));
            setTo(String(values.balance_to ?? ''));
        }
        setOpen(!open);
    }

    function apply(event) {
        event.preventDefault();
        if (!valid) { return; }
        onChange({ balance_side: side, balance_operator: operator, balance_amount: amount, balance_to: operator === 'between' ? to : '' });
        setOpen(false);
        trigger.current?.focus();
    }

    return (
        <div ref={root} className="relative w-full sm:w-auto">
            <button ref={trigger} type="button" onClick={toggle} aria-expanded={open} aria-controls={`${id}-panel`} className={`flex min-h-[42px] w-full items-center justify-between gap-2 rounded-control border bg-surface px-3.5 py-2 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${label ? 'border-brand-500/30 text-brand-600' : 'border-gray-200 text-gray-700 hover:border-gray-300'}`}>
                <Icon name="wallet" className="h-4 w-4 shrink-0" />
                {label || 'الرصيد'}
                <Icon name="chevron-down" className={`h-4 w-4 shrink-0 transition ${open ? 'rotate-180' : ''}`} />
            </button>
            {open && (
                <form id={`${id}-panel`} onSubmit={apply} aria-label="تصفية حسب الرصيد" className="fixed inset-x-4 bottom-4 z-50 max-h-[calc(100dvh-2rem)] space-y-4 overflow-y-auto rounded-2xl border border-gray-100 bg-surface p-5 shadow-lift sm:absolute sm:inset-x-auto sm:start-0 sm:bottom-auto sm:z-30 sm:mt-2 sm:max-h-none sm:w-96 sm:overflow-visible">
                    <div><h3 className="font-bold text-gray-900">تصفية حسب الرصيد</h3><p className="mt-1 text-xs text-gray-500">اختر جهة الرصيد وأدخل المبلغ بالشيكل. الحسابات المسدّدة خارج هذا الفلتر.</p></div>
                    <div className="grid grid-cols-2 gap-3">
                        <div><label htmlFor={`${id}-side`} className="mb-1.5 block text-xs font-semibold text-gray-700">جهة الرصيد</label><SearchableSelect id={`${id}-side`} value={side} onChange={setSide} options={BALANCE_SIDES} searchable={false} clearable={false} className="[&>div]:min-w-0" /></div>
                        <div><label htmlFor={`${id}-operator`} className="mb-1.5 block text-xs font-semibold text-gray-700">الشرط</label><SearchableSelect id={`${id}-operator`} value={operator} onChange={setOperator} options={BALANCE_OPERATORS} searchable={false} clearable={false} className="[&>div]:min-w-0" /></div>
                    </div>
                    <div className={`grid gap-3 ${operator === 'between' ? 'grid-cols-2' : ''}`}>
                        <div><label htmlFor={`${id}-amount`} className="mb-1.5 block text-xs font-semibold text-gray-700">{operator === 'between' ? 'من مبلغ (شيكل)' : 'المبلغ (شيكل)'}</label><input id={`${id}-amount`} type="text" inputMode="decimal" dir="ltr" autoFocus value={amount} onChange={(event) => setAmount(normalizeDecimalInput(event.target.value))} className="block w-full" /></div>
                        {operator === 'between' && <div><label htmlFor={`${id}-to`} className="mb-1.5 block text-xs font-semibold text-gray-700">إلى مبلغ (شيكل)</label><input id={`${id}-to`} type="text" inputMode="decimal" dir="ltr" value={to} onChange={(event) => setTo(normalizeDecimalInput(event.target.value))} className="block w-full" /></div>}
                    </div>
                    {operator === 'between' && to !== '' && Number(to) < Number(amount) && <p role="alert" className="text-xs text-red-600">المبلغ الأعلى يجب أن يساوي المبلغ الأول أو يزيد عليه.</p>}
                    {BALANCE_FILTER_KEYS.map((key) => errors[`filter.${key}`] && <p key={key} role="alert" className="text-xs text-red-600">{errors[`filter.${key}`]}</p>)}
                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-4">
                        {label && <SecondaryButton type="button" onClick={() => { onChange(Object.fromEntries(BALANCE_FILTER_KEYS.map((key) => [key, '']))); setOpen(false); }}>مسح</SecondaryButton>}
                        <PrimaryButton type="submit" disabled={!valid}><Icon name="filter" className="h-4 w-4" />تطبيق الفلتر</PrimaryButton>
                    </div>
                </form>
            )}
        </div>
    );
}
