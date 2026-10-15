import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { isGroup, navGroupClass, SITE_NAV, type NavItem, type NavPage } from './navigation';

/** The address of a ready page: its Ziggy route, or a literal href (previews only). */
export const pageHref = (page: NavPage): string => (page.route ? route(page.route) : (page.href ?? '#'));

function NavLink({ page, activeId }: { page: NavPage; activeId?: string }) {
    if (!page.ready) {
        return <span className="nav-soon">{page.label}</span>;
    }

    return (
        <Link href={pageHref(page)} className={page.id === activeId ? 'active' : undefined}>
            {page.label}
        </Link>
    );
}

interface SiteHeaderProps {
    /** Defaults to the real navigation; the component sheet passes production's. */
    pages?: NavItem[];
    /** The id of the current page, highlighted in the navigation. */
    activeId?: string;
    /** Show the sign-in or dashboard link at the end (not on production's header). */
    account?: boolean;
}

/**
 * The sticky header. Same markup and behavior as production's public_html/js/nav.js: dropdown groups open on
 * hover through CSS and on click or tap here (one open at a time, `aria-expanded` kept in step), and close
 * when anything else is clicked.
 */
export default function SiteHeader({ pages = SITE_NAV, activeId, account = true }: SiteHeaderProps) {
    const { auth, public_channels: hasChannels } = usePage<SharedData>().props;
    const [openId, setOpenId] = useState<string | null>(null);

    useEffect(() => {
        const close = () => setOpenId(null);
        document.addEventListener('click', close);

        return () => document.removeEventListener('click', close);
    }, []);

    const visible = (page: NavPage) => !page.footerOnly && (page.requires !== 'public_channels' || hasChannels);

    return (
        <header id="site-header" className="site-header">
            <div className="mrv-container">
                <Link href={route('home')} className="site-wordmark">
                    <span>
                        Mojave River <span className="wordmark-accent">Valley</span>
                    </span>
                </Link>

                <nav className="site-nav">
                    {pages.map((item) => {
                        if (!isGroup(item)) {
                            return visible(item) ? <NavLink key={item.id} page={item} activeId={activeId} /> : null;
                        }

                        const open = openId === item.id;
                        const children = item.children.filter(visible);
                        const hasActiveChild = children.some((child) => child.id === activeId);

                        return (
                            <div key={item.id} className={navGroupClass(hasActiveChild, open)}>
                                <button
                                    type="button"
                                    className="nav-group-toggle"
                                    aria-expanded={open}
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        setOpenId(open ? null : item.id);
                                    }}
                                >
                                    {item.label}
                                    <span className="nav-caret" aria-hidden="true"></span>
                                </button>
                                <div className="nav-dropdown">
                                    {children.map((child) => (
                                        <NavLink key={child.id} page={child} activeId={activeId} />
                                    ))}
                                </div>
                            </div>
                        );
                    })}

                    {account &&
                        (auth.user ? (
                            <Link href={route('dashboard')}>Dashboard</Link>
                        ) : (
                            <Link href={route('login')} className={activeId === 'login' ? 'active' : undefined}>
                                Sign in
                            </Link>
                        ))}
                </nav>
            </div>
        </header>
    );
}
