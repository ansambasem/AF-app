import Icon from '@/Components/Icon';

const TAGS = {
    residential: { icon: 'home', className: 'text-blue-600 dark:text-blue-500' },
    commercial: { icon: 'shop', className: 'text-violet-600 dark:text-violet-400' },
};

/** A subscription's kind of tariff with its own icon and colour: a house for منزلي, a shop for تجاري. */
export default function TariffTag({ category, label }) {
    const tag = TAGS[category];

    return (
        <span className="inline-flex items-center gap-1.5 font-semibold text-gray-700">
            {tag && <Icon name={tag.icon} className={`h-4 w-4 shrink-0 ${tag.className}`} />}
            {label}
        </span>
    );
}
