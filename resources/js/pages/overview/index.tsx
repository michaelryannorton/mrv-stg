import { DroughtBody } from '@/components/mrv/drought';
import { RegionMapView } from '@/components/mrv/map';
import { pageById, resolveNav } from '@/components/mrv/navigation';
import { MetricLegend } from '@/components/mrv/overview';
import { type DataStatus } from '@/components/mrv/site-footer';
import { pageHref } from '@/components/mrv/site-header';
import { StatCards } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import PublicLayout from '@/layouts/public-layout';
import { fetchDrought, type DroughtRow } from '@/lib/mrv/drought';
import { type MapPoint } from '@/lib/mrv/leaflet-map';
import {
    cityPopupHtml,
    computeCityStats,
    dataStatusMessage,
    DEFAULT_METRIC,
    legendSteps,
    METRICS,
    metricValues,
    regionStats,
} from '@/lib/mrv/overview';
import { weatherData, type LoadResult } from '@/lib/mrv/weather-data';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

// Geography Overview (production: overview.html with js/overview.js and js/drought.js): the region map by metric, the
// region's records, and the drought status. The weather data come from the shared data layer (lib/mrv/weather-data.ts).

const DESCRIPTION =
    'Ten years of daily weather data for communities across the Mojave River Valley — interactive maps, charts, and climate insights.';

type Weather = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; data: LoadResult };
type Drought = { status: 'loading' } | { status: 'message'; text: string } | { status: 'ready'; rows: DroughtRow[] };

const METRIC_OPTIONS = Object.entries(METRICS).map(([key, m]) => ({ key, label: m.label }));

export default function OverviewIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [weather, setWeather] = useState<Weather>({ status: 'loading' });
    const [drought, setDrought] = useState<Drought>({ status: 'loading' });
    const [metric, setMetric] = useState(DEFAULT_METRIC);
    const [scale, setScale] = useState<{ min: number | null; max: number | null }>({ min: null, max: null });

    useEffect(() => {
        let live = true;
        weatherData.load().then(
            (data) => live && setWeather({ status: 'ready', data }),
            (err: Error) => {
                console.error(err);
                if (live) setWeather({ status: 'error', message: err.message });
            },
        );
        fetchDrought().then(
            (rows) => {
                if (!live) return;
                setDrought(rows.length ? { status: 'ready', rows } : { status: 'message', text: 'Drought data has not been loaded yet.' });
            },
            (err: Error) => {
                console.error('Drought fetch failed:', err);
                if (live) setDrought({ status: 'message', text: 'Drought data could not be loaded right now.' });
            },
        );

        return () => {
            live = false;
        };
    }, []);

    const computed = useMemo(() => (weather.status === 'ready' ? computeCityStats(weather.data.cities) : null), [weather]);
    const points = useMemo<MapPoint[]>(
        () => (computed ? computed.names.map((name) => ({ name, lat: computed.stats[name].lat, lon: computed.stats[name].lon })) : []),
        [computed],
    );
    const values = useMemo(() => (computed ? metricValues(metric, computed.names, computed.stats) : {}), [computed, metric]);
    const summary = useMemo(() => (computed ? regionStats(computed.names, computed.stats) : null), [computed]);

    // The popup links to the City Explorer once that page exists (production's link is `city.html?city=`).
    const cityPage = pageById(pages, 'city');
    const formatPopup = useCallback(
        (point: MapPoint, value: number | null | undefined) => {
            const href = cityPage?.ready ? `${pageHref(cityPage)}?city=${encodeURIComponent(point.name)}` : null;

            return cityPopupHtml(point.name, value, metric, (computed as NonNullable<typeof computed>).stats[point.name], href);
        },
        [computed, metric, cityPage],
    );

    const legend = legendSteps(scale.min, scale.max, metric);
    const noCities = computed !== null && computed.names.length === 0;
    // Production stops before it sets the footer's line when there are no cities.
    const dataStatus: DataStatus | null =
        weather.status === 'ready' && !noCities
            ? {
                  state: weather.data.stale ? 'error' : '',
                  message: dataStatusMessage(weather.data.fetchedAt, weather.data.fromCache, weather.data.stale),
              }
            : weather.status === 'error'
              ? { state: 'error', message: 'Data failed to load' }
              : null;

    return (
        <PublicLayout activeId="overview" pages={pages} account={account} dataStatus={dataStatus}>
            <Head title="Geography Overview — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <section className="hero">
                <div className="mrv-container">
                    <div className="eyebrow">Geography &middot; Weather &amp; Climate</div>
                    <h1>Ten years of daily weather, mapped across the Mojave River Valley</h1>
                    <p className="lede">
                        From Victorville to Big Bear, explore a decade of temperature, rainfall, and wind data for the communities that make up the
                        region — built for locals, visitors, gardeners, and climate watchers alike.
                    </p>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Region Map</h2>
                        <p>Click a city for a quick climate snapshot, or choose a metric below.</p>
                    </div>
                    <div className="map-controls" id="map-metric-controls">
                        {computed && !noCities ? (
                            <ToggleButtons options={METRIC_OPTIONS} active={metric} attribute="metric" onChange={setMetric} typed={false} />
                        ) : null}
                    </div>
                    {computed && !noCities ? (
                        <RegionMapView id="map" points={points} values={values} formatPopup={formatPopup} onScale={setScale} />
                    ) : (
                        <div id="map">
                            {weather.status === 'error' ? (
                                <div className="error-panel">Couldn't load weather data right now. {weather.message}</div>
                            ) : null}
                            {noCities ? <div className="error-panel">No city data found in the workbook yet.</div> : null}
                        </div>
                    )}
                    <div className="map-legend" id="map-legend">
                        {legend ? <MetricLegend legend={legend} /> : null}
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>The Region, By the Numbers</h2>
                        <p id="region-summary-note">{summary && !noCities ? summary.note : ''}</p>
                    </div>
                    <div className="grid-stats grid" id="region-stats">
                        {summary && !noCities ? <StatCards cards={summary.cards} /> : null}
                        {!summary || noCities ? <div className="loading-panel">Crunching a decade of daily records&hellip;</div> : null}
                    </div>
                </div>
            </section>

            <section id="drought-section">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Drought Status</h2>
                        <p>How much of San Bernardino County is in drought, week by week since 2000, from the U.S. Drought Monitor.</p>
                    </div>
                    <div
                        className={drought.status === 'message' ? 'error-panel' : 'loading-panel'}
                        data-drought-loading=""
                        hidden={drought.status === 'ready'}
                    >
                        {drought.status === 'message' ? drought.text : 'Loading drought data…'}
                    </div>
                    <div data-drought-body="" hidden={drought.status !== 'ready'}>
                        <DroughtBody rows={drought.status === 'ready' ? drought.rows : null} />
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
