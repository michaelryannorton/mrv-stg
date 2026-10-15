import { SITE_NAV, type NavItem } from '@/components/mrv/navigation';
import SiteFooter, { type DataStatus } from '@/components/mrv/site-footer';
import SiteHeader from '@/components/mrv/site-header';
import { Head } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

export type Season = 'winter' | 'spring' | 'summer' | 'fall';

interface PublicLayoutProps {
    /** The id of the current page in the navigation (components/mrv/navigation.ts). */
    activeId?: string;
    /** Sets the seasonal color theme (production: City Explorer's season buttons). Unset is the native summer-desert palette. */
    season?: Season;
    dataStatus?: DataStatus | null;
    /** `route66` adds the Route 66 section's stylesheet (resources/css/mrv-route66.css), which production loads on those pages only. */
    section?: 'route66';
    /** Component sheet only: production's navigation without the account link. */
    pages?: NavItem[];
    account?: boolean;
}

/**
 * The layout of every public page: the production design system (resources/css/mrv.css, scoped to `.mrv-site`),
 * the sticky header, the page content, the footer. Admin, curator, settings and sign-in pages do not use it.
 * Page content goes straight between header and footer, as on production; a page brings its own sections.
 */
export default function PublicLayout({
    children,
    activeId,
    season,
    dataStatus,
    section,
    pages = SITE_NAV,
    account = true,
}: PropsWithChildren<PublicLayoutProps>) {
    return (
        <div className={`mrv-site min-h-screen${section ? ` mrv-${section}` : ''}`} data-season={season}>
            <Head>
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
                <link
                    rel="stylesheet"
                    href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap"
                />
            </Head>

            <SiteHeader pages={pages} activeId={activeId} account={account} />
            {/* `contents`: production has no <main> box, and a sticky bar (Route 66's filter bar) must stay stuck down to the footer, which only works when its containing block is the wrapper, not <main>. */}
            <main className="contents">{children}</main>
            <SiteFooter pages={pages} activeId={activeId} dataStatus={dataStatus} />
        </div>
    );
}
