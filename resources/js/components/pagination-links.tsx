import { Link } from '@inertiajs/react';

interface PaginationLinksProps {
    links: { url: string | null; label: string; active: boolean }[];
}

/** Laravel paginator links. Renders nothing when there is only one page (previous, 1, next). */
export default function PaginationLinks({ links }: PaginationLinksProps) {
    if (links.length <= 3) return null;

    return (
        <nav aria-label="Pagination" className="mt-8 flex flex-wrap gap-2">
            {links.map((link, i) => (
                <Link
                    key={i}
                    href={link.url ?? '#'}
                    className={`rounded-sm border px-3 py-1 text-sm ${
                        link.active
                            ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:border-[#EDEDEC] dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                            : 'border-[#e3e3e0] dark:border-[#3E3E3A]'
                    } ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    );
}
