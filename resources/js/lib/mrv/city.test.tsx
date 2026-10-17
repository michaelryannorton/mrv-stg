/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { LiveConditions, YoyNote } from '@/components/mrv/city';
import { StatCards } from '@/components/mrv/stat-card';
import { classes } from '@/lib/mrv/classes';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { probeCity } from './__golden__/chart-probes.mjs';
import { serialize } from './__golden__/chart-stub.mjs';
import {
    addDays,
    annualTrendChart,
    boundsOf,
    buildSearch,
    chooseDailyMonth,
    chooseDailyPreset,
    chooseDailySeason,
    chooseDailyYear,
    cityStatCards,
    clampDate,
    commitPickerRange,
    computePresetRange,
    computeStepTarget,
    conditionsChart,
    dailyChart,
    dailyControls,
    daylightChart,
    decodeRangeToken,
    describeGap,
    encodeRangeToken,
    ENRICHMENT_FAILED,
    ENRICHMENT_LOADING,
    enterCity,
    findNearestCity,
    geoNote,
    isoDate,
    lastDayOfMonth,
    loadObservedSnow,
    MODELED_SNOW_NOTE,
    normalsChart,
    normalsNote,
    OBSERVED_SNOW_CITY,
    OBSERVED_SNOW_NOTE,
    observedSnowCards,
    overlayChart,
    precipChart,
    resetObservedSnow,
    seasonBounds,
    setOverlayMetric,
    setOverlayZoom,
    shiftYearStr,
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
    type CityState,
    type ObservedSnow,
} from './city';
import { asJson, readGolden } from './golden';
import { destroyMap, initMiniMap } from './leaflet-map';
import { alertSeverity, compassLabel, fetchLatest, fmtObservedAt, liveCard } from './live';
import { computeSnowNormals, computeSolarNormals, computeWindRose, excludeYear, type ApiRow, type DayRow } from './stats';
import { canon } from './test-helpers/html-canon';
import { createWeatherData } from './weather-data';

// Leaflet is replaced by the recording stand-in the legacy scripts ran against to make the golden file.
const lflog = vi.hoisted(() => [] as unknown[]);
vi.mock('leaflet', async () => {
    const { makeLeaflet } = await import('./__golden__/leaflet-stub.mjs');

    return makeLeaflet(lflog, {});
});

/**
 * The City Explorer against the legacy city.js and live.js (run unmodified, with data.js, stats.js and map.js, over a fake DOM, a
 * recording Leaflet, Chart.js and flatpickr, a fixed clock, a fake geolocation and the saved data, through every control): after
 * every step the page's state gives the same address, button states, notes, statistics, chart configurations (and what their
 * functions answer), map calls and Right Now card as the legacy page wrote.
 */
const golden = readGolden<any>('phase6-golden.json.gz');
const inputs = readGolden<any>('phase6-inputs.json.gz');
const inputs5 = readGolden<any>('phase5-inputs.json.gz');
const dataInputs = readGolden<any>('data-inputs.json.gz');
const NOW = inputs.clock as number;
const html = (node: React.ReactElement) => renderToStaticMarkup(node);

interface Options {
    cityStatus?: number;
    failEnrich?: string[];
    snow?: 'real' | 'few' | 'none' | 'failed';
    geo?: 'none' | 'denied' | { lat: number; lon: number };
}

function loader(opts: Options = {}) {
    const fake = async (url: string) => {
        const q = new URL(url).searchParams;
        if (opts.cityStatus) return { ok: false, status: opts.cityStatus, json: async () => ({}) };
        if (q.get('table') === 'weather_daily' && !q.get('columns') && opts.failEnrich?.includes(q.get('city_id') as string))
            return { ok: false, status: 500, json: async () => ({}) };
        const body =
            q.get('table') === 'cities'
                ? dataInputs.cities
                : q.get('columns')
                  ? [...dataInputs.weatherBase, ...inputs.weatherBase]
                  : inputs5.enrich[q.get('city_id') as string] || [];

        return { ok: true, status: 200, json: async () => body };
    };

    return createWeatherData({ fetch: fake, storage: () => ({ getItem: () => null, setItem() {} }), now: () => NOW, warn() {} });
}

function observedFor(opts: Options): ObservedSnow | null {
    if (opts.snow === 'failed') return null;
    const data = opts.snow === 'few' ? inputs.snowFewSeasons : opts.snow === 'none' ? inputs.snowNoSeasons : inputs.snow;

    return data.seasons.length ? (data as ObservedSnow) : null;
}

const takeLog = () => (lflog as any[]).splice(0, (lflog as any[]).length);

/** The buttons of one box as the golden recorded them: [data attributes, class list, disabled]. */
const rowOf = (attr: string, key: string, active: boolean, disabled = false) => [
    `${attr}=${key}`,
    classes('btn-toggle', active && 'active'),
    disabled,
];

/** What the port's state says every button box holds, in the golden's form. */
function expectedButtons(cs: CityState, years: number[], bounds: { min: string; max: string }) {
    const c = dailyControls(cs, years, bounds);

    return {
        'daily-preset-controls': c.presets.map((b) => rowOf('preset', b.key, b.active, b.disabled)),
        'daily-season-controls': c.seasons.map((b) => rowOf('season', b.key, b.active, b.disabled)),
        'daily-year-controls': c.years.map((b) => rowOf('year', b.key, b.active, b.disabled)),
        'daily-month-controls': c.months.map((b) => rowOf('month', b.key, b.active, b.disabled)),
        'daily-chart-options': c.options.map((b) => rowOf('option', b.key, b.active, b.disabled)),
        'overlay-year-controls': years.map((y) => rowOf('year', String(y), cs.overlayYears.includes(y))),
        'overlay-metric-controls': ['both', 'high', 'low'].map((k) => rowOf('metric', k, cs.metric === k)),
        'overlay-month-controls': ['', ...Array.from({ length: 12 }, (_, i) => String(i + 1))].map((k) =>
            rowOf('zoomMonth', k, (cs.zoom ? String(cs.zoom) : '') === k),
        ),
    };
}

interface Scenario {
    name: string;
    search: string;
    steps: any[];
    opts?: Options;
}

async function replay({ name, search, steps, opts = {} }: Scenario) {
    resetObservedSnow();
    const wd = loader(opts);
    const weather = await wd.load();
    const names = wd.getCityNames();
    const rowsOf = (n: string) => excludeYear((wd.getCity(n)?.rows ?? []) as unknown as DayRow[], 2016);
    const enrichOf = async (n: string): Promise<ApiRow[] | null> => {
        try {
            return excludeYear(await wd.getCityEnrichment<ApiRow[]>(n), 2016);
        } catch {
            return null;
        }
    };

    const requested = new URLSearchParams(search).get('city');
    const initial = requested && names.includes(requested) ? requested : names[0];
    let city = initial;
    let cs = enterCity(stateFromSearch(search), rowsOf(city));
    let currentSearch = search;
    let geo = '';
    let userChanged = false;
    let enriched: ApiRow[] | null = null;
    let enrichFailed = false;
    const running: Record<string, any> = {};

    const settleEnrichment = async () => {
        const rows = await enrichOf(city);
        enriched = rows ?? enriched;
        enrichFailed = rows === null;
    };

    let previous: CityState | null = null;
    const miniMap: { current: any } = { current: null };
    const mapAt = (n: string) => {
        destroyMap(miniMap.current);
        const c = wd.getCity(n);
        miniMap.current = initMiniMap('city-mini-map', c?.lat ?? null, c?.lon ?? null, n);
    };
    takeLog();
    mapAt(city);

    await settleEnrichment();

    for (const [i, step] of steps.entries()) {
        const label = `${name}: ${step.step}`;
        let moved = i === 0; // a new city was shown (the map is redrawn)
        if (i > 0) {
            const [kind, a, b] = step.action;
            const bounds = boundsOf(rowsOf(city));
            const before = cs;
            takeLog();
            if (kind === 'city') {
                userChanged = true;
                city = a;
                cs = enterCity(cs, rowsOf(city));
                moved = true;
            } else if (kind === 'preset') cs = chooseDailyPreset(cs, a, bounds);
            else if (kind === 'season') cs = chooseDailySeason(cs, a, bounds);
            else if (kind === 'year') cs = chooseDailyYear(cs, a, bounds);
            else if (kind === 'month') cs = chooseDailyMonth(cs, a, bounds);
            else if (kind === 'option') cs = toggleDailyOption(cs, a);
            else if (kind === 'prev') cs = stepDaily(cs, -1, bounds);
            else if (kind === 'next') cs = stepDaily(cs, 1, bounds);
            else if (kind === 'overlayYear') cs = toggleOverlayYear(cs, a);
            else if (kind === 'overlayMetric') cs = setOverlayMetric(cs, a);
            else if (kind === 'overlayZoom') cs = setOverlayZoom(cs, a);
            else if (kind === 'pick') cs = commitPickerRange(cs, isoDate(new Date(a[0], a[1], a[2])), isoDate(new Date(b[0], b[1], b[2])), bounds);
            else if (kind === 'pickOne') {
                /* a single date: the page waits for the second */
            } else if (kind === 'position' && typeof opts.geo === 'object' && !requested && !userChanged) {
                const nearest = findNearestCity(opts.geo.lat, opts.geo.lon, names, (n) => wd.getCity(n));
                if (nearest) {
                    geo = geoNote(nearest.name, nearest.distanceMiles);
                    if (nearest.name !== city) {
                        city = nearest.name;
                        cs = enterCity(cs, rowsOf(city));
                        moved = true;
                    }
                }
            }
            // The address follows every change of state (and none when nothing changed).
            expect(cs !== before || moved, `${label} (state changed)`).toBe(step.urls.length > 0);
            if (moved) {
                mapAt(city);
                await settleEnrichment();
            }
        }

        const rows = rowsOf(city);
        const bounds = boundsOf(rows);
        const years = [...new Set(rows.map((r) => Number(r.date.slice(0, 4))))].sort((x, y) => x - y);
        const observed = city === OBSERVED_SNOW_CITY && !enrichFailed ? observedFor(opts) : null;
        const settledObserved = i > 0 && step.action[0] === 'city' && step.step.includes('before the observed') ? null : observed;

        if (step.urls.length) {
            currentSearch = '?' + buildSearch(currentSearch, city, cs, bounds);
            expect(currentSearch, `${label} (address)`).toBe(new URL(step.urls.at(-1)).search);
        }

        // Text the page writes.
        const daily = dailyChart(rows, cs);
        const overlay = overlayChart(rows, cs);
        const annual = annualTrendChart(rows);
        expect(city, label).toBe(step.texts['city-title']);
        expect(step.selected, label).toBe(city);
        const cityData = wd.getCity(city);
        expect(cityData?.lastChecked ? `Data last checked ${cityData.lastChecked}` : '', label).toBe(step.texts['city-updated']);
        expect(normalsNote(rows), label).toBe(step.texts['normals-note']);
        expect(geo, label).toBe(step.texts['geo-note']);
        expect(daily.gapNote, label).toBe(step.texts['daily-gap-note']);
        expect(overlay.gapNote, label).toBe(step.texts['overlay-gap-note']);
        expect(annual.note, label).toBe(step.texts['annual-trend-note']);
        expect(enrichFailed ? ENRICHMENT_FAILED : '', label).toBe(step.texts['sun-solar-note']);
        expect(enrichFailed ? ENRICHMENT_FAILED : '', label).toBe(step.texts['wind-snow-note']);
        if (!enrichFailed) expect(settledObserved ? OBSERVED_SNOW_NOTE : MODELED_SNOW_NOTE, label).toBe(step.texts['snow-depth-note']);
        void ENRICHMENT_LOADING;
        expect(canon(html(<StatCards cards={cityStatCards(rows)} />), 'grid'), label).toBe(canon(step.stats, 'grid'));
        expect(names.map((n) => `<option value="${n}">${n}</option>`).join(''), label).toBe(step.options);

        // The year-over-year sentence.
        const yoyHtml = daily.yoy ? html(<YoyNote yoy={daily.yoy} />) : '';
        if (daily.yoy?.kind === 'delta') {
            expect(step.yoy[0], label).toBe('html');
            expect(canon(yoyHtml), label).toBe(canon(step.yoy[1]));
        } else {
            expect(step.yoy, label).toEqual(['text', yoyHtml.replace(/&#x27;/g, "'")]);
        }

        // The controls.
        expect(asJson(expectedButtons(cs, years, bounds)), label).toEqual(step.buttons);
        const controls = dailyControls(cs, years, bounds);
        expect(controls.prevDisabled, `${label} (previous)`).toBe(step.prev);
        expect(controls.nextDisabled, `${label} (next)`).toBe(step.next);
        expect(cs.theme, label).toBe(step.theme);

        // The charts: every chart the legacy page has drawn so far must be the one the port derives from the same state.
        for (const [canvas, made] of Object.entries<any>(step.charts)) running[canvas] = made;
        const derived: Record<string, any> = {
            'chart-daily': daily.config,
            'chart-annual-trend': annual.config,
            'chart-overlay': overlay.config,
            'chart-normals': normalsChart(rows),
            'chart-precip': precipChart(rows),
            'chart-sky-groups': skyGroupsChart(rows),
            'chart-conditions': conditionsChart(rows),
            'chart-wind': windChart(rows),
        };
        if (enriched) {
            const solar = computeSolarNormals(enriched);
            derived['chart-daylight'] = daylightChart(solar);
            derived['chart-solar-radiation'] = solarRadiationChart(solar);
            derived['chart-wind-rose'] = windRoseChart(computeWindRose(enriched));
            derived['chart-snow'] = snowChart(computeSnowNormals(enriched), settledObserved);
            if (settledObserved && !step.observedHidden) derived['chart-snow-seasons'] = snowSeasonsChart(settledObserved);
        }
        for (const [canvas, made] of Object.entries<any>(running)) {
            if (canvas === 'chart-snow-seasons' && step.observedHidden) continue;
            expect(derived[canvas], `${label} ${canvas} exists`).toBeDefined();
            expect(asJson(serialize(derived[canvas])), `${label} ${canvas}`).toEqual(made.config);
            expect(asJson(probeCity(derived[canvas])), `${label} ${canvas} functions`).toEqual(made.probes);
        }

        // The observed snow section.
        expect(!settledObserved, label).toBe(step.observedHidden);
        if (settledObserved)
            expect(canon(html(<StatCards cards={observedSnowCards(settledObserved)} />), 'grid'), label).toBe(canon(step.observedStats, 'grid'));

        // The locator map is redrawn when a city is shown.
        if (moved) {
            const calls = asJson(takeLog()).filter(([n]: [string]) => n !== 'new Chart' && n !== 'destroy chart');
            expect(calls, `${label} (map)`).toEqual(asJson(step.map).filter(([n]: [string]) => n !== 'new Chart' && n !== 'destroy chart'));
        }
        previous = cs;
    }
    void previous;
    void weather;
    void fetchLatest;
}

const SCENARIOS: Scenario[] = [
    { name: 'hesperia', search: '?city=Hesperia', steps: golden.hesperia },
    { name: 'victorville', search: '?city=Victorville', steps: golden.victorville },
    { name: 'bigBear', search: '?city=Big Bear City', steps: golden.bigBear.default },
    { name: 'bigBear switched away', search: '?city=Big Bear City', steps: golden.bigBear.switchAway },
    { name: 'bigBear observed failed', search: '?city=Big Bear City', steps: golden.bigBear.failed, opts: { snow: 'failed' } },
    { name: 'bigBear few seasons', search: '?city=Big Bear City', steps: golden.bigBear.few, opts: { snow: 'few' } },
    { name: 'bigBear enrichment failed', search: '?city=Big Bear City', steps: golden.bigBear.enrichFailed, opts: { failEnrich: ['7'] } },
    ...Object.entries<string>(inputs.tokens).map(([name, search]) => ({ name: `address ${name}`, search, steps: golden.tokens[name] })),
    { name: 'geo barstow', search: '', steps: golden.geo.barstow, opts: { geo: { lat: 34.9, lon: -117.02 } } },
    { name: 'geo same', search: '', steps: golden.geo.same, opts: { geo: { lat: 34.53, lon: -117.29 } } },
    { name: 'geo after a choice', search: '', steps: golden.geo.afterChoice, opts: { geo: { lat: 34.9, lon: -117.02 } } },
    { name: 'geo denied', search: '', steps: golden.geo.denied, opts: { geo: 'denied' } },
    { name: 'geo in the address', search: '?city=Hesperia', steps: golden.geo.inAddress, opts: { geo: { lat: 34.9, lon: -117.02 } } },
    { name: 'geo with a range', search: '?range=2025&years=2025', steps: golden.geo.withRange, opts: { geo: { lat: 34.9, lon: -117.02 } } },
    { name: 'enrichment failed', search: '?city=Victorville', steps: golden.failures.enrichment, opts: { failEnrich: ['1', '7'] } },
];

describe('City Explorer (city.js)', () => {
    beforeEach(() => takeLog());
    for (const scenario of SCENARIOS) it(scenario.name, async () => replay(scenario));

    it('shows what production shows when the weather data fail to load', async () => {
        const step = golden.failures.weather[0];
        const message = await loader({ cityStatus: 503 })
            .load()
            .catch((e: Error) => e.message);
        expect(step.stats).toBe(`<div class="error-panel">Couldn't load weather data right now. ${message}</div>`);
        expect(step.status).toEqual([
            ['init', 'city'],
            ['status', 'error', 'Data failed to load'],
        ]);
    });

    it('asks for the same data in the same order', async () => {
        const step = golden.hesperia[0];
        const now = NOW;
        const fetched: string[] = [];
        const wd = createWeatherData({
            fetch: async (url: string) => {
                fetched.push(url.replace(/^https:\/\/data\.mojaverivervalley\.org\/v1\//, ''));
                const q = new URL(url).searchParams;
                const body = q.get('table') === 'cities' ? dataInputs.cities : q.get('columns') ? dataInputs.weatherBase : [];

                return { ok: true, status: 200, json: async () => body };
            },
            storage: () => ({ getItem: () => null, setItem() {} }),
            now: () => now,
            warn() {},
        });
        await wd.load();
        await wd.getCityEnrichment('Hesperia');
        await fetchLatest(2, now, async (url) => {
            fetched.push(url.replace(/^https:\/\/data\.mojaverivervalley\.org\/v1\//, ''));

            return { ok: true, status: 200, json: async () => [] };
        });
        expect([...fetched].sort()).toEqual([...step.fetched].sort());
    });

    it('asks for the observed snow once, and tries again after a failure', async () => {
        resetObservedSnow();
        const calls: string[] = [];
        const ok = async (url: string) => {
            calls.push(url);

            return { ok: true, status: 200, json: async () => inputs.snow };
        };
        await loadObservedSnow(ok);
        await loadObservedSnow(ok);
        expect(calls).toHaveLength(1);
        expect(calls[0]).toBe('https://data.mojaverivervalley.org/v1/snow_summary.php?from_year=2017');
        resetObservedSnow();
        await expect(loadObservedSnow(async () => ({ ok: false, status: 503, json: async () => ({}) }))).rejects.toThrow('HTTP 503');
        await expect(loadObservedSnow(async () => ({ ok: true, status: 200, json: async () => ({ seasons: [] }) }))).rejects.toThrow(
            'no observed snow data',
        );
        await expect(loadObservedSnow(ok)).resolves.toBeTruthy();
        resetObservedSnow();
    });
});

describe('Right Now card (live.js)', () => {
    const cards: Array<[string, any]> = [...Object.keys(golden.live).filter((k) => k !== 'failed' && k !== '8')].map((k) => [k, golden.live[k][0]]);
    for (const [id, step] of cards) {
        it(`city ${id}`, () => {
            const rows = inputs.liveByCity[id] ?? [];
            const latest = rows.length ? rows[rows.length - 1] : null;
            const card = liveCard(latest);
            const shown = card ? html(<LiveConditions state={{ status: 'card', card }} />) : '';
            expect(canon(shown), `city ${id}`).toBe(canon(step.live));
        });
    }

    it('shows API text as text, not as markup', () => {
        const rows = inputs.liveByCity['8'];
        const card = liveCard(rows[rows.length - 1]);
        const shown = html(<LiveConditions state={{ status: 'card', card: card as NonNullable<typeof card> }} />);
        expect(shown).toContain('Rain &lt;b&gt;&amp;&lt;/b&gt; wind');
        expect(shown).toContain('Heat &lt;i&gt;advisory&lt;/i&gt; &amp; more');
        expect(golden.live['8'][0].live).toContain('Rain <b>&</b> wind'); // production writes it as markup
    });

    it('stays empty when the request fails or there is no observation', () => {
        expect(golden.live.failed[0].live).toBe('');
        expect(golden.live['3'][0].live).toBe('');
        expect(golden.live['4'][0].live).toBe('');
        expect(liveCard(null)).toBeNull();
    });

    it('names the compass points, the time and the alert severity', () => {
        expect([null, 0, 11.25, 22.5, 348.75, 349, 360, 78].map((d) => compassLabel(d as number | null))).toEqual([
            null,
            'N',
            'NNE',
            'NNE',
            'N',
            'N',
            'N',
            'ENE',
        ]);
        expect(fmtObservedAt('2026-07-23 22:55:00')).toBe('10:55 PM');
        expect(fmtObservedAt('not a time')).toBe('not a time');
        expect(['Red Flag Warning', 'Flood Watch', 'Wind Advisory', 'WARNING', 'a watch', ''].map(alertSeverity)).toEqual([
            'severe',
            'moderate',
            'mild',
            'severe',
            'moderate',
            'mild',
        ]);
    });
});

describe('city helpers', () => {
    it('adds days, shifts years and clamps dates', () => {
        expect(addDays('2024-02-28', 2)).toBe('2024-03-01');
        expect(addDays('2024-03-01', -1)).toBe('2024-02-29');
        expect(shiftYearStr('2024-02-29', -1)).toBe('2023-02-29');
        expect(clampDate('2020-01-01', '2021-01-01', '2022-01-01')).toBe('2021-01-01');
        expect(clampDate('2023-01-01', '2021-01-01', '2022-01-01')).toBe('2022-01-01');
        expect(clampDate('2021-06-01', '2021-01-01', '2022-01-01')).toBe('2021-06-01');
        expect([2023, 2024].map((y) => lastDayOfMonth(y, 2))).toEqual([28, 29]);
    });

    it('bounds the seasons (winter belongs to the year of its January)', () => {
        expect(seasonBounds(2024, 'winter')).toEqual({ start: '2023-12-01', end: '2024-02-29' });
        expect(seasonBounds(2023, 'winter')).toEqual({ start: '2022-12-01', end: '2023-02-28' });
        expect(seasonBounds(2024, 'fall')).toEqual({ start: '2024-09-01', end: '2024-11-30' });
    });

    it('describes a gap between the axis and the data', () => {
        const labels = ['2024-01-01', '2024-01-02', '2024-01-03', '2024-01-04'];
        expect(describeGap(labels, () => true)).toBe('');
        expect(describeGap(labels, (d) => d >= '2024-01-02')).toBe('Partial — data begins 2024-01-02.');
        expect(describeGap(labels, (d) => d <= '2024-01-03')).toBe('Partial — data available through 2024-01-03.');
        expect(describeGap(labels, (d) => d === '2024-01-02')).toBe('Partial — data begins 2024-01-02; data available through 2024-01-02.');
        expect(describeGap([], () => true)).toBe('');
    });

    it('turns a range token into a view and back', () => {
        const bounds = { min: '2017-01-01', max: '2026-10-12' };
        const state = (token: string) =>
            enterCity({ ...stateFromSearch(`?range=${token}`) }, [{ date: '2017-01-01' }, { date: '2026-10-12' }] as DayRow[]);
        for (const token of ['2022', '2024-07', '2025-winter', '2025-06-01_2025-08-15', '30d', '1y', 'all']) {
            const decoded = decodeRangeToken(token, bounds);
            expect(decoded, token).not.toBeNull();
            expect(encodeRangeToken(state(token), bounds), token).toBe(token);
        }
        expect(decodeRangeToken('2024-13', bounds)).toBeNull();
        expect(decodeRangeToken('zzz', bounds)).toBeNull();
        expect(decodeRangeToken('', bounds)).toBeNull();
        expect(decodeRangeToken(null, bounds)).toBeNull();
        expect(computePresetRange('thisYear', bounds)).toEqual({ start: '2026-01-01', end: '2026-10-12' });
        // "This Year" is a plain year in the address
        expect(encodeRangeToken(enterCity(stateFromSearch(''), [{ date: '2017-01-01' }, { date: '2026-10-12' }] as DayRow[]), bounds)).toBe('2026');
    });

    it('steps by the length of a custom range', () => {
        const s = { ...stateFromSearch(''), mode: 'range' as const, range: { start: '2025-06-01', end: '2025-06-10' } };
        expect(computeStepTarget(s, 1).range).toEqual({ start: '2025-06-11', end: '2025-06-20' });
        expect(computeStepTarget(s, -1).range).toEqual({ start: '2025-05-22', end: '2025-05-31' });
    });

    it('builds the address from the state and keeps the parameters it does not own', () => {
        const bounds = { min: '2017-01-01', max: '2026-10-12' };
        const s = enterCity(stateFromSearch('?years=2020,2019&metric=low&zoom=3&avg=7&compare=1'), [
            { date: '2017-01-01' },
            { date: '2019-05-01' },
            { date: '2020-03-01' },
            { date: '2026-10-12' },
        ] as DayRow[]);
        expect(buildSearch('?utm=x&city=Old', 'Hesperia', s, bounds)).toBe(
            'utm=x&city=Hesperia&range=2026&years=2019%2C2020&metric=low&zoom=3&avg=7&compare=1',
        );
        expect(buildSearch('', 'Big Bear City', stateFromSearch(''), bounds)).toBe('city=Big+Bear+City');
    });

    it('caps the overlay at six years and keeps one', () => {
        let s = { ...stateFromSearch(''), overlayYears: [2020] };
        for (const y of [2021, 2022, 2023, 2024, 2025, 2026]) s = toggleOverlayYear(s, y);
        expect(s.overlayYears).toEqual([2020, 2021, 2022, 2023, 2024, 2025]);
        for (const y of [2020, 2021, 2022, 2023, 2024, 2025]) s = toggleOverlayYear(s, y);
        expect(s.overlayYears).toEqual([2025]);
    });

    it('treats a year of 299 days as partial and one of 300 as whole', () => {
        const days = (year: number, n: number) =>
            Array.from({ length: n }, (_, i) => ({
                date: isoDate(new Date(year, 0, 1 + i)),
                tmax: 80,
                tmin: 50,
                fmax: 80,
                fmin: 50,
                precip: 0,
                gust: 5,
                wx: 'Clear',
            })) as DayRow[];
        expect(annualTrendChart([...days(2021, 299), ...days(2022, 300)]).note).toBe(
            'Avg. daily high & low by year. 2021 is a partial year in the data.',
        );
        expect(annualTrendChart([...days(2021, 299), ...days(2022, 299)]).note).toBe(
            'Avg. daily high & low by year. 2021 and 2022 are partial years in the data.',
        );
        expect(annualTrendChart([...days(2021, 300)]).note).toBe('Avg. daily high & low by year.');
    });

    it('applies a picked range that equals the current one when the view is not already a custom range', () => {
        const bounds = { min: '2017-01-01', max: '2026-10-12' };
        const year = enterCity(stateFromSearch('?range=2022'), [{ date: '2017-01-01' }, { date: '2026-10-12' }] as DayRow[]);
        const same = commitPickerRange(year, '2022-01-01', '2022-12-31', bounds);
        expect(same).not.toBe(year);
        expect(same.mode).toBe('range');
        expect(commitPickerRange(same, '2022-01-01', '2022-12-31', bounds)).toBe(same);
    });

    it('colors a zero difference as a rise, as production does', () => {
        const zero = html(<YoyNote yoy={{ kind: 'delta', delta: { avgMaxDiff: 0, avgMinDiff: -0.1, days: 1 } }} />);
        expect(zero).toContain('<strong style="color:var(--color-terracotta-dark)">0°F</strong>');
        expect(zero).toContain('<strong style="color:var(--color-sky-dark)">-0.1°F</strong>');
        expect(zero).toContain('(1 overlapping day)');
    });

    it('measures the nearest city', () => {
        expect(
            findNearestCity(
                34.9,
                -117.02,
                ['Victorville', 'Barstow'],
                (n) => ({ Victorville: { lat: 34.53, lon: -117.29 }, Barstow: { lat: 34.9, lon: -117.02 } })[n],
            )?.name,
        ).toBe('Barstow');
    });
});

describe('observed snow charts', () => {
    it('draws one bar per winter, lighter for an incomplete record', () => {
        const config = snowSeasonsChart(inputs.snow);
        const colors = config.data.datasets[0].backgroundColor as string[];
        expect(colors).toHaveLength(inputs.snow.seasons.length);
        expect(colors.at(-1)).toBe('#2A6FB066');
        expect(colors[0]).toBe('#2A6FB0');
    });
});
