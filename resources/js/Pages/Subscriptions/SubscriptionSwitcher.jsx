import StatusPill from '@/Components/DataTable/StatusPill';
import Balance from '@/Components/Balance';
import Icon from '@/Components/Icon';
import './SubscriptionSwitcher.css';

const STATUS_TONES = {
    active: 'green',
    suspended: 'amber',
    disconnected: 'gray',
};

/**
 * Every subscription of the same person above their statement, each with
 * its balance, plus what they owe across all of them. Picking another one
 * shows that subscription's records. Hidden when the person has only one.
 */
export default function SubscriptionSwitcher({ subscriberNumber, subscriptions, currentId, onSelect }) {
    if (!subscriptions || subscriptions.length < 2) {
        return null;
    }

    const total = subscriptions.reduce((sum, subscription) => sum + Math.round(Number(subscription.balance) * 100), 0) / 100;

    return (
        <section className="subscription-switcher" aria-label="اشتراكات المشترك">
            <div className="ssw-heading">
                <div className="ssw-identity">
                    <span className="ssw-icon"><Icon name="users" /></span>
                    <div>
                        <h4>اشتراكات المشترك <span className="ssw-count">{subscriptions.length} اشتراكات</span></h4>
                        {subscriberNumber && <p>المشترك رقم <bdi dir="ltr" className="ssw-account-number">{subscriberNumber}</bdi></p>}
                    </div>
                </div>
                <div className="ssw-total">
                    <span>الرصيد الإجمالي للاشتراكات</span>
                    <div><Balance value={total} word className="ssw-total-number" /></div>
                </div>
            </div>
            <p className="ssw-hint"><Icon name="enter" /><span>اضغط على بطاقة الاشتراك لعرض كشفه.</span></p>
            <div className="ssw-grid">
                {subscriptions.map((subscription) => {
                    const current = subscription.id === currentId;
                    const area = [...new Set([subscription.areaName, subscription.subAreaName].filter(Boolean))].join(' / ');

                    return (
                        <button
                            key={subscription.id}
                            type="button"
                            aria-current={current ? 'true' : undefined}
                            aria-label={`عرض كشف الاشتراك ${subscription.accountNumber}`}
                            disabled={current}
                            onClick={() => onSelect(subscription)}
                            className="ssw-card"
                        >
                            <span className="ssw-card-top">
                                <span className="ssw-account">
                                    <span>رقم الاشتراك</span>
                                    <bdi dir="ltr" className="ssw-account-number">{subscription.accountNumber}</bdi>
                                </span>
                                <StatusPill tone={STATUS_TONES[subscription.status]} label={subscription.statusLabel} />
                            </span>
                            <strong className="ssw-name">{subscription.fullName}</strong>
                            <span className="ssw-facts">
                                <span>
                                    <span className="ssw-fact-label"><Icon name="grid" />الطبلون</span>
                                    {subscription.meterBoxName || subscription.meterBoxNumber ? (
                                        <span className="ssw-fact-value ssw-meter-box">
                                            {subscription.meterBoxName && <span>{subscription.meterBoxName}</span>}
                                            {subscription.meterBoxNumber && <bdi dir="ltr" className="ssw-account-number">{subscription.meterBoxName ? `(${subscription.meterBoxNumber})` : subscription.meterBoxNumber}</bdi>}
                                        </span>
                                    ) : <span className="ssw-fact-value ssw-empty">غير محدد</span>}
                                </span>
                                <span>
                                    <span className="ssw-fact-label"><Icon name="building" />التصنيف</span>
                                    <strong className="ssw-fact-value">{subscription.tariffCategoryLabel || 'غير محدد'}</strong>
                                </span>
                                <span className="ssw-area">
                                    <span className="ssw-fact-label"><Icon name="pin" />المنطقة</span>
                                    <strong className={`ssw-fact-value${area ? '' : ' ssw-empty'}`}>{area || 'غير محددة'}</strong>
                                </span>
                            </span>
                            <span className="ssw-balance"><span>الرصيد الحالي</span><span className="ssw-balance-value"><Balance value={subscription.balance} word className="ssw-card-number" /></span></span>
                            <span className="ssw-action">{current ? <><Icon name="check" />المعروض الآن</> : <>عرض كشف هذا الاشتراك<Icon name="arrow-left" /></>}</span>
                        </button>
                    );
                })}
            </div>
        </section>
    );
}
