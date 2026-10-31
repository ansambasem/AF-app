import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { shouldPrefetchPage, HOME_LINK, isActiveLink, sectionLinks } from '@/lib/navigation';

/** Where the page sits: home, its section, then the page. */
export function Breadcrumb({ trail }) {
    if (!trail) {
        return null;
    }

    const { group, link, exact } = trail;

    return (
        <nav className="tn-trail tn-rise mb-3 flex items-center gap-1.5 text-[13.5px] text-gray-500" aria-label="مسار الصفحة">
            <Link href={HOME_LINK.href} prefetch={shouldPrefetchPage(HOME_LINK.href)} className="hover:text-gray-900">{HOME_LINK.label}</Link>
            {group && (
                <>
                    <Icon name="chevron-left" className="h-3.5 w-3.5" />
                    <span>{group.label}</span>
                </>
            )}
            <Icon name="chevron-left" className="h-3.5 w-3.5" />
            {exact
                ? <span className="font-semibold text-gray-800" aria-current="page">{link.label}</span>
                : <Link href={link.href} prefetch={shouldPrefetchPage(link.href)} className="font-semibold text-gray-800 hover:text-gray-900">{link.label}</Link>}
        </nav>
    );
}

/**
 * Which ends of a scrolling strip hide more of it: 'start', 'end', 'both'
 * or null. In right-to-left text the start is on the right and scrollLeft
 * runs from 0 down to minus the overflow.
 */
export function hiddenEnds(scrollLeft, scrollWidth, clientWidth) {
    const overflow = scrollWidth - clientWidth;
    const scrolled = Math.abs(scrollLeft);
    const start = scrolled > 1;
    const end = overflow - scrolled > 1;

    return start && end ? 'both' : start ? 'start' : end ? 'end' : null;
}

/**
 * The section's other pages, one click away, the current one dark and
 * centred in view; an edge that hides more pages fades, so a phone shows
 * the strip scrolls.
 */
export function SectionPages({ trail, url, badges }) {
    const stripRef = useRef(null);
    const [fade, setFade] = useState(null);
    const links = sectionLinks(trail?.group);

    useEffect(() => {
        const strip = stripRef.current;
        if (!strip) {
            return undefined;
        }

        const measure = () => setFade(hiddenEnds(strip.scrollLeft, strip.scrollWidth, strip.clientWidth));
        measure();
        strip.addEventListener('scroll', measure, { passive: true });
        window.addEventListener('resize', measure);

        return () => {
            strip.removeEventListener('scroll', measure);
            window.removeEventListener('resize', measure);
        };
    }, [url, links.length]);

    useEffect(() => {
        function centre() {
            const strip = stripRef.current;
            const currentLink = strip?.querySelector('[aria-current="page"]');

            if (strip && currentLink) {
                strip.scrollLeft += currentLink.getBoundingClientRect().left + currentLink.offsetWidth / 2 - (strip.getBoundingClientRect().left + strip.clientWidth / 2);
            }
        }

        const frame = requestAnimationFrame(centre);
        const timer = setTimeout(centre, 120);

        return () => {
            cancelAnimationFrame(frame);
            clearTimeout(timer);
        };
    }, [url]);

    if (links.length === 0) {
        return null;
    }

    return (
        <div
            ref={stripRef}
            data-tour="subnav"
            data-fade={fade ?? undefined}
            role="navigation"
            aria-label={`صفحات ${trail.group.label}`}
            className="tn-subnav tn-rise mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-gray-200/70 bg-surface/70 p-1.5 shadow-card backdrop-blur [scrollbar-width:none]"
        >
            {links.map((link) => (
                <Link key={link.href} href={link.href} prefetch={shouldPrefetchPage(link.href)} aria-current={isActiveLink(link, url) ? 'page' : undefined}>
                    <Icon name={link.icon} />
                    {link.label}
                    {badges[link.href] > 0 && <span className="tn-badge">{badges[link.href]}</span>}
                </Link>
            ))}
        </div>
    );
}
