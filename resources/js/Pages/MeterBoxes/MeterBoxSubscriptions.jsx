import { useEffect, useRef, useState } from 'react';
import { Link, useHttp } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import RowIdentity from '@/Components/DataTable/RowIdentity';
import StatusPill from '@/Components/DataTable/StatusPill';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatNumber } from '@/lib/format';

const STATUS_TONES = { active: 'green', suspended: 'amber', disconnected: 'red' };

export function SubscriptionRows({ subscriptions, boxNumber }) {
    return (
        <div role="table" tabIndex={0} aria-label={`مشتركو ${boxNumber}`} className="max-h-[480px] overflow-y-auto rounded-control border border-gray-100 bg-surface text-start focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900">
            <div role="row" className="meter-box-subscription-columns sticky top-0 z-10 hidden border-b border-brand-500/10 bg-surface px-4 py-2.5 text-xs font-bold text-gray-700">
                {['المشترك / رقم الاشتراك', 'القراءة السابقة', 'القاطع', 'الحالة', 'الكشف'].map((label) => <span role="columnheader" key={label}>{label}</span>)}
            </div>
            <div role="rowgroup" className="divide-y divide-gray-100">
                {subscriptions.map((subscription) => {
                    return (
                        <div role="row" key={subscription.id} className="meter-box-subscription-columns grid items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-gray-50/60">
                            <div role="cell" className="meter-box-subscription-name min-w-0">
                                <Link href={subscription.statementUrl} className="block rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900">
                                    <RowIdentity name={subscription.display_name} subtitle={subscription.account_number} subtitleDir="ltr" />
                                </Link>
                            </div>
                            <div role="cell" className="meter-box-subscription-reading text-sm text-gray-600">
                                <span className="meter-box-subscription-label text-xs text-gray-500">القراءة: </span>
                                <b className="font-display font-semibold">{subscription.lastReading == null ? '—' : formatNumber(subscription.lastReading)}</b>
                            </div>
                            <div role="cell" className="meter-box-subscription-breaker text-sm text-gray-600">
                                {subscription.circuitBreakerAmpere == null ? '—' : <><b className="font-display font-semibold">{subscription.circuitBreakerAmpere}</b> <span className="text-xs">أمبير</span></>}
                            </div>
                            <div role="cell" className="meter-box-subscription-status"><StatusPill tone={STATUS_TONES[subscription.status]} label={subscription.statusLabel} /></div>
                            <div role="cell" className="meter-box-subscription-action text-end">
                                <Link href={subscription.statementUrl} className="row-action row-action-quiet" aria-label={`كشف حساب ${subscription.display_name}`} title="كشف الحساب">
                                    <Icon name="chevron-left" className="h-4 w-4" />
                                </Link>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export default function MeterBoxSubscriptions({ meterBox, sort, direction, canRecordReadings, canSendMessages }) {
    const http = useHttp();
    const [failed, setFailed] = useState(false);
    const requestNumber = useRef(0);

    function load(page = 1) {
        setFailed(false);
        const request = ++requestNumber.current;
        http.get(`/meter-boxes/${meterBox.id}/subscriptions?${new URLSearchParams({ page, sort, direction })}`).catch(() => {
            if (request === requestNumber.current) {
                setFailed(true);
            }
        });
    }

    useEffect(() => {
        load();
        return () => {
            requestNumber.current++;
            http.cancel();
        };
    }, [sort, direction]);

    const rows = http.response;

    return (
        <section id={`meter-box-subscriptions-${meterBox.id}`} aria-label={`مشتركو ${meterBox.box_number}`} aria-busy={http.processing} className="overflow-hidden rounded-card border border-brand-500/20 bg-surface text-start shadow-card">
            <div className="flex flex-wrap items-center gap-3 border-b border-brand-500/15 bg-brand-500/[0.045] p-4">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-brand-500/10 text-brand-600"><Icon name="users" className="h-5 w-5" /></span>
                    <div className="min-w-0">
                        <h4 className="text-sm font-bold text-gray-900">مشتركو الطبلون <span dir="ltr" className="font-display">{meterBox.box_number}</span></h4>
                        <p className="mt-1 text-xs font-semibold text-gray-600">{meterBox.display_name}</p>
                    </div>
                    <span className="shrink-0 rounded-control border border-brand-500/15 bg-surface px-2.5 py-1 text-xs font-bold text-brand-600">{formatNumber(meterBox.subscriptionsCount)} مشترك</span>
                </div>
                <div className="flex w-full flex-wrap items-end gap-3 sm:ms-auto sm:w-auto">
                    {canSendMessages && meterBox.subscriptionsCount > 0 && <Link href={`/messages/create?kind=custom&channel=sms&status=&meter_box_id=${meterBox.id}`} className="inline-flex items-center gap-2 rounded-control border border-gray-200 bg-surface px-3 py-1.5 text-xs font-semibold text-gray-900 hover:bg-gray-50"><Icon name="messages" className="h-4 w-4" />رسالة للمشتركين</Link>}
                    {canRecordReadings && meterBox.activeSubscriptionsCount > 0 && <Link href={`/meter-readings?filter[meter_box_id]=${meterBox.id}`} className="inline-flex items-center gap-2 rounded-control border border-gray-200 bg-surface px-3 py-1.5 text-xs font-semibold text-gray-900 hover:bg-gray-50"><Icon name="gauge" className="h-4 w-4" />تسجيل قراءات</Link>}
                </div>
            </div>
            <div className="p-4">
            {failed ? (
                <div role="alert" className="rounded-control border border-gray-100 bg-surface p-5 text-center text-sm text-gray-600">
                    <p>تعذّر تحميل المشتركين. حاول مرة أخرى.</p>
                    <SecondaryButton className="mt-3" onClick={() => load(rows?.current_page ?? 1)}>إعادة المحاولة</SecondaryButton>
                </div>
            ) : !rows ? (
                <div role="status" className="rounded-control border border-gray-100 bg-surface p-4">
                    <p className="mb-3 text-sm text-gray-500">جارٍ تحميل المشتركين…</p>
                    <div className="grid gap-3 motion-safe:animate-pulse" aria-hidden="true">{[1, 2, 3].map((id) => <div key={id} className="h-10 rounded-lg bg-gray-100" />)}</div>
                </div>
            ) : rows.data.length === 0 ? (
                <div className="rounded-control border border-dashed border-brand-500/20 bg-brand-500/[0.025] px-4 py-8 text-center">
                    <span className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-control bg-brand-500/10 text-brand-600"><Icon name="users" className="h-5 w-5" /></span>
                    <p className="text-sm font-bold text-gray-900">لا يوجد مشتركون على هذا الطبلون.</p>
                    <p className="mt-1.5 text-xs text-gray-500">سيظهر المشتركون هنا عند ربطهم بالطبلون.</p>
                </div>
            ) : (
                <>
                    <SubscriptionRows subscriptions={rows.data} boxNumber={meterBox.box_number} />
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                        <span>عرض <b className="font-display">{rows.from}–{rows.to}</b> من <b className="font-display">{rows.total}</b> مشترك</span>
                        {rows.last_page > 1 && <div className="flex items-center gap-2">
                            <SecondaryButton className="!px-3 !py-1.5 !text-xs" disabled={http.processing || rows.current_page === 1} onClick={() => load(rows.current_page - 1)}>السابق</SecondaryButton>
                            <span className="font-display">{rows.current_page} / {rows.last_page}</span>
                            <SecondaryButton className="!px-3 !py-1.5 !text-xs" disabled={http.processing || rows.current_page === rows.last_page} onClick={() => load(rows.current_page + 1)}>التالي</SecondaryButton>
                        </div>}
                    </div>
                </>
            )}
            </div>
        </section>
    );
}
