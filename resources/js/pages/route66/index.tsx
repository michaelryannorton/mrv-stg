import { resolveNav } from '@/components/mrv/navigation';
import { CardGrid, FilterChips, progressNoteText, resultCountText } from '@/components/mrv/route66';
import { useRoute66Data } from '@/components/mrv/use-route66-data';
import PublicLayout from '@/layouts/public-layout';
import { buildCardData } from '@/lib/mrv/route66';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';

// Route 66 Centennial, card view (production: route66/index.html with js/cards.js).

const DESCRIPTION = 'Route 66 Centennial card mockup — High Desert sites with a public-domain or Creative Commons photo available.';

export default function Route66Index({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const state = useRoute66Data();
    const [activeTier, setActiveTier] = useState<string | null>(null);
    const cards = useMemo(() => (state.status === 'ready' ? buildCardData(state.data.sites, state.data.media) : []), [state]);
    const detailHref = (id: string) => route('route66.show', { site: id });

    return (
        <PublicLayout activeId="route66" section="route66" pages={pages} account={account}>
            <Head title="Route 66 Centennial — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <section className="hero">
                <div className="mrv-container">
                    <div className="eyebrow">Route 66 Centennial 2026</div>
                    <h1>High Desert Route 66, Site by Site</h1>
                    <p className="lede">
                        A first look at the card view for the Route 66 project. This mockup shows only the sites we currently have a downloaded,
                        license-checked photo for &mdash; the rest of the top 100 will appear here as photo research fills in.
                    </p>
                    <span className="mockup-note" id="progress-note">
                        {state.status === 'loading'
                            ? 'Loading…'
                            : state.status === 'error'
                              ? 'Could not load site data.'
                              : progressNoteText(cards, state.data.sites.length)}
                    </span>
                </div>
            </section>

            <div className="filter-bar">
                <div className="mrv-container">
                    <div className="filter-row" id="filter-row">
                        {state.status === 'ready' ? <FilterChips cards={cards} activeTier={activeTier} onSelect={setActiveTier} /> : null}
                    </div>
                </div>
            </div>

            <div className="mrv-container">
                <div className="result-count" id="result-count" style={{ paddingTop: '14px' }}>
                    {state.status === 'ready' ? resultCountText(cards, activeTier) : ''}
                </div>
                <div className="card-grid" id="card-grid">
                    {state.status === 'loading' ? <div className="empty-state">Loading sites…</div> : null}
                    {state.status === 'error' ? <div className="empty-state">Could not load site data: {state.message}</div> : null}
                    {state.status === 'ready' ? <CardGrid cards={cards} activeTier={activeTier} detailHref={detailHref} /> : null}
                </div>
            </div>
        </PublicLayout>
    );
}
