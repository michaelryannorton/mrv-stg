import { ArtCards, ArtLegend, ArtTable, ArtViewTabs } from '@/components/mrv/art';
import { DirectoryHero, mapBoxStyle } from '@/components/mrv/directory-hero';
import { MarkerMapView } from '@/components/mrv/map';
import { resolveNav } from '@/components/mrv/navigation';
import PublicLayout from '@/layouts/public-layout';
import { artCountNote, artPins, fetchArt, type ArtEntry, type ArtView } from '@/lib/mrv/art';
import { classes } from '@/lib/mrv/classes';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

// Public Art directory (production: art.html with js/art.js). The entries are PLACEHOLDER data (see lib/mrv/art.ts).

const DESCRIPTION = 'Murals, sculptures, and public art installations across the Mojave River Valley region.';

export default function ArtIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [art, setArt] = useState<ArtEntry[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [view, setView] = useState<ArtView>('card');
    // Production fills a view the first time it is shown and leaves it in place (hidden) afterwards.
    const [shown, setShown] = useState<Set<ArtView>>(new Set(['card']));

    useEffect(() => {
        let live = true;
        fetchArt().then(
            (entries) => live && setArt(entries),
            (err: Error) => live && setError(err.message),
        );

        return () => {
            live = false;
        };
    }, []);

    const pick = (next: ArtView) => {
        setView(next);
        setShown((current) => new Set(current).add(next));
    };

    const markers = useMemo(() => (art ? artPins(art) : []), [art]);
    const fitPoints = useMemo(() => markers.map((m) => [m.lat, m.lon] as [number, number]), [markers]);
    const hidden = (v: ArtView) => !(v === view && (art || error));

    return (
        <PublicLayout activeId="art" pages={pages} account={account}>
            <Head title="Public Art — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <DirectoryHero eyebrow="Public Art Directory" title="Murals, sculpture, and installations across the valley">
                A growing directory of public art across the Mojave River Valley region &mdash; murals, sculptures, mosaics, and installations worth
                seeking out.
            </DirectoryHero>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Browse the Directory</h2>
                    </div>

                    <p className="card-note" id="art-count-note">
                        {art ? artCountNote(art.length) : 'Loading…'}
                    </p>

                    <div className="control-row">
                        <span className="control-row-label">View:</span>
                        <div id="art-view-tabs" className="map-controls">
                            {art ? <ArtViewTabs view={view} onChange={pick} /> : null}
                        </div>
                    </div>

                    <div id="art-card-view" className={classes('grid-markets grid', hidden('card') && 'hidden')} style={{ marginTop: '16px' }}>
                        {error ? <div className="error-panel">Couldn't load public art data right now. {error}</div> : null}
                        {art && shown.has('card') ? <ArtCards art={art} /> : null}
                    </div>

                    <div id="art-table-view" className={classes(hidden('table') && 'hidden')} style={{ marginTop: '16px' }}>
                        {art && shown.has('table') ? <ArtTable art={art} /> : null}
                    </div>

                    <div id="art-map-view" className={classes(hidden('map') && 'hidden')} style={{ marginTop: '16px' }}>
                        <div id="art-legend" className="map-legend">
                            {art && shown.has('map') ? <ArtLegend art={art} /> : null}
                        </div>
                        {art && shown.has('map') ? (
                            <MarkerMapView
                                id="art-map"
                                style={mapBoxStyle(520)}
                                markers={markers}
                                fitPoints={fitPoints}
                                fit={{ padding: 30, maxZoom: 11 }}
                                refitOnShow
                                active={view === 'map'}
                            />
                        ) : (
                            <div id="art-map" style={mapBoxStyle(520)}></div>
                        )}
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
