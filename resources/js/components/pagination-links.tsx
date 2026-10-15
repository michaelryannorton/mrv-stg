import { classes } from '@/lib/mrv/classes';
import { Link } from '@inertiajs/react';

interface PaginationLinksProps {
    links: { url: string | null; label: string; active: boolean }[];
}

/** Laravel paginator links, as the public site's toggle buttons. Renders nothing when there is only one page (previous, 1, next). */
export default function PaginationLinks({ links }: PaginationLinksProps) {
    if (links.length <= 3) return null;

    return (
        <nav aria-label="Pagination" className="page-links">
            {links.map((link, i) => (
                <Link
                    key={i}
                    href={link.url ?? '#'}
                    className={classes('btn-toggle', link.active && 'active', !link.url && 'disabled')}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    );
}
