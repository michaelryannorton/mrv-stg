import { resolveNav } from '@/components/mrv/navigation';
import { SiteDetail } from '@/components/mrv/route66';
import { useRoute66Data } from '@/components/mrv/use-route66-data';
import PublicLayout from '@/layouts/public-layout';
import { siteTitle } from '@/lib/mrv/route66';
import { Head, Link } from '@inertiajs/react';

// One Route 66 site (production: route66/site.html?id=… with js/site-detail.js). Unknown ids show production's
// "No site found" message; the id is shown as text, never as markup.

const DESCRIPTION = 'Route 66 Centennial site detail — Mojave River Valley High Desert corridor.';

export default function Route66Show({ siteId, preview = false }: { siteId: string; preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const state = useRoute66Data();
    const site = state.status === 'ready' ? state.data.sites.find((s) => s.id === siteId) : undefined;
    const indexHref = route('route66.index');

    return (
        <PublicLayout activeId="route66" section="route66" pages={pages} account={account}>
            <Head title={site ? siteTitle(site) : 'Route 66 site — Mojave River Valley'}>
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <div className="detail-backbar">
                <div className="mrv-container">
                    <Link href={indexHref} className="back-link">
                        &larr; All Route 66 sites
                    </Link>
                </div>
            </div>

            <div className="mrv-container detail-main" id="detail-main">
                {state.status === 'loading' ? <div className="empty-state">Loading…</div> : null}
                {state.status === 'error' ? <div className="empty-state">Could not load site data: {state.message}</div> : null}
                {state.status === 'ready' && !site ? (
                    <div className="empty-state">
                        No site found for &ldquo;{siteId}&rdquo;. <Link href={indexHref}>Back to all sites.</Link>
                    </div>
                ) : null}
                {state.status === 'ready' && site ? <SiteDetail site={site} media={state.data.media} /> : null}
            </div>
        </PublicLayout>
    );
}
