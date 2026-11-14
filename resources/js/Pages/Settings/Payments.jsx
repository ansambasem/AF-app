import { Head, usePage } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import CurrencyRatesSection from './CurrencyRatesSection';
import BanksSection from './BanksSection';
import PageHeader from '@/Components/PageHeader';

/** The payment settings: the currencies' exchange rates and the receiving and sending banks. */
export default function Payments({ currencies, banks, storeUrl }) {
    const { status } = usePage().props;

    return <SettingsLayout header={<PageHeader title="إعدادات الدفعات" />}>
        <Head title="إعدادات الدفعات" />
        <div className="space-y-6">
            {status && <p role="status" className="rounded-control bg-emerald-500/10 p-4 text-sm text-emerald-700 dark:text-emerald-300">{status}</p>}
            <CurrencyRatesSection currencies={currencies} />
            <BanksSection banks={banks} storeUrl={storeUrl} />
        </div>
    </SettingsLayout>;
}
