import MrvChart from '@/components/mrv/chart';
import { CitySelect, cityFromUrl, rememberCity } from '@/components/mrv/city-select';
import { resolveNav } from '@/components/mrv/navigation';
import { type DataStatus } from '@/components/mrv/site-footer';
import { PanelContent } from '@/components/mrv/stat-card';
import PublicLayout from '@/layouts/public-layout';
import { type Panel } from '@/lib/mrv/air-quality';
import { ET0_NOTE, et0Config, findNearestCity, frostCards, soilMoistureConfig, soilTempConfig, vpdConfig } from '@/lib/mrv/garden';
import { computeFrostWindow, computeSoilNormals, computeSolarNormals, computeVpdNormals, excludeYear, type ApiRow } from '@/lib/mrv/stats';
import { weatherData, type LoadResult } from '@/lib/mrv/weather-data';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

// Garden & Grow (production: garden.html with js/garden.js): frost window, irrigation demand, vapor pressure deficit and soil
// temperature and moisture by depth, for a city. Without a city in the address the browser may be asked where the visitor is.

const DESCRIPTION =
    'Frost windows, irrigation demand (ET0), vapor pressure deficit, and soil temperature/moisture by depth for communities across the Mojave River Valley — for growers, ranchers, and garden enthusiasts.';

type Weather =
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'empty' }
    | { status: 'ready'; data: LoadResult; names: string[] };

export default function GardenIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [weather, setWeather] = useState<Weather>({ status: 'loading' });
    const [city, setCity] = useState<string | null>(null);
    const [rows, setRows] = useState<ApiRow[] | null>(null);
    const [rowsError, setRowsError] = useState(false);
    const request = useRef(0);
    const userChangedCity = useRef(false);

    useEffect(() => {
        let live = true;
        weatherData.load().then(
            (data) => {
                if (!live) return;
                const names = weatherData.getCityNames();
                if (!names.length) return setWeather({ status: 'empty' });
                const requested = cityFromUrl();
                const initial = requested && names.includes(requested) ? requested : names[0];
                setWeather({ status: 'ready', data, names });
                setCity(initial);

                // With no city in the address, ask where the visitor is and move to the nearest city (never after they chose one).
                if (!requested && navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        (pos) => {
                            if (!live || userChangedCity.current) return;
                            const nearest = findNearestCity(pos.coords.latitude, pos.coords.longitude, names, (n) => weatherData.getCity(n));
                            if (!nearest || nearest.name === initial) return;
                            setCity(nearest.name);
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
                if (live) setWeather({ status: 'error', message: err.message });
            },
        );

        return () => {
            live = false;
        };
    }, []);

    useEffect(() => {
        if (!city) return;
        rememberCity(city);
        const mine = ++request.current;
        weatherData.getCityEnrichment<ApiRow[]>(city).then(
            (raw) => {
                if (mine !== request.current) return;
                setRowsError(false);
                setRows(excludeYear(raw, 2016));
            },
            (err: Error) => {
                console.warn('renderGarden failed', err);
                if (mine === request.current) setRowsError(true);
            },
        );
    }, [city]);

    const frost = useMemo(() => (rows ? frostCards(computeFrostWindow(rows)) : null), [rows]);
    const et0 = useMemo(() => (rows ? et0Config(computeSolarNormals(rows)) : null), [rows]);
    const vpd = useMemo(() => (rows ? vpdConfig(computeVpdNormals(rows)) : null), [rows]);
    const soilTemp = useMemo(() => (rows ? soilTempConfig(computeSoilNormals(rows)) : null), [rows]);
    const soilMoisture = useMemo(() => (rows ? soilMoistureConfig(computeSoilNormals(rows)) : null), [rows]);

    const names = weather.status === 'ready' ? weather.names : [];
    let panel: Panel;
    if (weather.status === 'loading') panel = { kind: 'loading', text: 'Loading frost data…' };
    else if (weather.status === 'error') panel = { kind: 'error', text: `Couldn't load weather data right now. ${weather.message}` };
    else if (weather.status === 'empty') panel = { kind: 'error', text: 'No city data found yet.' };
    else if (rowsError) panel = { kind: 'error', text: "Couldn't load this city's data right now." };
    else panel = frost ? { kind: 'cards', cards: frost } : { kind: 'loading', text: 'Loading frost data…' };

    const cityData = weather.status === 'ready' && city ? weatherData.getCity(city) : undefined;
    const dataStatus: DataStatus | null =
        weather.status === 'ready'
            ? { state: weather.data.stale ? 'error' : '', message: `Data as of ${weather.data.fetchedAt.slice(0, 10)}` }
            : weather.status === 'error'
              ? { state: 'error', message: 'Data failed to load' }
              : null;

    return (
        <PublicLayout activeId="garden" pages={pages} account={account} dataStatus={dataStatus}>
            <Head title="Garden & Grow — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <section className="hero" style={{ padding: '36px 0' }}>
                <div className="mrv-container">
                    <div className="eyebrow">Garden &amp; Grow</div>
                    <h1 id="garden-title" style={{ fontSize: 'clamp(1.8rem, 3.4vw, 2.6rem)' }}>
                        {city ? `Garden & Grow — ${city}` : 'Loading…'}
                    </h1>
                    <div className="map-controls" style={{ alignItems: 'center', gap: '14px' }}>
                        <label htmlFor="garden-city-select" style={{ fontSize: '0.9rem', color: 'var(--color-dusk-soft)' }}>
                            City:
                        </label>
                        <CitySelect
                            id="garden-city-select"
                            names={names}
                            value={city ?? ''}
                            onChange={(name) => {
                                userChangedCity.current = true;
                                setCity(name);
                            }}
                        />
                        <span className="card-note" id="garden-updated">
                            {cityData?.lastChecked ? `Data last checked ${cityData.lastChecked}` : ''}
                        </span>
                    </div>
                    <p className="card-note" style={{ marginTop: '8px', maxWidth: '640px' }}>
                        Modeled data for planning and comparison, not a substitute for a soil test or your local extension office's advice — see the
                        caveats below each chart, and the full data-source specs linked in the footer.
                    </p>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="grid-stats grid" id="garden-frost-stats">
                        <PanelContent panel={panel} />
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Irrigation Demand (ET0)</h2>
                        <p>
                            Reference evapotranspiration — how much water a well-watered reference crop loses to the atmosphere per day. Higher ET0
                            means plants need more replacement water to keep pace, all else equal.
                        </p>
                    </div>
                    <p className="card-note" id="et0-note">
                        {et0 ? ET0_NOTE : ''}
                    </p>
                    <div className="card chart-card">
                        <MrvChart id="chart-et0" config={et0} />
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Vapor Pressure Deficit</h2>
                        <p>
                            How much drying power the air has — the gap between how much moisture the air is holding and how much it could hold at
                            saturation. Higher VPD pulls water out of plants (and soil) faster.
                        </p>
                    </div>
                    <div className="card chart-card">
                        <MrvChart id="chart-vpd" config={vpd} />
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Soil Temperature by Depth</h2>
                        <p>Modeled soil temperature at four depths — shallow soil tracks the surface closely; deeper layers lag and flatten out.</p>
                    </div>
                    <div className="card chart-card">
                        <MrvChart id="chart-soil-temp" config={soilTemp} />
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Soil Moisture by Depth</h2>
                        <p>Modeled volumetric water content at the same four depths.</p>
                    </div>
                    <div className="card chart-card">
                        <MrvChart id="chart-soil-moisture" config={soilMoisture} />
                    </div>
                    <p className="card-note" style={{ marginTop: '10px' }}>
                        Soil temperature and moisture here are modeled (Open-Meteo), not measured at your actual soil — see{' '}
                        <a href="docs/data-sources/open-meteo.md">the data spec</a> for real resolution and known limitations, and{' '}
                        <a href="docs/data-sources/nrcs-ssurgo.md">the NRCS soil-profile spec</a> for the separate, coarser soil-type dataset this
                        project also collects.
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
