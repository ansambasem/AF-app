import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';

const COLUMNS = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 sm:grid-cols-2',
    3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
};

/**
 * One part of a long form as its own card: an icon and a title (with an
 * "!" holding what the user must know about it, `info`), then its fields
 * in a grid of `columns` (1, 2 or 3 across on wide screens).
 */
export default function FormSection({ icon, title, info, columns = 3, children, className = '' }) {
    return (
        <section className={`rounded-card border border-gray-100 bg-surface p-5 shadow-card sm:p-6 ${className}`}>
            <div className="mb-5 flex items-center gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-graphite-gradient text-white dark:ring-1 dark:ring-white/10">
                    <Icon name={icon} className="h-5 w-5" />
                </span>
                <h4 className="font-bold text-gray-900">{title}</h4>
                {info && <InfoTip label={`عن ${title}`}>{info}</InfoTip>}
            </div>
            <div className={`grid gap-x-6 gap-y-4 ${COLUMNS[columns]}`}>{children}</div>
        </section>
    );
}
