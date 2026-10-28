import { useEffect, useId, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';

/**
 * Each transfer bank or e-wallet's logo, color and kind; one not listed here gets a plain tile.
 * Every logo is a square image with its own padding, so all marks share one size and fit.
 */
const BANKS = {
    'بنك فلسطين': { logo: '/images/banks/bank-of-palestine.webp', color: '#b8007a', kind: 'تحويل بنكي' },
    'جوال باي': { logo: '/images/banks/jawwal-pay.webp', color: '#7cb342', kind: 'محفظة' },
    'محفظة بالباي': { logo: '/images/banks/palpay.webp', color: '#9b30e0', kind: 'محفظة' },
    'البنك الإسلامي الفلسطيني': { logo: '/images/banks/palestine-islamic-bank.webp', color: '#173d69', kind: 'تحويل بنكي' },
    'البنك الإسلامي العربي': { logo: '/images/banks/arab-islamic-bank.webp', color: '#7b2d8e', kind: 'تحويل بنكي' },
    'بنك القدس': { logo: '/images/banks/quds-bank.webp', color: '#74bd44', kind: 'تحويل بنكي' },
};

function useBankLook(bank) {
    const managed = usePage().props.paymentOptions?.banks?.find((option) => option.name === bank);
    return managed?.logo ? { ...BANKS[bank], logo: managed.logo } : BANKS[bank];
}

export function BankMark({ bank, className = 'h-10 w-10 rounded-xl' }) {
    const look = useBankLook(bank);

    if (!look) {
        return (
            <span className={`flex shrink-0 items-center justify-center bg-gray-100 text-gray-600 ${className}`}>
                <Icon name="bank" className="h-1/2 w-1/2" />
            </span>
        );
    }

    return (
        <span className={`shrink-0 overflow-hidden border border-gray-100 bg-white shadow-[0_4px_10px_-6px_rgb(0_0_0/0.4)] ${className}`}>
            <img src={look.logo} alt="" className="h-full w-full object-contain" />
        </span>
    );
}

/** A bank or e-wallet as a radio row: brand mark, name, kind, and the choice indicator. */
export function BankRow({ bank, checked, name, onChange, required = false }) {
    const look = useBankLook(bank);

    return (
        <label
            className={`relative flex min-h-[58px] cursor-pointer items-center gap-3 rounded-[14px] border-[1.5px] px-3 py-2 transition focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-gray-900 ${
                checked ? 'border-brand-500 bg-brand-500/[0.06] shadow-[0_0_0_3px_rgb(var(--brand-500)/0.12)]' : 'border-gray-100 bg-surface hover:border-gray-300'
            }`}
        >
            <input type="radio" name={name} value={bank} required={required} checked={checked} onChange={() => onChange(bank)} className="sr-only" />
            <BankMark bank={bank} />
            <span className="min-w-0">
                <b className={`block truncate text-[14.5px] ${checked ? 'text-brand-600' : 'text-gray-900'}`}>{bank}</b>
                <small className="block text-xs font-medium text-gray-500">{look?.kind ?? 'تحويل'}</small>
            </span>
            <span
                className={`ms-auto flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full border-[1.5px] transition ${
                    checked ? 'border-brand-500 bg-brand-500' : 'border-gray-300'
                }`}
                aria-hidden="true"
            >
                {checked && <span className="h-2 w-2 rounded-full bg-surface" />}
            </span>
        </label>
    );
}

export function BankDropdown({ banks, id, name, onChange, value }) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const listboxId = useId();
    const selectedLook = useBankLook(value);

    useEffect(() => {
        if (!open) {
            return;
        }

        function closeOutside(event) {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setOpen(false);
            }
        }

        function closeOnEscape(event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', closeOutside);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOutside);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    function select(bank) {
        onChange(bank);
        setOpen(false);
    }

    return (
        <div ref={containerRef} className="relative">
            <input type="hidden" name={name} value={value} />
            <button
                type="button"
                id={id}
                aria-controls={listboxId}
                aria-expanded={open}
                aria-haspopup="listbox"
                onClick={() => setOpen((current) => !current)}
                className={`flex min-h-[58px] w-full items-center gap-3 rounded-[14px] border-[1.5px] bg-surface px-3 py-2 text-start transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/15 ${
                    open ? 'border-brand-500 ring-4 ring-brand-500/15' : 'border-gray-200 hover:border-brand-500/45'
                }`}
            >
                {value ? (
                    <>
                        <BankMark bank={value} />
                        <span className="min-w-0">
                            <b className="block truncate text-[14.5px] text-gray-900">{value}</b>
                            <small className="block text-xs font-medium text-gray-500">{selectedLook?.kind ?? 'تحويل'}</small>
                        </span>
                    </>
                ) : (
                    <span className="text-sm text-gray-400">اختر البنك أو المحفظة المحوّل منها</span>
                )}
                <Icon name="chevron-down" className={`ms-auto h-4 w-4 shrink-0 text-gray-400 transition ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <ul
                    id={listboxId}
                    role="listbox"
                    aria-label="البنك المرسل"
                    className="animate-modal-panel absolute inset-x-0 z-30 mt-2 max-h-80 overflow-y-auto rounded-2xl border border-gray-100 bg-surface p-1.5 shadow-lift"
                >
                    <li role="none">
                        <button
                            type="button"
                            role="option"
                            aria-selected={!value}
                            onClick={() => select('')}
                            className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition hover:bg-gray-50 ${
                                !value ? 'bg-gray-50 font-semibold text-gray-900' : 'text-gray-600'
                            }`}
                        >
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50 text-gray-400">
                                <Icon name="close" className="h-4 w-4" />
                            </span>
                            <span className="text-sm">غير محدد</span>
                            {!value && <Icon name="check" className="ms-auto h-4 w-4 text-brand-600" strokeWidth={2} />}
                        </button>
                    </li>
                    {banks.map((bank) => {
                        const isSelected = value === bank;
                        const look = BANKS[bank];

                        return (
                            <li key={bank} role="none">
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    onClick={() => select(bank)}
                                    className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition hover:bg-gray-50 ${
                                        isSelected ? 'bg-gray-50' : ''
                                    }`}
                                    style={isSelected && look?.color ? { backgroundColor: `${look.color}0d` } : undefined}
                                >
                                    <BankMark bank={bank} />
                                    <span className="min-w-0">
                                        <b className="block text-[14.5px] text-gray-900">{bank}</b>
                                        <small className="block text-xs font-medium text-gray-500">{look?.kind ?? 'تحويل'}</small>
                                    </span>
                                    {isSelected && <Icon name="check" className="ms-auto h-4 w-4 shrink-0 text-brand-600" strokeWidth={2} />}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
