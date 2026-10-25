import { useEffect, useId, useRef, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import { DialogHeader } from '@/Components/DialogFrame';
import { balanceInText, balanceTone, balanceWord } from '@/lib/balance';

const SEARCH_DELAY = 200;

const BALANCE_TONES = {
    owes: 'text-brand-600',
    credit: 'text-emerald-700 dark:text-emerald-400',
    settled: 'text-gray-500',
};

/**
 * The dashboard's "تسجيل دفعة": find the subscriber here — by name, account
 * or meter box, as on the payments page — and choosing one opens their
 * statement with the payment form, without leaving the dashboard. Arrow
 * keys move through the results and Enter picks one.
 */
export default function QuickPaymentSearch({ show, onClose, onPick }) {
    const titleId = useId();
    const http = useHttp();
    const httpRef = useRef(http);
    httpRef.current = http;
    const [query, setQuery] = useState('');
    const [results, setResults] = useState(null);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [active, setActive] = useState(0);
    const term = query.trim();

    useEffect(() => {
        if (!show) {
            setQuery('');
            setResults(null);
            setActive(0);
        }
    }, [show]);

    useEffect(() => {
        if (term.length < 2) {
            setResults(null);
            setLoading(false);
            setFailed(false);

            return undefined;
        }

        // An answer to an older search is ignored once the search has changed.
        let current = true;
        setLoading(true);
        const timer = setTimeout(() => {
            httpRef.current
                .get(`/payments/search?${new URLSearchParams({ search: term })}`)
                .then((result) => {
                    if (current) {
                        setResults(result.subscriptions);
                        setHasMore(result.hasMore);
                        setFailed(false);
                        setActive(0);
                    }
                })
                .catch(() => current && setFailed(true))
                .finally(() => current && setLoading(false));
        }, SEARCH_DELAY);

        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [term]);

    function onKeyDown(event) {
        if (!results?.length) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => (index + (event.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            onPick(results[active]);
        }
    }

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div role="dialog" aria-modal="true" aria-labelledby={titleId} dir="rtl" className="flex max-h-[85dvh] min-h-0 flex-col">
                <DialogHeader
                    titleId={titleId}
                    title="تسجيل دفعة"
                    description="ابحث عن المشترك، ثم سجّل دفعته من كشف حسابه دون مغادرة الصفحة."
                    descriptionId={`${titleId}-description`}
                    icon="banknotes"
                    onClose={onClose}
                />
                <div className="shrink-0 px-5 pt-5 sm:px-7">
                    <div className="relative">
                        <Icon name="search" className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-5 w-5 text-gray-400" />
                        <input
                            type="search"
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder="اسم المشترك أو رقم الحساب أو الطبلون"
                            aria-label="ابحث عن المشترك"
                            aria-controls={`${titleId}-results`}
                            aria-activedescendant={results?.length ? `${titleId}-result-${active}` : undefined}
                            className="block w-full py-3 pe-4 ps-11 text-base"
                        />
                        {loading && (
                            <span className="absolute inset-y-0 end-3.5 my-auto h-5 w-5 animate-spin rounded-full border-2 border-gray-200 border-t-brand-500" aria-hidden="true" />
                        )}
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto px-3 py-4 sm:px-5" aria-live="polite">
                    {term.length < 2 ? (
                        <p className="px-4 py-8 text-center text-sm text-gray-500">اكتب حرفين على الأقل من الاسم أو الرقم.</p>
                    ) : failed ? (
                        <p className="px-4 py-8 text-center text-sm text-brand-600">تعذّر البحث. تحقّق من الاتصال وحاول مجددًا.</p>
                    ) : results === null ? (
                        <ul className="space-y-2 px-2" aria-hidden="true">
                            {[0, 1, 2].map((row) => (
                                <li key={row} className="h-16 animate-pulse rounded-row bg-gray-100" />
                            ))}
                        </ul>
                    ) : results.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-gray-500">لا يوجد مشترك بهذا الاسم أو الرقم.</p>
                    ) : (
                        <ul id={`${titleId}-results`} role="listbox" aria-label="المشتركون المطابقون" className="space-y-1.5">
                            {results.map((subscriber, index) => {
                                const tone = balanceTone(subscriber.balance);

                                return (
                                    <li key={subscriber.id} id={`${titleId}-result-${index}`} role="option" aria-selected={index === active}>
                                        <button
                                            type="button"
                                            onClick={() => onPick(subscriber)}
                                            onPointerEnter={() => setActive(index)}
                                            className={`flex w-full items-center gap-3 rounded-row border px-4 py-3 text-start transition active:scale-[0.99] focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${
                                                index === active ? 'border-brand-500/30 bg-brand-500/[0.06]' : 'border-transparent hover:bg-gray-50'
                                            }`}
                                        >
                                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-graphite-gradient text-white">
                                                <Icon name="user" className="h-5 w-5" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <b className="block truncate font-semibold text-gray-900">{subscriber.fullName}</b>
                                                <span className="block truncate text-sm text-gray-500">
                                                    <span dir="ltr">{subscriber.accountNumber}</span>
                                                    {subscriber.meterBoxNumber && ` · طبلون ${subscriber.meterBoxNumber}`}
                                                    {subscriber.branchName && ` · ${subscriber.branchName}`}
                                                </span>
                                            </span>
                                            <span className="shrink-0 text-end">
                                                <b className={`block font-display text-sm ${BALANCE_TONES[tone]}`}>{balanceInText(Math.abs(Number(subscriber.balance)))}</b>
                                                <span className="text-xs text-gray-500">{balanceWord(subscriber.balance)}</span>
                                            </span>
                                            <Icon name="chevron-left" className="h-4 w-4 shrink-0 text-gray-400" />
                                        </button>
                                    </li>
                                );
                            })}
                            {hasMore && <li className="px-4 pt-2 text-center text-xs text-gray-500">نتائج أكثر — اكتب المزيد من الاسم أو الرقم لتضييقها.</li>}
                        </ul>
                    )}
                </div>
            </div>
        </Modal>
    );
}
