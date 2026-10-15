import { DirectoryHero, mapBoxStyle } from '@/components/mrv/directory-hero';
import { MarkerMapView } from '@/components/mrv/map';
import { resolveNav } from '@/components/mrv/navigation';
import { StationLegend } from '@/components/mrv/stations';
import PublicLayout from '@/layouts/public-layout';
import { allPoints, drawableStations, fetchStations, initialNetworks, stationCountNote, stationPins, type Station } from '@/lib/mrv/stations';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

// Data Explorer (production: stations.html with js/stations.js): every observation station, toggled by network.

const DESCRIPTION =
    "Every weather station, fire-weather RAWS site, and monitoring location feeding this project's data across the Mojave River Valley region.";

type State = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; stations: Station[] };

export default function DataIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [state, setState] = useState<State>({ status: 'loading' });
    const [active, setActive] = useState<Set<string>>(new Set());

    useEffect(() => {
        let live = true;
        fetchStations().then(
            (stations) => {
                if (!live) return;
                setActive(initialNetworks(stations));
                setState({ status: 'ready', stations });
            },
            (err: Error) => live && setState({ status: 'error', message: err.message }),
        );

        return () => {
            live = false;
        };
    }, []);

    const stations = state.status === 'ready' ? state.stations : null;
    const drawn = useMemo(() => (stations ? drawableStations(stations, active) : []), [stations, active]);
    const markers = useMemo(() => stationPins(drawn), [drawn]);
    const fitPoints = useMemo(() => (stations ? allPoints(stations) : []), [stations]);

    const toggle = (network: string) =>
        setActive((current) => {
            const next = new Set(current);
            if (next.has(network)) next.delete(network);
            else next.add(network);

            return next;
        });

    return (
        <PublicLayout activeId="data" pages={pages} account={account}>
            <Head title="Data Explorer — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <DirectoryHero eyebrow="Data Explorer" title="Where our data comes from">
                Every dot on this map is a real weather-observation location feeding this site — airports, remote fire-weather stations, utility
                monitoring sites, and volunteer/community stations across the greater Victor Valley region.
            </DirectoryHero>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Regional Station Map</h2>
                    </div>
                    <p className="card-note">
                        Click any pin for details, or click a legend item to show/hide that station type. Types are best-effort classified from public
                        station identifiers — the source APIs don't label network affiliation directly, so treat this as a strong guess rather than an
                        official designation.
                    </p>
                    <div className="map-legend" id="station-legend">
                        {stations ? <StationLegend stations={stations} active={active} onToggle={toggle} /> : null}
                    </div>
                    {stations ? (
                        <MarkerMapView
                            id="station-map"
                            style={mapBoxStyle(560)}
                            markers={markers}
                            fitPoints={fitPoints}
                            fit={{ padding: 24, maxZoom: 11 }}
                            fitFirst
                        />
                    ) : (
                        <div id="station-map" style={mapBoxStyle(560)}>
                            {state.status === 'error' ? (
                                <div className="error-panel">Couldn't load station data right now. {state.message}</div>
                            ) : null}
                        </div>
                    )}
                    <p className="card-note" id="station-count-note" style={{ marginTop: '10px' }}>
                        {stations ? stationCountNote(drawn.length, stations.length) : ''}
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
