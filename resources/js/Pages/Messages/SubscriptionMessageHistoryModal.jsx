import { useEffect, useId, useRef, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import SearchableSelect from '@/Components/SearchableSelect';
import SecondaryButton from '@/Components/SecondaryButton';
import Pagination from '@/Components/DataTable/Pagination';
import { ChannelLabel, KindPill, MessageStatusPill } from './MessageParts';

const DATE_TIME = new Intl.DateTimeFormat('ar-EG-u-nu-latn', { dateStyle: 'medium', timeStyle: 'short' });
const dateTime = (value) => value ? DATE_TIME.format(new Date(value)) : '—';

export default function SubscriptionMessageHistoryModal({ subscription, onClose }) {
    const titleId = useId();
    const [kind, setKind] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);
    const http = useHttp();
    const httpRef = useRef(http);
    httpRef.current = http;
    const requestKey = JSON.stringify([subscription.id, kind, status, page, attempt]);
    const current = result?.key === requestKey ? result : null;
    const messages = current?.data?.messages;

    useEffect(() => {
        let active = true;
        const client = httpRef.current;
        const query = new URLSearchParams({ page, ...(kind ? { kind } : {}), ...(status ? { status } : {}) });
        client.get(`/subscriptions/${subscription.id}/messages?${query}`)
            .then((data) => { if (active) setResult({ key: requestKey, data }); })
            .catch(() => { if (active) setResult({ key: requestKey, error: 'تعذّر تحميل سجل الرسائل. حاول مرة أخرى.' }); });
        return () => { active = false; client.cancel(); };
    }, [requestKey]);

    return (
        <Modal show onClose={onClose} maxWidth="5xl" centered>
            <section role="dialog" aria-modal="true" aria-labelledby={titleId} className="flex max-h-[85dvh] min-h-0 flex-col">
                <header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 px-5 py-5 sm:px-7">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600"><Icon name="messages" /></span>
                        <div className="min-w-0">
                            <h3 id={titleId} className="text-xl font-bold text-gray-900">سجل رسائل المشترك</h3>
                            <p className="mt-1 text-sm text-gray-600">{subscription.display_name ?? subscription.fullName} · الاشتراك <bdi dir="ltr">{subscription.account_number ?? subscription.accountNumber}</bdi></p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} aria-label="إغلاق سجل الرسائل" className="rounded-control p-2 text-gray-500 transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900"><Icon name="close" className="h-5 w-5" /></button>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto bg-gray-50 px-5 py-5 sm:px-7" aria-busy={!current}>
                    <div className="relative z-10 mb-5 grid gap-4 rounded-card border border-gray-100 bg-surface p-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor={`${titleId}-kind`} className="mb-2 block text-sm font-semibold text-gray-700">نوع الرسالة</label>
                            <SearchableSelect id={`${titleId}-kind`} value={kind} onChange={(value) => { setKind(value); setPage(1); }} options={result?.data?.kinds ?? []} placeholder="كل الأنواع" searchable={false} />
                        </div>
                        <div>
                            <label htmlFor={`${titleId}-status`} className="mb-2 block text-sm font-semibold text-gray-700">حالة الإرسال</label>
                            <SearchableSelect id={`${titleId}-status`} value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={result?.data?.statuses ?? []} placeholder="كل الحالات" searchable={false} />
                        </div>
                    </div>

                    {!current ? (
                        <div role="status" aria-label="جارٍ تحميل سجل الرسائل" className="animate-pulse space-y-3">{[1, 2, 3].map((row) => <div key={row} className="h-36 rounded-card border border-gray-100 bg-surface" />)}</div>
                    ) : current.error ? (
                        <div role="alert" className="rounded-card border border-gray-200 bg-surface p-7 text-center"><p className="mb-4 text-base text-gray-700">{current.error}</p><SecondaryButton onClick={() => setAttempt((value) => value + 1)}>إعادة المحاولة</SecondaryButton></div>
                    ) : messages.data.length === 0 ? (
                        <div className="rounded-card border border-gray-100 bg-surface px-5 py-12 text-center"><Icon name="messages" className="mx-auto mb-3 h-8 w-8 text-gray-400" /><p className="text-base text-gray-700">{kind || status ? 'لا توجد رسائل مطابقة للتصفية.' : 'لا توجد رسائل مسجلة لهذا الاشتراك بعد.'}</p></div>
                    ) : (
                        <div className="space-y-4">
                            {messages.data.map((message) => (
                                <article key={message.id} aria-label={`رسالة ${message.kindLabel}`} className="rounded-card border border-gray-100 bg-surface p-5 shadow-card">
                                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3">
                                        <div className="flex flex-wrap items-center gap-3"><KindPill kind={message.kind} label={message.kindLabel} /><ChannelLabel channel={message.channel} label={message.channelLabel} /></div>
                                        <MessageStatusPill status={message.status} label={message.statusLabel} />
                                    </div>
                                    <p className="whitespace-pre-wrap break-words py-4 text-base leading-8 text-gray-900">{message.body}</p>
                                    <dl className="grid gap-x-5 gap-y-3 border-t border-gray-100 pt-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                        <div><dt className="text-gray-500">رقم المستلم وقت الإرسال</dt><dd className="mt-1 font-semibold text-gray-800"><bdi dir="ltr">{message.phone}</bdi></dd></div>
                                        <div><dt className="text-gray-500">تاريخ التسجيل</dt><dd className="mt-1 text-gray-800">{dateTime(message.createdAt)}</dd></div>
                                        <div><dt className="text-gray-500">سجّلها</dt><dd className="mt-1 text-gray-800">{message.createdBy ?? '—'}</dd></div>
                                        {message.sentAt && <div><dt className="text-gray-500">تاريخ الإرسال المسجّل</dt><dd className="mt-1 text-gray-800">{dateTime(message.sentAt)}</dd></div>}
                                        {message.sentBy && <div><dt className="text-gray-500">أرسلها</dt><dd className="mt-1 text-gray-800">{message.sentBy}</dd></div>}
                                    </dl>
                                    {message.error && <p className="mt-4 rounded-control bg-brand-500/5 px-3 py-2 text-sm text-brand-600">سبب فشل الإرسال: {message.error}</p>}
                                </article>
                            ))}
                            <Pagination meta={messages} onPageChange={setPage} />
                        </div>
                    )}
                </div>
                <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-gray-100 bg-surface px-5 py-4 sm:px-7">
                    <p role="status" className="text-sm text-gray-500">{messages ? `${messages.total.toLocaleString('en')} رسالة${kind || status ? ' مطابقة' : ' مسجلة'}` : 'سجل الرسائل للاشتراك المختار'}</p>
                    <SecondaryButton onClick={onClose}>إغلاق</SecondaryButton>
                </footer>
            </section>
        </Modal>
    );
}
