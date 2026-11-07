import { Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';

/**
 * Every page's title, laid out the same way: an optional quiet line above
 * it (`eyebrow`: the branch, a link back), the title with an "!" holding
 * the page's guidance (`info`), an optional line of facts under it
 * (`meta`: dates, counts — not instructions), and the page's actions at
 * the other end. Goes in the layout's `header`.
 */
export default function PageHeader({ title, eyebrow = null, info = null, meta = null, actions = null, titleAddon = null }) {
    return (
        <>
            <div className="min-w-0">
                {eyebrow && <div className="mb-1 text-sm font-semibold text-gray-500">{eyebrow}</div>}
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h2 className="text-3xl font-bold text-gray-900">{title}</h2>
                    {info && <InfoTip label={`عن صفحة ${typeof title === 'string' ? title : ''}`.trim()}>{info}</InfoTip>}
                    {titleAddon}
                </div>
                {meta && <div className="mt-1 text-sm text-gray-500">{meta}</div>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </>
    );
}

/** The way back to the page above, as a page header's `eyebrow`. */
export function BackLink({ href, children }) {
    return (
        <Link href={href} prefetch className="inline-flex items-center gap-1 rounded transition hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900">
            <Icon name="chevron-right" className="h-4 w-4" />
            {children}
        </Link>
    );
}
