import { usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import ThemeToggle from '@/Components/ThemeToggle';
import DevelopmentCredits from '@/Components/DevelopmentCredits';

export const COMPANY_NAME = 'شركة أبناء فارس أبو زايد للتجارة والاستيراد';
const COMPANY_NAME_EN = 'Sons of Fares AbuZayed Trading & Import Company';

const FEATURES = [
    { icon: 'users', label: 'إدارة المشتركين' },
    { icon: 'pin', label: 'متابعة الفروع' },
    { icon: 'shield', label: 'صلاحيات دقيقة' },
];

/**
 * The sign-in screen: the form on one half, the brand (logo, company and
 * system name, three highlights) on the other. On small screens only the
 * form shows, with the logo above it.
 */
export default function GuestLayout({ children }) {
    const { appName } = usePage().props;

    return (
        <div className="grid min-h-screen bg-gray-50 text-gray-900 lg:grid-cols-2">
            <div className="login-form-panel relative flex flex-col px-6 py-8 sm:px-12">
                <ThemeToggle className="absolute end-6 top-6 sm:end-8 sm:top-8" />

                <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-16">
                    <img src="/images/logo-af.webp" alt={appName} className="mx-auto mb-10 h-24 w-auto lg:hidden" />
                    {children}
                </div>

                <footer className="space-y-3">
                    <DevelopmentCredits />
                    <p className="text-center text-xs text-gray-400">
                        © {new Date().getFullYear()} {COMPANY_NAME}
                    </p>
                </footer>
            </div>

            <div className="login-brand-panel relative hidden flex-col items-center justify-center overflow-hidden border-s border-gray-100 px-10 py-12 text-center lg:flex">
                <span className="absolute top-12 inline-flex items-center gap-2 rounded-full border border-gray-200 bg-surface/70 px-3 py-1 text-xs font-semibold text-gray-700 backdrop-blur">
                    <span className="h-1.5 w-1.5 rounded-full bg-brand-500" aria-hidden="true" />
                    {appName}
                </span>

                <div className="relative">
                    <div
                        className="animate-glow-breathe absolute -start-6 top-1/2 h-56 w-56 -translate-y-1/2 rounded-full bg-brand-500/25 blur-3xl"
                        aria-hidden="true"
                    />
                    <img
                        src="/images/logo-af.webp"
                        alt={appName}
                        className="animate-logo-float relative h-48 w-auto drop-shadow-[0_18px_30px_rgba(16,24,40,0.35)]"
                    />
                </div>

                <p className="mt-8 text-xl font-bold text-gray-900">{COMPANY_NAME}</p>
                <p className="mt-1.5 text-xs font-semibold tracking-[0.08em] text-gray-500" dir="ltr">
                    {COMPANY_NAME_EN}
                </p>

                <h1 className="mt-10 text-5xl font-bold leading-tight text-gray-900 xl:text-[56px]">نظام التحصيل الكهربائي</h1>
                <div className="brand-spectrum mx-auto mt-6 w-28" />
                <p className="mx-auto mt-6 max-w-sm text-base leading-8 text-gray-500">
                    إدارة المشتركين والفروع والتحصيل من مكان واحد — بدقة، وسرعة، وثقة.
                </p>

                <ul className="mt-14 grid w-full max-w-lg grid-cols-3 gap-3">
                    {FEATURES.map((feature, index) => (
                        <li
                            key={feature.label}
                            className="rise-in flex flex-col items-center gap-3 rounded-2xl border border-gray-200 bg-surface/70 px-4 py-6 shadow-card backdrop-blur"
                            style={{ '--rise-delay': `${200 + index * 90}ms` }}
                        >
                            <span className="flex h-9 w-9 items-center justify-center rounded-xl border border-gray-200 bg-gray-50 text-gray-700">
                                <Icon name={feature.icon} className="h-[18px] w-[18px]" />
                            </span>
                            <span className="text-sm font-semibold text-gray-900">{feature.label}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
