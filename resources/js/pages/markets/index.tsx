import { DirectoryHero, mapBoxStyle } from '@/components/mrv/directory-hero';
import { MarkerMapView } from '@/components/mrv/map';
import { MarketCard, MarketLegend } from '@/components/mrv/markets';
import { resolveNav } from '@/components/mrv/navigation';
import PublicLayout from '@/layouts/public-layout';
import { fetchMarkets, marketCountNote, marketPins, sortMarkets, type Market } from '@/lib/mrv/markets';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

// Farmers Market Directory (production: markets.html with js/markets.js): a regional map and a card grid.

const DESCRIPTION =
    "Every farmers market and community market we've researched across the Mojave River Valley region — schedules, seasons, products, and how to find them.";

type State = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; markets: Market[] };

export default function MarketsIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [state, setState] = useState<State>({ status: 'loading' });

    useEffect(() => {
        let live = true;
        fetchMarkets().then(
            (markets) => live && setState({ status: 'ready', markets: sortMarkets(markets) }),
            (err: Error) => live && setState({ status: 'error', message: err.message }),
        );

        return () => {
            live = false;
        };
    }, []);

    const markets = state.status === 'ready' ? state.markets : null;
    const pins = useMemo(() => (markets ? marketPins(markets) : []), [markets]);
    const fitPoints = useMemo(() => pins.map((p) => [p.lat, p.lon] as [number, number]), [pins]);
    const errorPanel = (text: string) => <div className="error-panel">{text}</div>;

    return (
        <PublicLayout activeId="markets" pages={pages} account={account}>
            <Head title="Farmers Markets — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <DirectoryHero eyebrow="Farmers Market Directory" title="Which market, when, and why">
                Every recurring farmers and community market we've researched across the Victor Valley, Barstow area, San Bernardino Mountains, and
                Antelope Valley — with real schedules, not just a list of names.
            </DirectoryHero>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Regional Market Map</h2>
                    </div>
                    <p className="card-note">Click any pin for its schedule at a glance. Colors group markets by region.</p>
                    <div className="map-legend" id="market-legend">
                        {markets ? <MarketLegend markets={markets} /> : null}
                    </div>
                    {state.status === 'ready' && pins.length > 0 ? (
                        <MarkerMapView
                            id="market-map"
                            style={mapBoxStyle(520)}
                            markers={pins}
                            fitPoints={fitPoints}
                            fit={{ padding: 30, maxZoom: 11 }}
                            appendOnly
                        />
                    ) : (
                        <div id="market-map" style={mapBoxStyle(520)}>
                            {state.status === 'error' ? errorPanel(`Couldn't load farmers market data right now. ${state.message}`) : null}
                            {state.status === 'ready' ? errorPanel('No market locations available yet.') : null}
                        </div>
                    )}
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>All Markets</h2>
                    </div>
                    <p className="card-note" id="market-count-note">
                        {state.status === 'loading' ? 'Loading markets…' : markets ? marketCountNote(markets) : ''}
                    </p>
                    <div className="grid-markets grid" id="market-grid" style={{ marginTop: '16px' }}>
                        {state.status === 'error' ? errorPanel(`Couldn't load farmers market data right now. ${state.message}`) : null}
                        {markets ? markets.map((m, i) => <MarketCard key={m.id ?? i} market={m} />) : null}
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
