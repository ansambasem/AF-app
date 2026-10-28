import { Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { shouldPrefetchPage, isActiveLink, lockedLinkProps, sectionLinks } from '@/lib/navigation';

/** The small lock on a page the user may not open; its link opens nothing. */
export function LockMark() {
    return (
        <span className="tn-lock" title="لا تملك صلاحية فتح هذه الصفحة">
            <Icon name="lock" />
            <span className="sr-only">(بلا صلاحية)</span>
        </span>
    );
}

/** A wide panel for a section with more than four plain pages, two columns of them. */
export function megaWidth(group) {
    const links = sectionLinks(group);
    const featured = (group.featured ?? []).filter((href) => links.some((link) => link.href === href));

    return links.length - featured.length > 4 ? 580 : 420;
}

/**
 * One section's pages in the big menu: the section's name and what it holds,
 * its featured pages as dark cards, then the rest with a line on each, and a
 * footer with the keys and a way to every page.
 */
export default function MegaMenu({ group, url, badges, width, shift, leaving, onSearch, contentRef }) {
    const links = sectionLinks(group);
    const featured = links.filter((link) => group.featured?.includes(link.href));
    const rest = links.filter((link) => !featured.includes(link));
    const twoColumns = rest.length > 4;

    return (
        <div
            ref={contentRef}
            className={`tn-mega-content ${leaving ? 'is-leaving' : ''}`}
            style={{ width, '--tn-shift': `${shift}px` }}
            aria-hidden={leaving || undefined}
        >
            <div className="flex">
                <div className="min-w-0 flex-1 p-3 pt-4">
                    <div className="mb-2 flex items-center gap-3 px-2">
                        <span className="grid h-10 w-10 place-items-center rounded-xl bg-graphite-gradient text-white shadow-[0_10px_20px_-12px_rgb(16_24_40/.7)]">
                            <Icon name={group.icon} className="h-5 w-5" />
                        </span>
                        <div>
                            <b className="block text-[16px] font-bold leading-normal text-gray-900">{group.label}</b>
                            <small className="block text-[12.5px] leading-normal text-gray-500">{group.description}</small>
                        </div>
                    </div>

                    {featured.length > 0 && (
                        <div className="mb-2 grid grid-cols-2 gap-2 px-1">
                            {featured.map((link) => (
                                <Link key={link.href} href={link.href} prefetch={!link.locked && shouldPrefetchPage(link.href)} className="tn-feature" aria-current={isActiveLink(link, url) ? 'page' : undefined} tabIndex={leaving ? -1 : undefined} {...lockedLinkProps(link)}>
                                    <span className="tn-feature-icon"><Icon name={link.icon} /></span>
                                    <span className="relative z-[1]">
                                        <b className="flex items-center gap-1.5 leading-normal">{link.label}{link.locked && <LockMark />}</b>
                                        <small className="block">{link.description}</small>
                                    </span>
                                </Link>
                            ))}
                        </div>
                    )}

                    {links.length === 0 && <p className="px-3 py-4 text-sm text-gray-500">لا توجد صفحات متاحة ضمن صلاحياتك.</p>}
                    <ul className={`grid auto-rows-fr gap-0.5 ${twoColumns ? 'grid-cols-2' : ''}`}>
                        {rest.map((link) => (
                            <li key={link.href}>
                                <Link href={link.href} prefetch={!link.locked && shouldPrefetchPage(link.href)} className="tn-mega-link" aria-current={isActiveLink(link, url) ? 'page' : undefined} tabIndex={leaving ? -1 : undefined} {...lockedLinkProps(link)}>
                                    <span className="tn-tile"><Icon name={link.icon} /></span>
                                    <span className="min-w-0">
                                        <b>{link.label}</b>
                                        <small>{link.description}</small>
                                    </span>
                                    {link.locked
                                        ? <LockMark />
                                        : badges[link.href] > 0
                                            ? <span className="tn-badge">{badges[link.href]}</span>
                                            : <Icon name="chevron-left" className="tn-go" />}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
            <div className="flex items-center gap-2 border-t border-gray-200/70 bg-gray-50/80 px-4 py-2.5 text-[12.5px] leading-normal text-gray-500">
                <span className="inline-flex items-center gap-1.5"><span className="tn-kbd">↑ ↓</span>تنقّل</span>
                <span className="inline-flex items-center gap-1.5"><span className="tn-kbd">Enter</span>فتح</span>
                <span className="inline-flex items-center gap-1.5"><span className="tn-kbd">Esc</span>إغلاق</span>
                <button type="button" onClick={onSearch} tabIndex={leaving ? -1 : undefined} className="ms-auto inline-flex shrink-0 items-center gap-1.5 font-semibold text-gray-700 hover:text-gray-900">
                    <Icon name="search" className="h-4 w-4" />
                    كل الصفحات <span className="tn-kbd" dir="ltr">Ctrl K</span>
                </button>
            </div>
        </div>
    );
}
