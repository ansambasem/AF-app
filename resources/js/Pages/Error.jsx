import { Head, Link, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GuestLayout from '@/Layouts/GuestLayout';

/** What each error status says, in plain words, and the icon that goes with it. */
const MESSAGES = {
    403: { icon: 'lock', title: 'لا تملك صلاحية الوصول', text: 'ليست لديك صلاحية لفتح هذه الصفحة أو تنفيذ هذا الإجراء. إن كنت تحتاجه، تواصل مع مدير النظام.' },
    404: { icon: 'search', title: 'الصفحة غير موجودة', text: 'قد يكون الرابط غير صحيح، أو أن السجل الذي تبحث عنه حُذف أو نُقل.' },
    405: { icon: 'alert', title: 'الطلب غير مسموح', text: 'لا يقبل هذا العنوان هذا النوع من الطلبات. ارجع إلى لوحة التحكم وتابع من هناك.' },
    419: { icon: 'clock', title: 'انتهت صلاحية الصفحة', text: 'بقيت الصفحة مفتوحة وقتًا طويلًا. ارجع إلى لوحة التحكم وأعد المحاولة.' },
    429: { icon: 'clock', title: 'محاولات كثيرة', text: 'أرسلت طلبات كثيرة في وقت قصير. انتظر قليلًا ثم حاول مرة أخرى.' },
    500: { icon: 'alert', title: 'حدث خطأ غير متوقع', text: 'لم يكتمل طلبك. حاول مرة أخرى، وإن تكرر الخطأ فأبلغ مدير النظام.' },
    503: { icon: 'cog', title: 'النظام تحت الصيانة', text: 'نجري أعمال صيانة قصيرة. حاول مرة أخرى بعد قليل.' },
};

const BUTTON =
    'inline-flex items-center justify-center gap-2 rounded-control px-4 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900';

/**
 * The page an error (no permission, not found, expired, server trouble)
 * shows instead of the framework's plain English one, with a way back.
 * A signed-in user opening a page they may not open (`blank`) sees it empty,
 * the top bar still there to go elsewhere, with no word of the permission.
 */
export default function Error({ status, blank = false }) {
    const { appName, auth, branchContext } = usePage().props;
    const signedIn = Boolean(auth?.user);

    if (blank && signedIn) {
        return (
            <AuthenticatedLayout>
                <Head title={appName} />
            </AuthenticatedLayout>
        );
    }

    const message = MESSAGES[status] ?? MESSAGES[500];
    const RecoveryLink = branchContext?.recoveryUrl ? 'a' : Link;

    const body = (
        <div className="mx-auto flex max-w-md flex-col items-center py-16 text-center" role="alert">
            <Head title={message.title} />
            <span className="flex h-16 w-16 items-center justify-center rounded-full bg-brand-500/10 text-brand-600">
                <Icon name={message.icon} className="h-8 w-8" />
            </span>
            <p className="mt-6 font-display text-sm font-semibold tracking-widest text-gray-400" dir="ltr">
                {status}
            </p>
            <h1 className="mt-2 text-2xl font-bold text-gray-900">{message.title}</h1>
            <p className="mt-3 text-sm leading-7 text-gray-600">{message.text}</p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                <RecoveryLink href={branchContext?.recoveryUrl ?? (signedIn ? '/dashboard' : '/login')} className={`${BUTTON} bg-brand-gradient text-white shadow-glow hover:brightness-110`}>
                    <Icon name={signedIn ? 'home' : 'enter'} className="h-4 w-4" />
                    {signedIn ? 'العودة إلى لوحة التحكم' : 'تسجيل الدخول'}
                </RecoveryLink>
                <button type="button" onClick={() => window.history.back()} className={`${BUTTON} border border-gray-200 bg-surface text-gray-900 hover:bg-gray-50`}>
                    رجوع
                </button>
            </div>
        </div>
    );

    return signedIn ? <AuthenticatedLayout>{body}</AuthenticatedLayout> : <GuestLayout>{body}</GuestLayout>;
}
