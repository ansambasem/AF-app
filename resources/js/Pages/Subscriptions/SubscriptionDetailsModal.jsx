import { useEffect, useId, useRef, useState } from 'react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import { BALANCE_HINTS } from '@/lib/balance';
import { chainColor, compactStatementEntries, describeBalance, filterStatementEntries, relatedLineChains } from '@/lib/accountStatement';
import { groupReadingsByMonth } from '@/lib/readingHistory';
import { hasLatestWeekReading, readingOptionFor } from '@/lib/readings';
import MeterReadingModal from '@/Pages/MeterReadings/MeterReadingModal';
import StopStandingDiscountButton from './StopStandingDiscountButton';
import StatementViewTabs from './StatementViewTabs';
import ArchiveNotice from './ArchiveNotice';
import './SubscriptionDetailsModal.css';
import { formatDate } from '@/lib/format';

const TABS = [['details', 'البيانات'], ['transactions', 'الحساب'], ['consumption', 'الاستهلاك']];
const number = (value) => value == null || value === '' ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });
const money = (value) => value == null || value === '' ? '—' : `${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₪`;
const monthLabel = (month) => new Intl.DateTimeFormat('ar', { month: 'long', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`));

function ProfileIcon({ name }) {
    const paths = {
        copy: <><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></>,
        expand: <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />,
        previous: <path d="m18 15-6-6-6 6" />,
        next: <path d="m6 9 6 6 6-6" />,
    };
    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}

function Field({ label, value, numeric = false, copy = false, wide = false }) {
    const [copyStatus, setCopyStatus] = useState('');
    const empty = value === null || value === undefined || value === '';
    async function copyValue() {
        try {
            await navigator.clipboard.writeText(String(value));
            setCopyStatus('تم النسخ');
        } catch {
            setCopyStatus('تعذر النسخ');
        }
    }
    return <div className={wide ? 'sp-field sp-field-wide' : 'sp-field'}>
        <dt>{label}</dt>
        <dd className={empty ? 'sp-empty-value' : ''}>
            <span className={numeric ? 'sp-number' : undefined}>{empty ? 'لا يوجد' : value}</span>
            {copy && !empty && <button type="button" className="sp-copy" onClick={copyValue} aria-label={`نسخ ${label}`} title={copyStatus || 'نسخ'}><ProfileIcon name="copy" /></button>}
            {copyStatus && <span className="sp-copy-status" role="status">{copyStatus}</span>}
        </dd>
    </div>;
}

function Section({ title, icon, onEdit, children, trailing }) {
    return <section className="sp-section">
        <h3><span className="sp-section-icon"><Icon name={icon} /></span>{title}
            {onEdit && <button type="button" onClick={onEdit}><Icon name="pencil" />تعديل</button>}{trailing}
        </h3>{children}
    </section>;
}

export function AccountTab({ statement, onOpenStatement, onLoadStatement, loading }) {
    const [type, setType] = useState('');
    const [view, setView] = useState('compact');
    const [hoveredChain, setHoveredChain] = useState(null);
    function chooseView(nextView) {
        setView(nextView);
    }
    if (!statement) {
        return loading ? <div className="sp-loading" role="status" aria-label="جارٍ تحميل الحساب"><div /><div /><div /><div /></div>
            : <div className="sp-empty"><p>تعذر تحميل الحساب.</p><button type="button" className="sp-button" onClick={onLoadStatement}>إعادة المحاولة</button></div>;
    }
    const isCompact = view === 'compact';
    const compact = compactStatementEntries(statement.entries);
    const entries = filterStatementEntries(isCompact ? compact.entries : statement.entries, { type }).toReversed();
    const chains = relatedLineChains(statement.entries);
    const balance = describeBalance(statement.summary.balance);
    return <>
        <div className="sp-account-summary">
            <div><small>الرصيد</small><b className={`sp-${balance.tone}`} title={BALANCE_HINTS[balance.tone]}><bdi dir="ltr">{balance.signed} ₪</bdi></b></div>
            <div><small>مجموع الفواتير والرسوم</small><b>{money(statement.summary.charged)}</b></div>
            <div><small>مجموع الدفعات</small><b className="sp-credit">{money(statement.summary.paid)}</b></div>
            <div><small>مجموع الخصومات</small><b>{money(statement.summary.discounted)}</b></div>
        </div>
        <div className="sp-account-toolbar">
            <div className="sp-filters" role="group" aria-label="نوع المعاملة">{[['', 'الكل'], ['meter_reading', 'فواتير'], ['payment', 'دفعات'], ['debit', 'تحميلات'], ['credit', 'دفعات وخصومات']].map(([value, label]) => <button type="button" key={value} aria-pressed={type === value} onClick={() => setType(value)}>{label}</button>)}</div>
            <StatementViewTabs value={view} onChange={chooseView} />
            <button type="button" className="sp-button" onClick={() => onOpenStatement()}><Icon name="ledger" />كشف الحساب الكامل</button>
        </div>
        <div className="sp-table-wrap"><table className="sp-statement">
            <thead><tr><th>التاريخ</th><th>المعاملة</th><th>الوصف</th><th className="sp-amount-column">المبلغ</th><th className="sp-amount-column">الرصيد بعدها</th></tr></thead>
            <tbody>{entries.map((entry) => {
                const running = describeBalance(entry.balance);
                const chain = chains.get(entry.id) ?? null;
                const color = chainColor(chain);
                return <tr key={entry.id} data-chain={chain ?? undefined} className={chain !== null && chain === hoveredChain ? 'sp-related' : undefined} style={color ? { '--sp-chain': color } : undefined}
                    onMouseEnter={chain ? () => setHoveredChain(chain) : undefined} onMouseLeave={chain ? () => setHoveredChain(null) : undefined}>
                    <td><span className="sp-date">{formatDate(entry.date.slice(0, 10))}<small>{entry.date.slice(11)}</small></span></td>
                    <td><span className={`sp-transaction-type ${entry.isCredit ? 'sp-credit' : 'sp-owes'}`}><Icon name={entry.isCredit ? 'arrow-down' : 'receipt'} />{entry.typeLabel}</span></td>
                    <td>{color && <span className="sp-chain-dot" title="الحركات المرتبطة بنفس اللون" aria-hidden="true" />}{entry.description}{entry.cancellation && <small className="sp-cancellation">{entry.cancellation.wasCorrected ? 'مصححة' : 'ملغاة'} · {entry.cancellation.reasonLabel}</small>}{entry.history?.length > 0 && <button type="button" className="sp-history-link" onClick={() => chooseView('full')}><Icon name="history" />صُحّحت · {entry.history.length} حركات سابقة</button>}</td>
                    <td className="sp-amount-column"><b className="sp-number">{number(entry.amount)} {entry.currencyLabel}</b></td>
                    <td className="sp-amount-column"><bdi dir="ltr" className="sp-number" title={BALANCE_HINTS[running.tone]}>{running.signed} ₪</bdi></td>
                </tr>;
            })}</tbody>
        </table>{entries.length === 0 && <p className="sp-empty">لا توجد حركات لهذا النوع.</p>}</div>
        {isCompact && compact.hiddenCount > 0 && <p className="sp-hidden-note">أُخفيت {compact.hiddenCount} حركة ملغاة مع قيودها العكسية (مجموعها صفر) · <button type="button" onClick={() => chooseView('full')}>عرض كل الحركات</button></p>}
    </>;
}

export default function SubscriptionDetailsModal({ subscription, onClose, onEdit, onEditPersonal, onOpenStatement, onLoadStatement, statement, statementLoading, onSendMessage, onMessageHistory, onPrevious, onNext, onOpenReadings, canUpdate, readingWeekOptions = [] }) {
    const [activeTab, setActiveTab] = useState('details');
    const [expanded, setExpanded] = useState(false);
    const [enteringReading, setEnteringReading] = useState(false);
    const tabsId = useId();
    const dialogRef = useRef(null);
    const bodyRef = useRef(null);
    const balance = describeBalance(statement?.summary.balance ?? subscription?.outstandingBalance ?? 0);
    const readings = subscription?.meterReadings ?? [];
    const lastReading = readings[0];
    const months = groupReadingsByMonth(readings).slice(0, 6).reverse();
    const maximum = Math.max(1, ...months.map((month) => month.totals.consumption));
    const currentWeekRecorded = subscription ? hasLatestWeekReading(subscription, readingWeekOptions) : false;
    const lastPayment = statement?.entries.findLast((entry) => entry.type === 'payment' && !entry.cancellation && !entry.isReversal);

    useEffect(() => {
        if (!subscription) {
            return;
        }
        const previousFocus = document.activeElement;
        dialogRef.current?.focus();
        return () => previousFocus?.focus();
    }, [subscription?.id]);

    function selectTab(tab, focus = false) {
        setActiveTab(tab);
        bodyRef.current?.scrollTo({ top: 0 });
        if (tab === 'transactions' && !statement && !statementLoading) {
            onLoadStatement();
        }
        if (focus) {
            document.getElementById(`${tabsId}-${tab}`)?.focus();
        }
    }

    function handleKeyDown(event) {
        if (enteringReading || event.altKey || event.ctrlKey || event.metaKey || event.target.closest('input, textarea, select, [contenteditable="true"]')) {
            return;
        }
        if (event.key === 'Tab') {
            const controls = [...dialogRef.current.querySelectorAll('button:not(:disabled), a[href], [tabindex="0"]')].filter((element) => element.getClientRects().length > 0);
            const first = controls[0];
            const last = controls.at(-1);
            if (event.shiftKey && (document.activeElement === first || document.activeElement === dialogRef.current)) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        } else if (['1', '2', '3'].includes(event.key)) {
            event.preventDefault();
            selectTab(TABS[Number(event.key) - 1][0], true);
        } else if (!event.target.closest('[role="tablist"]') && ['ArrowLeft', 'ArrowRight'].includes(event.key)) {
            event.preventDefault();
            (event.key === 'ArrowLeft' ? onNext : onPrevious)?.();
        }
    }

    return <>
        <Modal show={Boolean(subscription)} onClose={onClose} maxWidth="full" centered panelClassName={`subscription-profile-panel ${expanded ? 'is-expanded' : ''}`}>
            {subscription && <div className="subscription-profile" role="dialog" aria-modal="true" aria-labelledby={`${tabsId}-title`} dir="rtl" tabIndex={-1} ref={dialogRef} onKeyDown={handleKeyDown}>
                <header className="sp-header">
                    <div className="sp-top">
                        <span className="sp-avatar" aria-hidden="true">{subscription.display_name?.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('')}<i className={`sp-status-${subscription.status}`} /></span>
                        <div className="sp-identity">
                            <h2 id={`${tabsId}-title`}>{subscription.display_name}<span className={`sp-status sp-status-${subscription.status}`}><i />{subscription.statusLabel}</span></h2>
                            <div className="sp-meta"><span>ملف المشترك</span><span className="sp-number">#{subscription.subscriber_number ?? subscription.account_number}</span><span><Icon name="pin" />{subscription.branchName}</span><span><Icon name="bolt" />{subscription.tariffCategoryLabel}{subscription.circuitBreakerAmpere && ` · ${subscription.circuitBreakerAmpere} أمبير`}</span></div>
                            <div className="sp-facts">{lastPayment && <span>آخر دفعة <b>{number(lastPayment.amount)} {lastPayment.currencyLabel}</b></span>}<span>القراءة السابقة <b>{number(subscription.lastReading)}</b></span>{lastReading && <span>استهلاك آخر أسبوع <b>{number(lastReading.consumption)}</b> كيلوواط ساعة</span>}</div>
                        </div>
                        <div className={`sp-balance sp-${balance.tone}`} title={BALANCE_HINTS[balance.tone]}><small>الرصيد الحالي</small><b><bdi dir="ltr">{balance.signed} ₪</bdi><em>{balance.label}</em></b></div>
                        <div className="sp-tools">
                            <button type="button" onClick={onPrevious} disabled={!onPrevious} aria-label="المشترك السابق" title="المشترك السابق"><ProfileIcon name="previous" /></button>
                            <button type="button" onClick={onNext} disabled={!onNext} aria-label="المشترك التالي" title="المشترك التالي"><ProfileIcon name="next" /></button>
                            <button type="button" onClick={() => setExpanded(!expanded)} aria-label={expanded ? 'تصغير' : 'توسيع'} title={expanded ? 'تصغير' : 'توسيع'} aria-pressed={expanded}><ProfileIcon name="expand" /></button>
                            <button type="button" onClick={onClose} aria-label="إغلاق" title="إغلاق (Esc)"><Icon name="close" /></button>
                        </div>
                    </div>
                    <div className="sp-header-bottom">
                        <div className="sp-tabs" role="tablist" aria-label="أقسام ملف المشترك">
                            {TABS.map(([tab, label], index) => <button key={tab} type="button" id={`${tabsId}-${tab}`} role="tab" aria-selected={activeTab === tab} aria-controls={`${tabsId}-${tab}-panel`} tabIndex={activeTab === tab ? 0 : -1} onClick={() => selectTab(tab)} onKeyDown={(event) => {
                                if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                                    event.preventDefault();
                                    const next = event.key === 'Home' ? 0 : event.key === 'End' ? 2 : (index + (event.key === 'ArrowLeft' ? 1 : 2)) % 3;
                                    selectTab(TABS[next][0], true);
                                }
                            }}>{label}{tab === 'transactions' && statement && <span>{statement.entries.length}</span>}</button>)}
                        </div>
                        <div className="sp-quick-actions">
                            {subscription.canRecordPayment && <button type="button" className="sp-pay" onClick={() => onOpenStatement('payment')}><Icon name="banknotes" /><span>تسجيل دفعة</span></button>}
                            {onSendMessage && <button type="button" onClick={onSendMessage} aria-label="إرسال SMS" title="إرسال SMS"><Icon name="chat" /></button>}
                            {onMessageHistory && <button type="button" onClick={onMessageHistory} title="سجل رسائل هذا الاشتراك"><Icon name="messages" /><span>سجل الرسائل</span></button>}
                            {subscription.canRecordReading && <button type="button" onClick={() => setEnteringReading(true)} disabled={currentWeekRecorded} aria-label={currentWeekRecorded ? 'تم إدخال قراءة هذا الأسبوع' : 'تسجيل قراءة'} title={currentWeekRecorded ? 'تم إدخال قراءة هذا الأسبوع' : 'تسجيل قراءة'}><Icon name="gauge" /></button>}
                            <button type="button" onClick={() => onOpenStatement()} aria-label="كشف حساب" title="كشف حساب"><Icon name="ledger" /></button>
                            {canUpdate && <button type="button" onClick={onEdit} aria-label="تعديل حالة الاشتراك" title="تعديل حالة الاشتراك"><Icon name="power" /></button>}
                        </div>
                    </div>
                </header>
                <div className="sp-body" ref={bodyRef}>
                    <div id={`${tabsId}-details-panel`} role="tabpanel" aria-labelledby={`${tabsId}-details`} hidden={activeTab !== 'details'}>
                        <div className="sp-columns">
                            <ArchiveNotice subscription={subscription} />
                            {!subscription.archivedAt && subscription.status !== 'active' && <div className="sp-notice"><Icon name="warning" /><div><b>الاشتراك {subscription.statusLabel}</b><span>{`الرصيد \u2066${balance.signed} ₪\u2069.`} راجع بيانات الاشتراك وحالته قبل تسجيل قراءة جديدة.</span></div></div>}
                            <Section title="البيانات الشخصية" icon="user" onEdit={canUpdate ? onEditPersonal : undefined}><dl className="sp-fields">
                                <Field label="الاسم" value={subscription.full_name} /><Field label="رقم المشترك" value={subscription.subscriber_number} numeric copy />
                                <Field label="رقم الهوية" value={subscription.national_id} numeric copy /><Field label="رقم الجوال" value={subscription.phone ?? subscription.contact_phone} numeric copy />
                            </dl></Section>
                            <Section title="الاشتراك والقاطع" icon="bolt" onEdit={canUpdate ? onEdit : undefined}><dl className="sp-fields">
                                <Field label="نوع الاشتراك" value={<span className="sp-chip"><Icon name="bolt" />{subscription.tariffCategoryLabel}</span>} /><Field label="تصنيف الزبائن" value={subscription.tariffSegmentName} />
                                <Field label="القاطع" value={subscription.circuitBreakerAmpere ? `${subscription.circuitBreakerAmpere} أمبير` : null} /><Field label="سعر الكيلو" value={money(subscription.kilowattPrice)} numeric />
                                <Field label="الحد الأدنى" value={money(subscription.minimum_charge)} numeric /><Field label="خصم القراءات الأسبوعية" value={subscription.standingDiscountSummary && <>{subscription.standingDiscountSummary}{subscription.canAddDiscount && <StopStandingDiscountButton subscriptionId={subscription.id} subscriptionName={subscription.full_name} discountLabel={subscription.standingDiscountSummary} className="ms-2" />}</>} />
                            </dl></Section>
                            <Section title="الموقع والعداد" icon="pin" onEdit={canUpdate ? onEdit : undefined}><dl className="sp-fields">
                                <Field label="الفرع" value={subscription.branchName} /><Field label="المحافظة" value={subscription.governorateName} />
                                <Field label="المنطقة" value={subscription.areaName} /><Field label="منطقة 2" value={subscription.subAreaName} />
                                <Field label="اسم الطبلون" value={subscription.meterBoxName} /><Field label="رقم الطبلون" value={subscription.meterBoxNumber} numeric copy />
                                <Field label="العنوان" value={subscription.address} wide />
                            </dl></Section>
                            <Section title="الاشتراك" icon="calendar" onEdit={canUpdate ? onEdit : undefined}><dl className="sp-fields">
                                <Field label="اسم الاشتراك" value={subscription.display_name} /><Field label="رقم الاشتراك" value={subscription.account_number} numeric copy />
                                <Field label="تاريخ الاشتراك" value={subscription.subscription_date} numeric /><Field label="رسوم الاشتراك" value={money(subscription.subscription_fee)} numeric />
                                {subscription.reconnected_at && <Field label="تاريخ إعادة التوصيل" value={subscription.reconnected_at} numeric />}
                                <Field label="القراءة الأولى" value={subscription.initial_reading == null ? 'لم تُدخل بعد' : number(subscription.initial_reading)} numeric={subscription.initial_reading != null} /><Field label="سجّله" value={subscription.registeredByName} />
                                {subscription.subscription_phone && subscription.subscription_phone !== subscription.phone && <Field label="جوال الاشتراك" value={subscription.contact_phone} numeric copy />}
                                <Field label="ملاحظات" value={subscription.notes} wide />
                            </dl></Section>
                        </div>
                    </div>
                    <div id={`${tabsId}-transactions-panel`} role="tabpanel" aria-labelledby={`${tabsId}-transactions`} hidden={activeTab !== 'transactions'} aria-busy={statementLoading}>
                        {activeTab === 'transactions' && <AccountTab statement={statement} loading={statementLoading} onLoadStatement={onLoadStatement} onOpenStatement={onOpenStatement} />}
                    </div>
                    <div id={`${tabsId}-consumption-panel`} role="tabpanel" aria-labelledby={`${tabsId}-consumption`} hidden={activeTab !== 'consumption'}>
                        <div className="sp-columns">
                            <Section title="الاستهلاك الشهري" icon="bolt" trailing={<small className="sp-section-unit">كيلوواط ساعة</small>}>
                                {months.length ? <div className="sp-chart" role="img" aria-label={`الاستهلاك الشهري: ${months.map((month) => `${month.month}: ${number(month.totals.consumption)} كيلوواط ساعة`).join('، ')}`}>{months.map((month) => <div key={month.month} title={`${month.month}: ${number(month.totals.consumption)} كيلوواط ساعة`}><b>{number(month.totals.consumption)}</b><i style={{ height: `${month.totals.consumption / maximum * 80}px` }} /><small>{monthLabel(month.month)}</small><small>{month.month.slice(0, 4)}</small></div>)}</div> : <p className="sp-empty">لا توجد قراءات مسجلة بعد.</p>}
                            </Section>
                            <Section title="القراءات" icon="table"><dl className="sp-fields">
                                <Field label="القراءة الحالية" value={number(subscription.lastReading)} numeric /><Field label="القراءة السابقة" value={lastReading ? number(lastReading.previous_reading) : null} numeric />
                                <Field label="استهلاك آخر أسبوع" value={lastReading ? `${number(lastReading.consumption)} كيلوواط ساعة` : null} /><Field label="نهاية أسبوع القراءة" value={lastReading?.weekEnd} numeric />
                                <Field label="تاريخ التسجيل" value={lastReading?.recordedAt} numeric /><Field label="قرأها" value={lastReading?.recordedByName} />
                            </dl></Section>
                            <div className="sp-reading-footer"><span>يُجمع الاستهلاك حسب شهر نهاية أسبوع القراءة.</span><button type="button" className="sp-button" onClick={onOpenReadings}><Icon name="history" />سجل القراءات الكامل</button></div>
                        </div>
                    </div>
                </div>
                <footer className="sp-footer"><span className="sp-shortcuts"><kbd>←</kbd><kbd>→</kbd> المشترك التالي · <kbd>1</kbd><kbd>2</kbd><kbd>3</kbd> التبويبات · <kbd>Esc</kbd> إغلاق</span><div className="sp-footer-actions"><button type="button" className="sp-button" onClick={onClose}>إغلاق</button>{canUpdate && <button type="button" className="sp-button sp-primary" onClick={onEdit}><Icon name="pencil" />تعديل البيانات</button>}</div></footer>
            </div>}
        </Modal>
        {enteringReading && subscription && <MeterReadingModal show onClose={() => setEnteringReading(false)} reading={null} fixedSubscription={readingOptionFor(subscription)} weekOptions={readingWeekOptions} />}
    </>;
}
