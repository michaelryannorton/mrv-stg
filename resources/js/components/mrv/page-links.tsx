import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { pageById, type NavItem } from './navigation';
import { pageHref } from './site-header';

/**
 * A link to a page by its id in the navigation (components/mrv/navigation.ts). A page that is not ported yet is not
 * linked: `soon` renders in its place, so a body link turns on by itself when the page's `ready` flag does.
 */
export function PageLink({
    pages,
    id,
    className,
    children,
    soon,
}: {
    pages: NavItem[];
    id: string;
    className?: string;
    children: ReactNode;
    soon: ReactNode;
}) {
    const page = pageById(pages, id);
    if (!page || !page.ready) return <>{soon}</>;

    return (
        <Link href={pageHref(page)} className={className}>
            {children}
        </Link>
    );
}
