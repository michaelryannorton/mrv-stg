/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { DaysBar, DaysLegend } from '@/components/mrv/air-quality';
import { PanelContent } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { probeAirQuality, probeBands } from './__golden__/chart-probes.mjs';
import { serialize } from './__golden__/chart-stub.mjs';
import {
    AQI_BANDS,
    aqiConfig,
    aqStats,
    bandCeiling,
    bandFor,
    compareConfig,
    daysByCategory,
    DEFAULT_METRIC,
    MAX_COMPARE_YEARS,
    METRICS,
    MMDD_LABELS,
    MONTH_CHOICES,
    MONTH_RANGES,
    normalizeCompareYears,
    ozoneConfig,
    particulatesConfig,
    thresholdBandsPlugin,
    toggleCompareYear,
    UV_BANDS,
    uvConfig,
    withAlpha,
} from './air-quality';
import { asJson, readGolden } from './golden';
import { computeAqiNormals, getYearsPresent, type ApiRow } from './stats';
import { canon } from './test-helpers/html-canon';
import { createWeatherData } from './weather-data';

/**
 * The Air Quality & UV page against the legacy air-quality.js (run unmodified over a fake DOM, a recording Chart.js, a fixed clock
 * and the saved data, through the city selector and every compare control): what it wrote into the page, every chart
 * configuration, what the charts' own functions answer, and what the band-drawing plugin draws on a recording canvas.
 */
const golden = readGolden<any>('phase5-golden.json.gz');
const inputs = readGolden<any>('phase5-inputs.json.gz');
const dataInputs = readGolden<any>('data-inputs.json.gz');
const NOW = inputs.clock as number;
const html = (node: React.ReactElement) => renderToStaticMarkup(node);
const aqiRowsFor: Record<string, ApiRow[]> = { '1': inputs.aq['1'], '2': inputs.edgeAq, '7': inputs.aq['7'] };

function loader(opts: { status?: number; failCity?: string } = {}) {
    const fake = async (url: string) => {
        const q = new URL(url).searchParams;
        if (opts.status) return { ok: false, status: opts.status, json: async () => ({}) };
        if (q.get('table') === 'air_quality_daily' && q.get('city_id') === opts.failCity) return { ok: false, status: 500, json: async () => ({}) };
        const body =
            q.get('table') === 'cities'
                ? dataInputs.cities
                : q.get('table') === 'air_quality_daily'
                  ? aqiRowsFor[q.get('city_id') as string] || []
                  : q.get('columns')
                    ? dataInputs.weatherBase
                    : [];

        return { ok: true, status: 200, json: async () => body };
    };

    return createWeatherData({ fetch: fake, storage: () => ({ getItem: () => null, setItem() {} }), now: () => NOW, warn() {} });
}

const METRIC_OPTIONS = METRICS.map(([key, label]) => ({ key, label }));
const MONTH_OPTIONS = MONTH_CHOICES.map(([key, label]) => ({ key, label }));
const names = (wd: ReturnType<typeof loader>) => wd.getCityNames();

describe('Air Quality & UV (air-quality.js)', () => {
    const scenarios: Array<[string, string, { failCity?: string }]> = [
        ['default', '', {}],
        ['victorville', '?city=Victorville', {}],
        ['query', '?city=Barstow&other=1', {}],
        ['unknownCity', '?city=Nowhere', {}],
        ['edge', '?city=Hesperia', {}],
        ['cityError', '?city=Victorville', { failCity: '2' }],
    ];

    for (const [name, search, opts] of scenarios) {
        it(name, async () => {
            const wd = loader(opts);
            const weather = await wd.load();
            const cityNames = names(wd);
            const cityId = (n: string) => String(wd.getCity(n)?.id);
            const requested = new URLSearchParams(search).get('city');
            let city = requested && cityNames.includes(requested) ? requested : cityNames[0];
            let rows = null as ApiRow[] | null;
            let rowsError = false;
            let chosen = new Set<number>();
            let metric = DEFAULT_METRIC;
            let zoom: number | null = null;
            const steps = golden.airquality[name] as any[];

            const loadRows = async (n: string) => {
                try {
                    rows = await wd.getCityAirQuality<ApiRow[]>(n);
                    rowsError = false;
                    chosen = normalizeCompareYears(chosen, getYearsPresent(rows));
                } catch {
                    rowsError = true;
                }
            };
            await loadRows(city);

            for (const [i, step] of steps.entries()) {
                let newCharts: 'all' | 'compare' | 'none' = i === 0 ? 'all' : 'none';
                if (i > 0) {
                    const [kind, value] = step.action;
                    if (kind === 'city') {
                        city = value;
                        await loadRows(city);
                        newCharts = rowsError ? 'none' : 'all';
                    } else if (kind === 'metric') {
                        metric = value;
                        newCharts = 'compare';
                    } else if (kind === 'month') {
                        zoom = value;
                        newCharts = 'compare';
                    } else if (kind === 'year') {
                        chosen = toggleCompareYear(chosen, value);
                        newCharts = 'compare';
                    }
                }
                const label = `${name}: ${step.step}`;
                expect(`Air Quality & UV — ${city}`, label).toBe(step['aq-title']);
                expect(cityNames.map((n) => `<option value="${n}">${n}</option>`).join(''), label).toBe(step.options);
                expect(step.selected, label).toBe(city);
                expect(
                    canon(
                        html(
                            <PanelContent
                                panel={
                                    rowsError
                                        ? { kind: 'error', text: "Couldn't load this city's air quality data right now." }
                                        : aqStats(rows as ApiRow[])
                                }
                            />,
                        ),
                        'grid',
                    ),
                    label,
                ).toBe(canon(step['aq-stats'], 'grid'));
                if (!rowsError) {
                    const r = rows as ApiRow[];
                    expect(r.length ? `${r.length} days of data` : '', label).toBe(step['aq-updated']);
                    const days = daysByCategory(r);
                    expect(days ? days.note : '', label).toBe(step['aq-days-note']);
                    expect(canon(days ? html(<DaysBar days={days} />) : ''), label).toBe(canon(step['aq-days-bar']));
                    expect(canon(days ? html(<DaysLegend days={days} />) : ''), label).toBe(canon(step['aq-days-legend']));
                    const years = getYearsPresent(r);
                    expect(
                        canon(
                            html(
                                <ToggleButtons
                                    options={years.map((y) => ({ key: String(y), label: String(y) }))}
                                    active={Array.from(chosen).map(String)}
                                    attribute="year"
                                    onChange={() => {}}
                                    typed={false}
                                />,
                            ),
                            'map-controls',
                        ),
                        label,
                    ).toBe(canon(step['compare-year-controls'], 'map-controls'));
                    expect(
                        canon(
                            html(<ToggleButtons options={METRIC_OPTIONS} active={metric} attribute="metric" onChange={() => {}} typed={false} />),
                            'map-controls',
                        ),
                        label,
                    ).toBe(canon(step['compare-metric-controls'], 'map-controls'));
                    expect(
                        canon(
                            html(
                                <ToggleButtons
                                    options={MONTH_OPTIONS}
                                    active={zoom ? String(zoom) : ''}
                                    attribute="zoom-month"
                                    onChange={() => {}}
                                    typed={false}
                                />,
                            ),
                            'map-controls',
                        ),
                        label,
                    ).toBe(canon(step['compare-month-controls'], 'map-controls'));
                    const compare = compareConfig(r, chosen, metric, zoom);
                    expect(compare.gapNote, label).toBe(step['compare-gap-note']);

                    // the charts made in this step, in the order production makes them
                    const normals = computeAqiNormals(r);
                    const made =
                        newCharts === 'all'
                            ? [aqiConfig(normals), particulatesConfig(normals), ozoneConfig(normals), uvConfig(normals), compare.config]
                            : newCharts === 'compare'
                              ? [compare.config]
                              : [];
                    const canvases =
                        newCharts === 'all'
                            ? ['chart-aqi', 'chart-particulates', 'chart-ozone', 'chart-uv', 'chart-compare']
                            : newCharts === 'compare'
                              ? ['chart-compare']
                              : [];
                    expect(
                        step.charts.map((c: any) => c.canvas),
                        label,
                    ).toEqual(canvases);
                    made.forEach((config, k) => {
                        expect(asJson(serialize(config)), `${label} ${canvases[k]}`).toEqual(step.charts[k].config);
                        expect(asJson(probeAirQuality(config)), `${label} ${canvases[k]}`).toEqual(step.charts[k].probes);
                    });
                } else {
                    expect(step.charts, label).toHaveLength(0);
                }
                if (i === 0) {
                    expect(step.status, label).toEqual([
                        ['init', 'airquality'],
                        ['status', weather.stale ? 'error' : '', `Data as of ${weather.fetchedAt.slice(0, 10)}`],
                    ]);
                }
            }
        });
    }

    it('shows what production shows when the weather data fail to load', async () => {
        const step = golden.airquality.weatherError[0];
        const message = await loader({ status: 503 })
            .load()
            .catch((e: Error) => e.message);
        expect(step['aq-stats']).toBe(`<div class="error-panel">Couldn't load weather data right now. ${message}</div>`);
        expect(step.status).toEqual([
            ['init', 'airquality'],
            ['status', 'error', 'Data failed to load'],
        ]);
    });
});

describe('the band-drawing plugin', () => {
    it('has the bands production has, with the same colors and edges', () => {
        expect(asJson(AQI_BANDS)).toEqual(golden.bands.aqiBands);
        expect(asJson(UV_BANDS)).toEqual(golden.bands.uvBands);
    });

    it('draws the same on a recording canvas in every scenario (wide, narrow, data high at either edge, hidden or no data, short axis, no bands)', () => {
        const draws = asJson(probeBands(thresholdBandsPlugin, { aqi: AQI_BANDS, uv: UV_BANDS }));
        expect(Object.keys(draws)).toEqual(Object.keys(golden.bands.draws));
        for (const k of Object.keys(golden.bands.draws)) expect(draws[k], k).toEqual(golden.bands.draws[k]);
    });
});

describe('air quality helpers', () => {
    it('says how many days the trailing year has, and that it is short below 300', () => {
        const rows = (n: number): ApiRow[] =>
            Array.from({ length: n }, (_, i) => ({ date: new Date(Date.UTC(2025, 0, 1 + i)).toISOString().slice(0, 10), aqi_us: 20 }));
        expect(daysByCategory(rows(300))?.note).toBe('Daily peak US AQI, 2024-10-28 to 2025-10-27 (300 days).');
        expect(daysByCategory(rows(299))?.note).toBe('Daily peak US AQI, 2024-10-27 to 2025-10-26 — only 299 days available in this window.');
    });

    it('labels a category with a number only when it is at least 9% of the days', () => {
        // 100 days, the last `n` of them Hazardous (500), the rest Good: the share of Hazardous is n percent
        const rows = (n: number): ApiRow[] =>
            Array.from({ length: 100 }, (_, i) => ({
                date: new Date(Date.UTC(2025, 0, 1 + i)).toISOString().slice(0, 10),
                aqi_us: i >= 100 - n ? 500 : 20,
            }));
        const shown = (n: number) => daysByCategory(rows(n))?.segments.find((s) => s.band.name === 'Hazardous');
        expect(shown(8)?.text).toBe('');
        expect(shown(9)?.text).toBe('9%');
        expect(shown(10)?.text).toBe('10%');
    });

    it('classifies AQI after rounding and UV exactly', () => {
        expect(bandFor(AQI_BANDS, 50.4)?.name).toBe('Good');
        expect(bandFor(AQI_BANDS, 50.5)?.name).toBe('Moderate');
        expect(bandFor(AQI_BANDS, 100.4)?.name).toBe('Moderate');
        expect(bandFor(AQI_BANDS, 501)?.name).toBe('Hazardous');
        expect(bandFor(UV_BANDS, 2.5)?.name).toBe('Low');
        expect(bandFor(UV_BANDS, 2.51)?.name).toBe('Moderate');
        expect(bandFor(UV_BANDS, 11)?.name).toBe('Extreme');
        expect(bandFor(AQI_BANDS, null)).toBeNull();
        expect(bandFor(AQI_BANDS, NaN)).toBeNull();
    });

    it('draws the band that holds the largest value in full', () => {
        expect(bandCeiling(AQI_BANDS, [10, 157, null])).toBe(200);
        expect(bandCeiling(AQI_BANDS, [null])).toBeUndefined();
        expect(bandCeiling(UV_BANDS, [5.5])).toBe(5.5);
    });

    it('tints with an alpha', () => {
        expect(withAlpha('#3E9F6B', 0.13)).toBe('rgba(62,159,107,0.13)');
    });

    it('has a 366-day calendar and the month ranges inside it', () => {
        expect(MMDD_LABELS).toHaveLength(366);
        expect(MMDD_LABELS[59]).toBe('02-29');
        expect(MONTH_RANGES[1]).toEqual([31, 60]);
        expect(MONTH_RANGES[11]).toEqual([335, 366]);
    });

    it('keeps at least one year chosen and at most six', () => {
        const years = [2018, 2019, 2020, 2021, 2022, 2023, 2024];
        let chosen = normalizeCompareYears(new Set(), years);
        expect(Array.from(chosen)).toEqual([2022, 2023, 2024]);
        expect(Array.from(normalizeCompareYears(new Set([2020, 1999]), years))).toEqual([2020]);
        for (const y of [2018, 2019, 2020]) chosen = toggleCompareYear(chosen, y);
        expect(chosen.size).toBe(MAX_COMPARE_YEARS);
        expect(toggleCompareYear(chosen, 2021).size).toBe(MAX_COMPARE_YEARS);
        expect(toggleCompareYear(new Set([2022]), 2022).size).toBe(1);
    });
});
