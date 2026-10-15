import { DirectoryHero, mapBoxStyle } from '@/components/mrv/directory-hero';
import { QuakeLegend, QuakeRows } from '@/components/mrv/earthquakes';
import { MarkerMapView } from '@/components/mrv/map';
import { resolveNav } from '@/components/mrv/navigation';
import { StatCards } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import PublicLayout from '@/layouts/public-layout';
import {
    DEFAULT_WINDOW_KEY,
    fetchEvents,
    filteredEvents,
    LOADING_STAT,
    MAG_OPTIONS,
    magRange,
    MIN_MAGNITUDE_DEFAULT,
    quakeList,
    quakePins,
    quakeStats,
    WINDOWS,
    type QuakeEvent,
} from '@/lib/mrv/earthquakes';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

// Earthquake Activity (production: earthquakes.html with js/earthquakes.js): a regional map and a list of recent events.

const DESCRIPTION =
    'Recent and historical earthquake activity across the Mojave River Valley region, drawn from the full USGS catalog for this area.';

export default function EarthquakesIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [windowKey, setWindowKey] = useState(DEFAULT_WINDOW_KEY);
    const [minMagnitude, setMinMagnitude] = useState<number | null>(MIN_MAGNITUDE_DEFAULT);
    // The events of the last successful request stay on screen while the next one loads, as on production.
    const [events, setEvents] = useState<QuakeEvent[] | null>(null);
    const [loading, setLoading] = useState(true);
    // The message of the last failed request, and whether it is what the stats and list show right now (the next drawing of the page replaces it).
    const [error, setError] = useState<{ message: string; id: number } | null>(null);
    const [errorShown, setErrorShown] = useState(false);
    const mapElement = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        let live = true;
        setLoading(true);
        const days = (WINDOWS.find((w) => w.key === windowKey) as (typeof WINDOWS)[number]).days;
        fetchEvents(days).then(
            (rows) => {
                if (!live) return;
                setEvents(rows);
                setErrorShown(false);
                setLoading(false);
            },
            (err: Error) => {
                if (!live) return;
                setError((prev) => ({ message: err.message, id: (prev?.id ?? 0) + 1 }));
                setErrorShown(true);
                setLoading(false);
            },
        );

        return () => {
            live = false;
        };
    }, [windowKey]);

    const shown = useMemo(() => (events ? filteredEvents(events, minMagnitude) : null), [events, minMagnitude]);
    const range = useMemo(() => (shown ? magRange(shown) : { min: null, max: null }), [shown]);
    // "Nm ago" is worked out when the events are drawn, not on every render.
    const stats = useMemo(() => (shown ? quakeStats(shown) : []), [shown]);
    const pins = useMemo(() => (shown ? quakePins(shown, range.min, range.max) : []), [shown, range]);
    const fitPoints = useMemo(() => pins.map((p) => [p.lat, p.lon] as [number, number]), [pins]);
    const list = useMemo(() => (shown ? quakeList(shown) : null), [shown]);
    const failure = error ? `Couldn't load earthquake data right now. ${error.message}` : null;

    // Production writes the failure into the map's own element (the map stays inside it, out of sight).
    useEffect(() => {
        if (error && mapElement.current)
            mapElement.current.innerHTML = `<div class="error-panel">${failure?.replace(/&/g, '&amp;').replace(/</g, '&lt;')}</div>`;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [error?.id]);

    return (
        <PublicLayout activeId="earthquakes" pages={pages} account={account}>
            <Head title="Earthquake Activity — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <DirectoryHero eyebrow="Earthquake Activity" title="What's shaking in the Mojave River Valley">
                Every earthquake USGS has recorded for this region — recent activity and the full historical catalog, not just the ones large enough
                to make the news.
            </DirectoryHero>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>About Seismicity Here</h2>
                    </div>
                    <p style={{ maxWidth: '720px' }}>
                        The Mojave River Valley sits in one of the more seismically active parts of Southern California. The San Andreas Fault runs
                        along the region's southern edge through Cajon Pass, where the San Jacinto Fault Zone — considered the most seismically active
                        fault zone in the immediate area — branches off. The Helendale Fault runs directly through Apple Valley and Victorville.
                        Further north and east, the Lenwood and Lockhart faults are part of the broader Eastern California Shear Zone, the same
                        tectonic system that produced the 1992 Landers and 1999 Hector Mine earthquakes (on other faults within that same zone).
                    </p>
                    <p className="card-note" style={{ maxWidth: '720px', marginTop: '8px' }}>
                        Most of what's recorded below is instrument-detected microseismicity that no one actually feels — modern seismic networks in
                        Southern California are dense enough to catch earthquakes far too small to notice. Use the magnitude filter below to see only
                        the activity most people could plausibly feel.
                    </p>
                    <p className="card-note" style={{ maxWidth: '720px', marginTop: '8px' }}>
                        USGS's catalog for this area also holds quarry blasts, sonic booms and other explosions, recorded as separate event types, and
                        events it later reviewed and deleted. They are kept in our data but not shown here.
                    </p>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Regional Activity Map</h2>
                    </div>

                    <div className="control-row">
                        <span className="control-row-label">Time window:</span>
                        <div id="quake-window-controls" className="map-controls">
                            <ToggleButtons options={WINDOWS} active={windowKey} attribute="window" onChange={setWindowKey} />
                        </div>
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Magnitude:</span>
                        <div id="quake-mag-controls" className="map-controls">
                            <ToggleButtons
                                options={MAG_OPTIONS}
                                active={(MAG_OPTIONS.find((o) => o.value === minMagnitude) as (typeof MAG_OPTIONS)[number]).key}
                                attribute="mag"
                                onChange={(key) => {
                                    setMinMagnitude((MAG_OPTIONS.find((o) => o.key === key) as (typeof MAG_OPTIONS)[number]).value);
                                    setErrorShown(false);
                                }}
                            />
                        </div>
                    </div>

                    <div className="grid-stats grid" id="quake-stats" style={{ margin: '16px 0' }}>
                        {loading ? <StatCards cards={[LOADING_STAT]} /> : errorShown ? null : <StatCards cards={stats} />}
                    </div>

                    <div id="quake-legend">{shown ? <QuakeLegend min={range.min} max={range.max} /> : null}</div>
                    <MarkerMapView
                        id="quake-map"
                        style={mapBoxStyle(520)}
                        markers={pins}
                        fitPoints={fitPoints}
                        fit={{ padding: 30, maxZoom: 11 }}
                        appendOnly
                        refitOnChange
                        elementRef={mapElement}
                    />
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Recent Events</h2>
                    </div>
                    <p className="card-note" id="quake-list-note">
                        {list ? (list.shown.length ? list.note : '') : 'Loading events…'}
                    </p>
                    <div id="quake-list" style={{ marginTop: '12px' }}>
                        {errorShown && failure ? <div className="error-panel">{failure}</div> : null}
                        {!errorShown && list ? (
                            list.shown.length ? (
                                <QuakeRows events={list.shown} min={range.min} max={range.max} />
                            ) : (
                                <p className="card-note">No events match the current window and magnitude filter.</p>
                            )
                        ) : null}
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
