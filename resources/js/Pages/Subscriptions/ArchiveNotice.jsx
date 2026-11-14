import Icon from '@/Components/Icon';
import { formatDate, DATE_LOCALE } from '@/lib/format';

export default function ArchiveNotice({ subscription }) {
    if (!subscription?.archivedAt && !subscription?.archiveHistory?.length) return null;
    return <section className="rounded-card border border-gray-200 bg-surface p-5 text-base text-gray-700">
        <h3 className="flex items-center gap-2 font-bold text-gray-900"><Icon name="folder" className="h-5 w-5" />{subscription.archivedAt ? 'اشتراك مؤرشف — الخدمة منتهية' : 'سجل الأرشفة والاستعادة'}</h3>
        {subscription.archivedAt && <p className="mt-2 leading-7">إنهاء الخدمة: <bdi>{formatDate(subscription.serviceEndedOn)}</bdi> · {subscription.archiveReason}</p>}
        <details className="mt-3"><summary className="cursor-pointer text-sm font-semibold text-brand-600">سجل الإجراءات ({subscription.archiveHistory?.length ?? 0})</summary><ol className="mt-3 space-y-3">{(subscription.archiveHistory ?? []).toReversed().map((item, index) => <li key={`${item.at}-${index}`} className="border-t border-gray-100 pt-3 text-sm leading-6"><div className="flex flex-wrap justify-between gap-2"><b>{item.action === 'archive' ? 'أرشفة الاشتراك' : 'استعادة الاشتراك'} · {item.user_name}</b><time dateTime={item.at}>{new Date(item.at).toLocaleString(DATE_LOCALE, { timeZone: 'Asia/Hebron', day: '2-digit', month: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' })}</time></div><p className="whitespace-pre-wrap break-words">{item.reason}</p>{item.service_ended_on && <p>انتهاء الخدمة: <bdi>{item.service_ended_on}</bdi></p>}</li>)}</ol></details>
    </section>;
}
