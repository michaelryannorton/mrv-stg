import MrvChart from '@/components/mrv/chart';
import { DateRangeInput, LiveConditions, StateButtons, YoyNote } from '@/components/mrv/city';
import { cityFromUrl, CitySelect } from '@/components/mrv/city-select';
import { MiniMapView } from '@/components/mrv/map';
import { resolveNav } from '@/components/mrv/navigation';
import { type DataStatus } from '@/components/mrv/site-footer';
import { PanelContent, StatCards } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import PublicLayout from '@/layouts/public-layout';
import {
    annualTrendChart,
    boundsOf,
    buildSearch,
    chooseDailyMonth,
    chooseDailyPreset,
    chooseDailySeason,
    chooseDailyYear,
    cityStatCards,
    commitPickerRange,
    conditionsChart,
    dailyChart,
    dailyControls,
    daylightChart,
    ENRICHMENT_FAILED,
    ENRICHMENT_LOADING,
    enterCity,
    findNearestCity,
    geoNote,
    isoDate,
    loadObservedSnow,
    MODELED_SNOW_NOTE,
    normalsChart,
    normalsNote,
    OBSERVED_SNOW_CITY,
    OBSERVED_SNOW_NOTE,
    observedSnowCards,
    OVERLAY_METRICS,
    overlayChart,
    precipChart,
    setOverlayMetric,
    setOverlayZoom,
    skyGroupsChart,
    snowChart,
    snowSeasonsChart,
    solarRadiationChart,
    stateFromSearch,
    stepDaily,
    toggleDailyOption,
    toggleOverlayYear,
    windChart,
    windRoseChart,
    ZOOM_BUTTONS,
    type CityState,
    type ObservedSnow,
    type OverlayMetric,
} from '@/lib/mrv/city';
import { fetchLatest, liveCard, type LiveCardState } from '@/lib/mrv/live';
import { computeSnowNormals, computeSolarNormals, computeWindRose, excludeYear, getYearsPresent, type ApiRow, type DayRow } from '@/lib/mrv/stats';
import { weatherData, type LoadResult } from '@/lib/mrv/weather-data';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

// City Explorer (production: city.html with js/city.js and js/live.js): a city's ten years of daily weather, as stat tiles and
// thirteen charts, with a shareable address (`city`, `range`, `years`, `metric`, `zoom`, `avg`, `compare`).

const DESCRIPTION =
    'Explore ten years of daily temperature, precipitation, wind, and weather-condition data for communities across the Mojave River Valley.';
const mdash = '—';

type Weather =
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'empty' }
    | { status: 'ready'; data: LoadResult; names: string[] };

type Enrichment = { status: 'idle' | 'loading' | 'ready' | 'error'; rows: ApiRow[] | null };

const rowsOf = (name: string): DayRow[] => excludeYear((weatherData.getCity(name)?.rows ?? []) as unknown as DayRow[], 2016);

export default function CityIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [weather, setWeather] = useState<Weather>({ status: 'loading' });
    const [city, setCity] = useState<string | null>(null);
    const [cs, setCs] = useState<CityState | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const [geo, setGeo] = useState('');
    const [enrichment, setEnrichment] = useState<Enrichment>({ status: 'idle', rows: null });
    const [observed, setObserved] = useState<ObservedSnow | null>(null);
    // Production hides the observed-snow section when another city is chosen but leaves the cards it wrote in it.
    const [shownObserved, setShownObserved] = useState<ObservedSnow | null>(null);
    const [live, setLive] = useState<LiveCardState>({ status: 'idle' });
    const userChangedCity = useRef(false);
    const request = useRef(0);
    const liveRequest = useRef(0);

    // Show a city: its rows set the page's state (production: renderCity).
    function show(name: string, state: CityState) {
        try {
            setCs(enterCity(state, rowsOf(name)));
            setCity(name);
            setFailure(null);
        } catch (err) {
            console.error(err);
            setCity(name);
            setFailure((err as Error).message);
        }
    }

    useEffect(() => {
        let alive = true;
        weatherData.load().then(
            (data) => {
                if (!alive) return;
                const names = weatherData.getCityNames();
                if (!names.length) return setWeather({ status: 'empty' });
                const requested = cityFromUrl();
                const initial = requested && names.includes(requested) ? requested : names[0];
                setWeather({ status: 'ready', data, names });
                show(initial, stateFromSearch(window.location.search));

                // Render the default city at once, then move to the nearest one if the browser answers (never after a choice).
                if (!requested && navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        (pos) => {
                            if (!alive || userChangedCity.current) return;
                            const nearest = findNearestCity(pos.coords.latitude, pos.coords.longitude, names, (n) => weatherData.getCity(n));
                            if (!nearest) return;
                            setGeo(geoNote(nearest.name, nearest.distanceMiles));
                            if (nearest.name !== initial) setCity(nearest.name);
                        },
                        () => {
                            /* denied, unavailable, or timed out: silently keep the default */
                        },
                        { timeout: 4000, maximumAge: 600000 },
                    );
                }
            },
            (err: Error) => {
                console.error(err);
                if (alive) setWeather({ status: 'error', message: err.message });
            },
        );

        return () => {
            alive = false;
        };
    }, []);

    const ready = weather.status === 'ready' && city !== null && cs !== null && failure === null;
    const rows = useMemo(() => (ready ? rowsOf(city as string) : null), [ready, city]);
    const cityData = weather.status === 'ready' && city ? weatherData.getCity(city) : undefined;
    const bounds = useMemo(() => (rows && rows.length ? boundsOf(rows) : null), [rows]);

    // A city chosen by position keeps the page's view and re-enters with its rows (the same as choosing it from the selector).
    const entered = useRef<string | null>(null);
    useEffect(() => {
        if (weather.status !== 'ready' || !city || !cs || entered.current === null) {
            if (city) entered.current = city;
            return;
        }
        if (entered.current !== city) {
            entered.current = city;
            show(city, cs);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [city]);

    // The address follows the state (production: syncURL, history.replaceState with no entries).
    useEffect(() => {
        if (!ready || !city || !cs || !bounds) return;
        const search = buildSearch(window.location.search, city, cs, bounds);
        window.history.replaceState(window.history.state, '', `${window.location.pathname}?${search}`);
    }, [ready, city, cs, bounds]);

    // The city's full-column rows: the sun, solar, wind and snow charts.
    useEffect(() => {
        if (!ready || !city) return;
        const mine = ++request.current;
        const name = city;
        setEnrichment((prev) => ({ status: 'loading', rows: prev.rows }));
        weatherData.getCityEnrichment<ApiRow[]>(name).then(
            (raw) => {
                if (mine !== request.current) return;
                setEnrichment({ status: 'ready', rows: excludeYear(raw, 2016) });
                setObserved(null);
                if (name === OBSERVED_SNOW_CITY) {
                    // The modeled chart never waits for the observed data; if they arrive, the chart is redrawn with them.
                    loadObservedSnow()
                        .then((data) => {
                            if (mine !== request.current) return;
                            setObserved(data);
                            setShownObserved(data);
                        })
                        .catch((err) => console.warn('observed snow unavailable', err));
                }
            },
            (err: Error) => {
                console.warn('renderEnrichmentCharts failed', err);
                if (mine === request.current) setEnrichment((prev) => ({ status: 'error', rows: prev.rows }));
            },
        );
    }, [ready, city]);

    // The "Right Now" card.
    useEffect(() => {
        if (!ready || !city) return;
        const id = weatherData.getCity(city)?.id;
        if (!id) return;
        const mine = ++liveRequest.current;
        setLive({ status: 'loading' });
        fetchLatest(id).then(
            (latest) => {
                if (mine !== liveRequest.current) return;
                const card = liveCard(latest);
                setLive(card ? { status: 'card', card } : { status: 'idle' });
            },
            (err) => {
                console.warn('LiveConditions: failed to load', err);
                if (mine === liveRequest.current) setLive({ status: 'idle' });
            },
        );
    }, [ready, city]);

    // ---- what the page shows ------------------------------------------------------------------------------------------------
    const dailyTick = cs?.dailyTick;
    const overlayTick = cs?.overlayTick;
    const stats = useMemo(() => (rows && rows.length ? cityStatCards(rows) : null), [rows]);
    const notes = useMemo(() => (rows && rows.length ? normalsNote(rows) : ''), [rows]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    const daily = useMemo(() => (rows && cs && cs.range ? dailyChart(rows, cs) : null), [rows, dailyTick]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    const overlay = useMemo(() => (rows && cs && cs.range ? overlayChart(rows, cs) : null), [rows, overlayTick]);
    const annual = useMemo(() => (rows && rows.length ? annualTrendChart(rows) : null), [rows]);
    const normals = useMemo(() => (rows && rows.length ? normalsChart(rows) : null), [rows]);
    const precip = useMemo(() => (rows && rows.length ? precipChart(rows) : null), [rows]);
    const skyGroups = useMemo(() => (rows && rows.length ? skyGroupsChart(rows) : null), [rows]);
    const conditions = useMemo(() => (rows && rows.length ? conditionsChart(rows) : null), [rows]);
    const wind = useMemo(() => (rows && rows.length ? windChart(rows) : null), [rows]);
    const years = useMemo(() => (rows ? getYearsPresent(rows) : []), [rows]);
    const controls = cs && bounds && cs.range ? dailyControls(cs, years, bounds) : null;

    const enriched = useMemo(() => {
        if (!enrichment.rows) return null;
        const solar = computeSolarNormals(enrichment.rows);

        return {
            daylight: daylightChart(solar),
            solarRadiation: solarRadiationChart(solar),
            windRose: windRoseChart(computeWindRose(enrichment.rows)),
            snowNormals: computeSnowNormals(enrichment.rows),
        };
    }, [enrichment.rows]);
    const snow = useMemo(() => (enriched ? snowChart(enriched.snowNormals, observed) : null), [enriched, observed]);
    const snowSeasons = useMemo(() => (observed ? snowSeasonsChart(observed) : null), [observed]);
    const sectionNote = enrichment.status === 'loading' ? 'loading' : enrichment.status === 'error' ? 'error' : 'ready';
    const sunNote = sectionNote === 'loading' ? ENRICHMENT_LOADING.sun : sectionNote === 'error' ? ENRICHMENT_FAILED : '';
    const windNote = sectionNote === 'loading' ? ENRICHMENT_LOADING.wind : sectionNote === 'error' ? ENRICHMENT_FAILED : '';

    // ---- handlers --------------------------------------------------------------------------------------------------------------
    const change = (fn: (s: CityState) => CityState) => setCs((s) => (s ? fn(s) : s));
    const withBounds = (fn: (s: CityState, b: NonNullable<typeof bounds>) => CityState) => () => bounds && change((s) => fn(s, bounds));

    let statsPanel: { kind: 'cards' | 'loading' | 'error'; cards?: ReturnType<typeof cityStatCards>; text?: string };
    if (weather.status === 'error') statsPanel = { kind: 'error', text: `Couldn't load weather data right now. ${weather.message}` };
    else if (weather.status === 'empty') statsPanel = { kind: 'error', text: 'No city data found in the workbook yet.' };
    else if (failure !== null) statsPanel = { kind: 'error', text: `Couldn't load weather data right now. ${failure}` };
    else if (stats) statsPanel = { kind: 'cards', cards: stats };
    else statsPanel = { kind: 'loading', text: 'Loading city stats…' };

    const dataStatus: DataStatus | null =
        weather.status === 'ready'
            ? { state: weather.data.stale ? 'error' : '', message: `Data as of ${weather.data.fetchedAt.slice(0, 10)}` }
            : weather.status === 'error'
              ? { state: 'error', message: 'Data failed to load' }
              : null;
    const names = weather.status === 'ready' ? weather.names : [];
    const boxed = { marginTop: '28px' };
    void isoDate;
    void mdash;

    return (
        <PublicLayout
            activeId="city"
            pages={pages}
            account={account}
            dataStatus={dataStatus}
            season={(cs?.theme as 'winter' | 'spring' | 'summer' | 'fall' | null) ?? undefined}
        >
            <Head title="City Explorer — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <section className="hero" style={{ padding: '36px 0' }}>
                <div className="mrv-container">
                    <div className="eyebrow">City Explorer</div>
                    <h1 id="city-title" style={{ fontSize: 'clamp(1.8rem, 3.4vw, 2.6rem)' }}>
                        {city ?? 'Loading…'}
                    </h1>
                    <div className="map-controls" style={{ alignItems: 'center', gap: '14px' }}>
                        <label htmlFor="city-select" style={{ fontSize: '0.9rem', color: 'var(--color-dusk-soft)' }}>
                            City:
                        </label>
                        <CitySelect
                            id="city-select"
                            names={names}
                            value={city ?? ''}
                            onChange={(name) => {
                                userChangedCity.current = true;
                                if (cs) show(name, cs);
                            }}
                        />
                        <span className="card-note" id="city-updated">
                            {cityData?.lastChecked ? `Data last checked ${cityData.lastChecked}` : ''}
                        </span>
                    </div>
                    <p className="card-note" id="geo-note" style={{ marginTop: '8px' }}>
                        {geo}
                    </p>
                    <div id="live-conditions" style={{ marginTop: '18px', maxWidth: '420px' }}>
                        <LiveConditions state={live} />
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="grid-stats grid" id="city-stats">
                        {statsPanel.kind === 'cards' ? <StatCards cards={statsPanel.cards ?? []} /> : <PanelContent panel={statsPanel} />}
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Daily Temperatures</h2>
                        <div className="map-controls" style={{ alignItems: 'center', gap: '8px' }}>
                            <label htmlFor="daily-range-input" style={{ fontSize: '0.9rem', color: 'var(--color-dusk-soft)' }}>
                                Date range:
                            </label>
                            <button
                                type="button"
                                id="daily-prev"
                                className="btn-nav-arrow"
                                aria-label="Previous period"
                                disabled={controls ? controls.prevDisabled : false}
                                onClick={withBounds((s, b) => stepDaily(s, -1, b))}
                            >
                                &#8249;
                            </button>
                            <DateRangeInput
                                min={bounds?.min ?? ''}
                                max={bounds?.max ?? ''}
                                start={cs?.range?.start ?? ''}
                                end={cs?.range?.end ?? ''}
                                tick={cs?.dailyTick ?? 0}
                                onCommit={(dates) => {
                                    if (dates.length < 2 || !bounds) return;
                                    change((s) => commitPickerRange(s, isoDate(dates[0]), isoDate(dates[1]), bounds));
                                }}
                            />
                            <button
                                type="button"
                                id="daily-next"
                                className="btn-nav-arrow"
                                aria-label="Next period"
                                disabled={controls ? controls.nextDisabled : false}
                                onClick={withBounds((s, b) => stepDaily(s, 1, b))}
                            >
                                &#8250;
                            </button>
                        </div>
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Presets:</span>
                        <StateButtons
                            id="daily-preset-controls"
                            attribute="preset"
                            buttons={controls?.presets ?? []}
                            onChoose={(k) => bounds && change((s) => chooseDailyPreset(s, k, bounds))}
                        />
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Jump to season:</span>
                        <StateButtons
                            id="daily-season-controls"
                            attribute="season"
                            buttons={controls?.seasons ?? []}
                            onChoose={(k) => bounds && change((s) => chooseDailySeason(s, k, bounds))}
                        />
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Jump to year:</span>
                        <StateButtons
                            id="daily-year-controls"
                            attribute="year"
                            buttons={controls?.years ?? []}
                            onChoose={(k) => bounds && change((s) => chooseDailyYear(s, Number(k), bounds))}
                        />
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Jump to month:</span>
                        <StateButtons
                            id="daily-month-controls"
                            attribute="month"
                            buttons={controls?.months ?? []}
                            onChoose={(k) => bounds && change((s) => chooseDailyMonth(s, Number(k), bounds))}
                        />
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Chart options:</span>
                        <StateButtons
                            id="daily-chart-options"
                            attribute="option"
                            buttons={controls?.options ?? []}
                            onChoose={(k) => change((s) => toggleDailyOption(s, k as 'avg' | 'compare'))}
                        />
                    </div>
                    <div className="card chart-card">
                        <p className="card-note" id="daily-gap-note">
                            {daily?.gapNote ?? ''}
                        </p>
                        <p className="card-note" id="daily-yoy-note">
                            <YoyNote yoy={daily?.yoy ?? null} />
                        </p>
                        <div className="chart-tall">
                            <MrvChart id="chart-daily" config={daily?.config ?? null} />
                        </div>
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Year-over-Year Comparison</h2>
                        <p>See how conditions have shifted across the full data record.</p>
                    </div>
                    <div className="grid" style={{ gap: '20px' }}>
                        <div className="card chart-card">
                            <h3>Annual Averages Trend</h3>
                            <p className="card-note" id="annual-trend-note">
                                {annual?.note ?? 'Avg. daily high & low by year.'}
                            </p>
                            <MrvChart id="chart-annual-trend" config={annual?.config ?? null} />
                        </div>
                        <div className="card chart-card">
                            <h3>Compare Specific Years</h3>
                            <p className="card-note">Daily high/low curves overlaid on the same calendar year — pick up to 6 years to compare.</p>
                            <div className="map-controls" id="overlay-year-controls">
                                <ToggleButtons
                                    options={years.map((y) => ({ key: String(y), label: String(y) }))}
                                    active={(cs?.overlayYears ?? []).map(String)}
                                    attribute="year"
                                    typed={false}
                                    onChange={(k) => change((s) => toggleOverlayYear(s, Number(k)))}
                                />
                            </div>
                            <div className="map-controls" id="overlay-metric-controls">
                                {cs ? (
                                    <ToggleButtons
                                        options={OVERLAY_METRICS}
                                        active={cs.metric}
                                        attribute="metric"
                                        typed={false}
                                        onChange={(k) => change((s) => setOverlayMetric(s, k as OverlayMetric))}
                                    />
                                ) : null}
                            </div>
                            <div className="control-row">
                                <span className="control-row-label">Zoom to month:</span>
                                <div className="map-controls" id="overlay-month-controls">
                                    {cs ? (
                                        <ToggleButtons
                                            options={ZOOM_BUTTONS}
                                            active={cs.zoom ? String(cs.zoom) : ''}
                                            attribute="zoom-month"
                                            typed={false}
                                            onChange={(k) => change((s) => setOverlayZoom(s, k === '' ? null : Number(k)))}
                                        />
                                    ) : null}
                                </div>
                            </div>
                            <p className="card-note" id="overlay-gap-note">
                                {overlay?.gapNote ?? ''}
                            </p>
                            <div className="chart-tall">
                                <MrvChart id="chart-overlay" config={overlay?.config ?? null} />
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Climate Normals &amp; Patterns</h2>
                        <p id="normals-note">{notes || 'Averages computed across all years of daily records.'}</p>
                    </div>
                    <div className="grid-charts grid">
                        <div className="card chart-card">
                            <h3>Monthly Temperature Normals</h3>
                            <p className="card-note">Average daily high &amp; low by month.</p>
                            <MrvChart id="chart-normals" config={normals} />
                        </div>
                        <div className="card chart-card">
                            <h3>Monthly Precipitation</h3>
                            <p className="card-note">Average total rainfall/snowfall-equivalent by month.</p>
                            <MrvChart id="chart-precip" config={precip} />
                        </div>
                        <div className="card chart-card">
                            <h3>Weather Conditions</h3>
                            <p className="card-note">Sky character at a glance, then the full breakdown below. Both bars use a fixed 0–100% scale.</p>
                            <div style={{ height: '150px' }}>
                                <MrvChart id="chart-sky-groups" config={skyGroups} />
                            </div>
                            <MrvChart id="chart-conditions" config={conditions} />
                        </div>
                        <div className="card chart-card">
                            <h3>Monthly Wind Gusts</h3>
                            <p className="card-note">Average daily max wind gust by month.</p>
                            <MrvChart id="chart-wind" config={wind} />
                        </div>
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Sun &amp; Solar</h2>
                        <p>Daylight, sunshine, and solar radiation by month.</p>
                    </div>
                    <p className="card-note" id="sun-solar-note">
                        {sunNote}
                    </p>
                    <div className="grid-charts grid">
                        <div className="card chart-card">
                            <h3>Daylight &amp; Sunshine</h3>
                            <p className="card-note">Average hours per day by month.</p>
                            <MrvChart id="chart-daylight" config={enriched?.daylight ?? null} />
                        </div>
                        <div className="card chart-card">
                            <h3>Solar Radiation</h3>
                            <p className="card-note">Average total, direct, and diffuse radiation (MJ/m²) by month.</p>
                            <MrvChart id="chart-solar-radiation" config={enriched?.solarRadiation ?? null} />
                        </div>
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Wind &amp; Snow</h2>
                        <p>Prevailing wind direction and modeled snow depth.</p>
                    </div>
                    <p className="card-note" id="wind-snow-note">
                        {windNote}
                    </p>
                    <div className="grid-charts grid">
                        <div className="card chart-card">
                            <h3>Wind Rose</h3>
                            <p className="card-note">% of days with each dominant wind direction, all years combined.</p>
                            <MrvChart id="chart-wind-rose" config={enriched?.windRose ?? null} />
                        </div>
                        <div className="card chart-card">
                            <h3>Snow Depth</h3>
                            <p className="card-note" id="snow-depth-note">
                                {observed ? OBSERVED_SNOW_NOTE : MODELED_SNOW_NOTE}
                            </p>
                            <MrvChart id="chart-snow" config={snow} />
                        </div>
                    </div>

                    <div id="observed-snow" hidden={!observed} style={boxed}>
                        <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: '1.35rem', margin: '0 0 6px' }}>
                            Observed Snowfall at Big Bear Lake
                        </h3>
                        <p className="card-note" style={{ maxWidth: '720px', marginBottom: '14px' }}>
                            Real measurements, not model output: a volunteer weather station at Big Bear Lake (about 6,750 feet) has recorded daily
                            snowfall for every winter since 1960, kept by NOAA.
                        </p>
                        <div className="grid-stats grid" id="observed-snow-stats">
                            {shownObserved ? <StatCards cards={observedSnowCards(shownObserved)} /> : null}
                        </div>
                        <div className="card chart-card" style={{ marginTop: '14px' }}>
                            <h3>Snowfall by Winter</h3>
                            <p className="card-note">
                                Total inches of snowfall per winter (July through June). Lighter bars are winters with a noticeable number of missing
                                days, or the winter still in progress, so their totals are lower bounds.
                            </p>
                            <div className="chart-tall">
                                <MrvChart id="chart-snow-seasons" config={snowSeasons} />
                            </div>
                        </div>
                        <p className="card-note" style={{ maxWidth: '720px', marginTop: '12px' }}>
                            Source:{' '}
                            <a href="https://www.ncei.noaa.gov/products/land-based-station/global-historical-climatology-network-daily">
                                NOAA Global Historical Climatology Network, daily
                            </a>
                            , station USC00040741. The station is a cooperative observer site, so a day can be missing, and values NOAA&apos;s own
                            quality checks flagged are left out here. The monthly bars above compare the same calendar months across 2017 onward; the
                            model&apos;s value is for Big Bear City&apos;s coordinates, the observation is at the lake, and the two will not match
                            exactly.
                        </p>
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Where It Is</h2>
                    </div>
                    <MiniMapView id="city-mini-map" lat={cityData?.lat ?? null} lon={cityData?.lon ?? null} label={city ?? ''} />
                </div>
            </section>
        </PublicLayout>
    );
}
