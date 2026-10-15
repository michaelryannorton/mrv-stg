import { DaysBar, DaysLegend } from '@/components/mrv/air-quality';
import MrvChart from '@/components/mrv/chart';
import { cityFromUrl, CitySelect, rememberCity } from '@/components/mrv/city-select';
import { resolveNav } from '@/components/mrv/navigation';
import { type DataStatus } from '@/components/mrv/site-footer';
import { PanelContent } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import PublicLayout from '@/layouts/public-layout';
import {
    aqiConfig,
    aqStats,
    compareConfig,
    daysByCategory,
    DEFAULT_METRIC,
    METRICS,
    MONTH_CHOICES,
    normalizeCompareYears,
    ozoneConfig,
    particulatesConfig,
    toggleCompareYear,
    uvConfig,
    type Panel,
} from '@/lib/mrv/air-quality';
import { computeAqiNormals, getYearsPresent, type ApiRow } from '@/lib/mrv/stats';
import { weatherData, type LoadResult } from '@/lib/mrv/weather-data';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

// Air Quality & UV (production: air-quality.html with js/air-quality.js): US AQI, particulates, ozone and UV index for a
// city, with the health bands drawn behind the charts. The data only go back to August 2022, and the page says so.

const DESCRIPTION = 'Daily AQI, PM2.5/PM10, ozone, and UV index for communities across the Mojave River Valley, since August 2022.';

type Weather =
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'empty' }
    | { status: 'ready'; data: LoadResult; names: string[] };
const METRIC_OPTIONS = METRICS.map(([key, label]) => ({ key, label }));
const MONTH_OPTIONS = MONTH_CHOICES.map(([key, label]) => ({ key, label }));

export default function AirQualityIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [weather, setWeather] = useState<Weather>({ status: 'loading' });
    const [city, setCity] = useState<string | null>(null);
    const [rows, setRows] = useState<ApiRow[] | null>(null);
    const [rowsError, setRowsError] = useState(false);
    const [chosen, setChosen] = useState<Set<number>>(new Set());
    const [metric, setMetric] = useState(DEFAULT_METRIC);
    const [zoomMonth, setZoomMonth] = useState<number | null>(null);
    const request = useRef(0);

    useEffect(() => {
        let live = true;
        weatherData.load().then(
            (data) => {
                if (!live) return;
                const names = weatherData.getCityNames();
                if (!names.length) return setWeather({ status: 'empty' });
                const requested = cityFromUrl();
                setWeather({ status: 'ready', data, names });
                setCity(requested && names.includes(requested) ? requested : names[0]);
            },
            (err: Error) => {
                console.error(err);
                if (live) setWeather({ status: 'error', message: err.message });
            },
        );

        return () => {
            live = false;
        };
    }, []);

    // Choosing a city (and the first one) reads that city's air quality; the page keeps showing the last city's until it arrives.
    useEffect(() => {
        if (!city) return;
        rememberCity(city);
        const mine = ++request.current;
        weatherData.getCityAirQuality<ApiRow[]>(city).then(
            (data) => {
                if (mine !== request.current) return;
                setRowsError(false);
                setRows(data);
                setChosen((prev) => normalizeCompareYears(prev, getYearsPresent(data)));
            },
            (err: Error) => {
                console.warn('renderAirQuality failed', err);
                if (mine === request.current) setRowsError(true);
            },
        );
    }, [city]);

    const normals = useMemo(() => (rows ? computeAqiNormals(rows) : null), [rows]);
    const compare = useMemo(() => (rows ? compareConfig(rows, chosen, metric, zoomMonth) : null), [rows, chosen, metric, zoomMonth]);
    const days = useMemo(() => (rows ? daysByCategory(rows) : null), [rows]);
    // A chart is made again whenever its configuration changes, so each is made once per set of rows.
    const aqi = useMemo(() => (normals ? aqiConfig(normals) : null), [normals]);
    const particulates = useMemo(() => (normals ? particulatesConfig(normals) : null), [normals]);
    const ozone = useMemo(() => (normals ? ozoneConfig(normals) : null), [normals]);
    const uv = useMemo(() => (normals ? uvConfig(normals) : null), [normals]);
    const years = useMemo(() => (rows ? getYearsPresent(rows) : []), [rows]);

    const names = weather.status === 'ready' ? weather.names : [];
    let stats: Panel | null = null;
    if (weather.status === 'loading') stats = { kind: 'loading', text: 'Loading air quality data…' };
    else if (weather.status === 'error') stats = { kind: 'error', text: `Couldn't load weather data right now. ${weather.message}` };
    else if (weather.status === 'empty') stats = { kind: 'error', text: 'No city data found yet.' };
    else if (rowsError) stats = { kind: 'error', text: "Couldn't load this city's air quality data right now." };
    else stats = rows ? aqStats(rows) : { kind: 'loading', text: 'Loading air quality data…' };

    const dataStatus: DataStatus | null =
        weather.status === 'ready'
            ? { state: weather.data.stale ? 'error' : '', message: `Data as of ${weather.data.fetchedAt.slice(0, 10)}` }
            : weather.status === 'error'
              ? { state: 'error', message: 'Data failed to load' }
              : null;

    return (
        <PublicLayout activeId="airquality" pages={pages} account={account} dataStatus={dataStatus}>
            <Head title="Air Quality & UV — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <section className="hero" style={{ padding: '36px 0' }}>
                <div className="mrv-container">
                    <div className="eyebrow">Air Quality &amp; UV</div>
                    <h1 id="aq-title" style={{ fontSize: 'clamp(1.8rem, 3.4vw, 2.6rem)' }}>
                        {city ? `Air Quality & UV — ${city}` : 'Loading…'}
                    </h1>
                    <div className="map-controls" style={{ alignItems: 'center', gap: '14px' }}>
                        <label htmlFor="aq-city-select" style={{ fontSize: '0.9rem', color: 'var(--color-dusk-soft)' }}>
                            City:
                        </label>
                        <CitySelect id="aq-city-select" names={names} value={city ?? ''} onChange={setCity} />
                        <span className="card-note" id="aq-updated">
                            {rows && rows.length ? `${rows.length} days of data` : ''}
                        </span>
                    </div>
                    <p className="card-note" style={{ marginTop: '8px', maxWidth: '640px' }}>
                        <strong>This data only goes back to August 2022</strong> — much shorter than the 2016&ndash;present record everywhere else on
                        this site. Averages and "typical" figures below reflect however many partial years are actually available, not a full decade.
                    </p>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="grid-stats grid" id="aq-stats">
                        <PanelContent panel={stats} />
                    </div>
                    <div className="card" style={{ marginTop: '18px' }}>
                        <h3>Days in Each AQI Category, Past Year</h3>
                        <p className="card-note" id="aq-days-note">
                            {days ? days.note : ''}
                        </p>
                        <div
                            className="aqi-days-bar"
                            id="aq-days-bar"
                            role="img"
                            aria-label="Share of days in each US AQI category over the past year"
                        >
                            {days ? <DaysBar days={days} /> : null}
                        </div>
                        <ul className="aqi-days-legend" id="aq-days-legend">
                            {days ? <DaysLegend days={days} /> : null}
                        </ul>
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Compare Specific Years</h2>
                        <p>Daily values overlaid on the same calendar year &mdash; pick a metric and up to 6 years to compare.</p>
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Metric:</span>
                        <div className="map-controls" id="compare-metric-controls">
                            {rows ? (
                                <ToggleButtons options={METRIC_OPTIONS} active={metric} attribute="metric" onChange={setMetric} typed={false} />
                            ) : null}
                        </div>
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Years:</span>
                        <div className="map-controls" id="compare-year-controls">
                            {rows ? (
                                <ToggleButtons
                                    options={years.map((y) => ({ key: String(y), label: String(y) }))}
                                    active={Array.from(chosen).map(String)}
                                    attribute="year"
                                    onChange={(key) => setChosen((prev) => toggleCompareYear(prev, Number(key)))}
                                    typed={false}
                                />
                            ) : null}
                        </div>
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Zoom to month:</span>
                        <div className="map-controls" id="compare-month-controls">
                            {rows ? (
                                <ToggleButtons
                                    options={MONTH_OPTIONS}
                                    active={zoomMonth ? String(zoomMonth) : ''}
                                    attribute="zoom-month"
                                    onChange={(key) => setZoomMonth(key === '' ? null : Number(key))}
                                    typed={false}
                                />
                            ) : null}
                        </div>
                    </div>
                    <div className="card chart-card">
                        <p className="card-note" id="compare-gap-note">
                            {compare ? compare.gapNote : ''}
                        </p>
                        <div className="chart-tall">
                            <MrvChart id="chart-compare" config={compare ? compare.config : null} />
                        </div>
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>AQI by Month</h2>
                        <p>
                            Average daily peak US AQI by month, across all years combined. Shaded bands are the EPA health categories; each bar is
                            colored by the category its month falls in.
                        </p>
                    </div>
                    <div className="card chart-card">
                        <MrvChart id="chart-aqi" config={aqi} />
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Particulates</h2>
                        <p>Average daily PM2.5 and PM10 by month (&micro;g/m&sup3;).</p>
                    </div>
                    <div className="card chart-card">
                        <MrvChart id="chart-particulates" config={particulates} />
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Ozone &amp; UV Index</h2>
                        <p>Ground-level ozone (&micro;g/m&sup3;) and peak daily UV index by month. UV bands are the WHO exposure categories.</p>
                    </div>
                    <div className="grid-charts grid">
                        <div className="card chart-card">
                            <h3>Ozone</h3>
                            <MrvChart id="chart-ozone" config={ozone} />
                        </div>
                        <div className="card chart-card">
                            <h3>UV Index</h3>
                            <MrvChart id="chart-uv" config={uv} />
                        </div>
                    </div>
                    <p className="card-note" style={{ marginTop: '10px' }}>
                        Modeled data (Open-Meteo), not a physical monitor at your location — see{' '}
                        <a href="docs/data-sources/open-meteo.md">the data spec</a> for resolution and limitations.
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
