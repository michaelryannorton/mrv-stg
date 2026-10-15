import MrvChart from '@/components/mrv/chart';
import { ModelTable, SourceNote } from '@/components/mrv/climate';
import { resolveNav } from '@/components/mrv/navigation';
import { StatCards } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import PublicLayout from '@/layouts/public-layout';
import {
    CITIES,
    climateStats,
    DEFAULT_METRIC,
    fetchClimate,
    loadObserved,
    METRIC_ORDER,
    METRICS,
    monthConfig,
    realityCards,
    yearConfig,
    type ClimateData,
    type ObservedRow,
} from '@/lib/mrv/climate';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

// Climate Projections (production: climate.html with js/climate.js): what seven climate models say about temperature, extreme
// heat, frost and rainfall through 2050, for one high-emissions pathway. Chart.js's own defaults (the page sets none).

const DESCRIPTION =
    'What seven climate models project for temperature, extreme heat, frost and rainfall in Mojave River Valley communities through 2050, under one high-emissions pathway.';
const METRIC_OPTIONS = METRIC_ORDER.map((k) => ({ key: k, label: METRICS[k].label }));

type Load = { status: 'loading' } | { status: 'error'; text: string } | { status: 'ready'; data: ClimateData };

export default function ClimateIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    // The address names the community (`?city=Barstow`), read once when the page starts.
    const [cityId, setCityId] = useState(() => {
        const wanted = new URLSearchParams(window.location.search).get('city');
        return (CITIES.find((c) => c.name.toLowerCase() === (wanted || '').toLowerCase()) || CITIES[0]).id;
    });
    const [load, setLoad] = useState<Load>({ status: 'loading' });
    const [metric, setMetric] = useState(DEFAULT_METRIC);
    const [observed, setObserved] = useState<Record<number, ObservedRow[]> | null>(null);

    useEffect(() => {
        let live = true;
        setLoad({ status: 'loading' });
        fetchClimate(cityId).then(
            (data) => {
                if (!live) return;
                if (!data.snapshot) return setLoad({ status: 'error', text: 'Projections for this community have not been loaded yet.' });
                setLoad({ status: 'ready', data });
                setObserved(null);
                loadObserved(cityId).then(
                    (obs) => live && setObserved(obs),
                    (err: Error) => console.warn('Recorded weather unavailable for the reality check', err),
                );
            },
            (err: Error) => {
                console.error('Climate summary fetch failed:', err);
                if (live) setLoad({ status: 'error', text: 'Climate projections could not be loaded right now.' });
            },
        );

        return () => {
            live = false;
        };
    }, [cityId]);

    // Production keeps the last community's figures in the (hidden) sections while another loads or has no projections.
    const [kept, setKept] = useState<{ data: ClimateData; cityId: number } | null>(null);
    useEffect(() => {
        if (load.status === 'ready') setKept({ data: load.data, cityId });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load]);
    const shown = load.status === 'ready' ? { data: load.data, cityId } : kept;
    const data = shown ? shown.data : null;
    const stats = useMemo(() => (shown ? climateStats(shown.data, shown.cityId) : null), [shown?.data, shown?.cityId]);
    const year = useMemo(() => (data ? yearConfig(data, metric) : null), [data, metric]);
    const month = useMemo(() => (data ? monthConfig(data) : null), [data]);
    const reality = useMemo(() => (data ? realityCards(data, metric, observed) : null), [data, metric, observed]);
    const ready = load.status === 'ready';

    const chooseCity = (id: number) => {
        const c = CITIES.find((x) => x.id === id) as (typeof CITIES)[number];
        const url = new URL(window.location.href);
        url.searchParams.set('city', c.name);
        window.history.replaceState(window.history.state, '', url);
        setCityId(id);
    };

    return (
        <PublicLayout activeId="climate" pages={pages} account={account}>
            <Head title="Climate Projections — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <section className="hero" style={{ padding: '36px 0' }}>
                <div className="mrv-container">
                    <div className="eyebrow">Climate Projections</div>
                    <h1 style={{ fontSize: 'clamp(1.8rem, 3.4vw, 2.6rem)' }}>What climate models say about the next 25 years here</h1>
                    <p style={{ maxWidth: '640px' }}>
                        Seven high-resolution climate models, each run forward to 2050 and adjusted to this location's own recorded weather. The point
                        is the direction and the size of the change, and how much the models disagree.
                    </p>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Read This First</h2>
                    </div>
                    <ul style={{ maxWidth: '720px', paddingLeft: '20px', lineHeight: 1.6 }}>
                        <li>
                            <strong>One pathway, not a range of futures.</strong> These models were run assuming high greenhouse gas emissions. The
                            service we use does not offer other scenarios, and until about 2050 the scenarios differ little. Projections past 2050
                            depend heavily on the scenario and are not shown.
                        </li>
                        <li>
                            <strong>These are model runs, not forecasts.</strong> A model's value for a single year says little, because each
                            simulates its own random weather. The signal is the average over many years, and the spread between the seven models is a
                            measure of how uncertain it is.
                        </li>
                        <li>
                            <strong>Rainfall is the least certain.</strong> The models agree on warming far better than they agree on precipitation,
                            especially in a desert where a few storms make the year.
                        </li>
                    </ul>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Change by Mid-Century</h2>
                        <p id="cl-sub">{stats ? stats.sub : ''}</p>
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Community:</span>
                        <select id="cl-city" aria-label="Community" value={String(cityId)} onChange={(e) => chooseCity(Number(e.target.value))}>
                            {CITIES.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className={load.status === 'error' ? 'error-panel' : 'loading-panel'} id="cl-loading" hidden={ready}>
                        {load.status === 'error' ? load.text : 'Loading projections…'}
                    </div>
                    <div id="cl-body" hidden={!ready}>
                        <div className="grid-stats grid" id="cl-stats" style={{ margin: '14px 0 6px' }}>
                            {stats ? <StatCards cards={stats.cards} /> : null}
                        </div>
                        <p className="card-note" id="cl-stats-note" style={{ maxWidth: '720px' }}>
                            {stats ? stats.note : ''}
                        </p>
                    </div>
                </div>
            </section>

            <section id="cl-chart-section" hidden={!ready}>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Year by Year</h2>
                        <p>
                            Ten-year averages, so a single freak year does not dominate. Each thin line is one model; the heavy line is the middle of
                            the seven.
                        </p>
                    </div>
                    <div className="control-row">
                        <span className="control-row-label">Measure:</span>
                        <div id="cl-metric-controls" className="map-controls">
                            {data ? <ToggleButtons options={METRIC_OPTIONS} active={metric} attribute="metric" onChange={setMetric} /> : null}
                        </div>
                    </div>
                    <div className="card chart-card">
                        <div className="chart-tall">
                            <MrvChart id="chart-cl-year" config={year} style="stock" />
                        </div>
                    </div>
                    <div className="grid-stats grid" id="cl-reality" style={{ marginTop: '14px' }}>
                        {reality ? <StatCards cards={reality.cards} /> : null}
                    </div>
                    <p className="card-note" id="cl-reality-note" style={{ maxWidth: '720px', marginTop: '8px' }}>
                        {reality ? reality.note : ''}
                    </p>
                </div>
            </section>

            <section className="alt" id="cl-month-section" hidden={!ready}>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Which Months Warm Most</h2>
                        <p>
                            Change in the average daily high for each month, 2035&ndash;2049 compared with 1991&ndash;2020. The bar spans the lowest
                            to the highest of the seven models; the mark is the middle.
                        </p>
                    </div>
                    <div className="card chart-card">
                        <div className="chart-tall">
                            <MrvChart id="chart-cl-month" config={month} style="stock" />
                        </div>
                    </div>
                </div>
            </section>

            <section id="cl-table-section" hidden={!ready}>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Model by Model</h2>
                        <p>The same comparison for each model, so you can see the disagreement behind the middle value.</p>
                    </div>
                    <div className="chart-scroll" id="cl-table">
                        {data ? <ModelTable data={data} /> : null}
                    </div>
                    <p className="card-note" id="cl-source" style={{ maxWidth: '720px', marginTop: '14px' }}>
                        {data ? <SourceNote data={data} /> : null}
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
