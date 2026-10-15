/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { DroughtTable } from '@/components/mrv/drought';
import { MetricLegend } from '@/components/mrv/overview';
import { StatCards } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { probeDrought } from './__golden__/chart-probes.mjs';
import { serialize } from './__golden__/chart-stub.mjs';
import { CATEGORIES, droughtChart, droughtStats, fmtPct, only, RANGES, visibleRows, type DroughtRow } from './drought';
import { asJson, readGolden } from './golden';
import { initRegionMap, renderMarkers } from './leaflet-map';
import { cityPopupHtml, computeCityStats, dataStatusMessage, DEFAULT_METRIC, legendSteps, METRICS, metricValues, regionStats } from './overview';
import { canon } from './test-helpers/html-canon';
import { createWeatherData } from './weather-data';

// Leaflet is replaced by the recording stand-in the legacy scripts ran against to make the golden file.
const log = vi.hoisted(() => [] as unknown[]);
vi.mock('leaflet', async () => {
    const { makeLeaflet } = await import('./__golden__/leaflet-stub.mjs');

    return makeLeaflet(log, { invalidateSize: true, wells: true });
});

/**
 * The Geography Overview and its drought section against the legacy overview.js and drought.js (run unmodified, with data.js,
 * stats.js and map.js, over a fake DOM, a recording Leaflet and Chart.js and a fixed clock, through the metric and time-span
 * buttons): what they wrote into the page, every Leaflet call, the chart configuration and what its functions answer.
 */
const golden = readGolden<any>('phase4-golden.json.gz');
const inputs = readGolden<any>('phase4-inputs.json.gz');
const dataInputs = readGolden<any>('data-inputs.json.gz');
const NOW = inputs.clock as number;
const html = (node: React.ReactElement) => renderToStaticMarkup(node);
const takeLog = () => (log as any[]).splice(0, (log as any[]).length);
const normalizeCalls = (calls: any[]) =>
    asJson(calls)
        .filter(([name]: [string]) => name !== 'new Chart' && name !== 'destroy chart')
        .map(([name, args]: [string, any[]]) =>
            name === 'marker.bindTooltip' || name === 'marker.bindPopup' ? [name, [canon(args[0]), ...args.slice(1)]] : [name, args],
        );

async function loadWeather(status?: number) {
    const fakeFetch = async (url: string) => {
        const q = new URL(url).searchParams;
        if (status) return { ok: false, status, json: async () => ({}) };

        return {
            ok: true,
            status: 200,
            json: async () => (q.get('table') === 'cities' ? dataInputs.cities : q.get('columns') ? dataInputs.weatherBase : []),
        };
    };

    return createWeatherData({ fetch: fakeFetch, storage: () => ({ getItem: () => null, setItem() {} }), now: () => NOW, warn() {} }).load();
}

const METRIC_OPTIONS = Object.entries(METRICS).map(([key, m]) => ({ key, label: m.label }));

describe('Geography Overview (overview.js and drought.js)', () => {
    for (const variant of ['real', 'empty'] as const) {
        it(`${variant}: region map, numbers and drought through every control`, async () => {
            const steps = golden.overview[variant] as any[];
            const droughtRows = inputs.drought[variant] as DroughtRow[];
            const weather = await loadWeather();
            const { names, stats } = computeCityStats(weather.cities);
            const points = names.map((name) => ({ name, lat: stats[name].lat, lon: stats[name].lon }));
            let metric = DEFAULT_METRIC;
            let rangeKey = 'all';
            let handle: ReturnType<typeof initRegionMap> | null = null;
            takeLog();

            const draw = () => {
                const popup = (p: { name: string }, value: number | null | undefined) =>
                    cityPopupHtml(p.name, value, metric, stats[p.name], 'city.html?city=' + encodeURIComponent(p.name));

                return renderMarkers((handle as any).markers, points, metricValues(metric, names, stats), { formatPopup: popup as any });
            };

            for (const [i, step] of steps.entries()) {
                if (i === 0) {
                    handle = initRegionMap('map', points);
                    draw();
                } else if (step.step.startsWith('metric ')) {
                    metric = step.step.slice(7);
                    draw();
                } else if (step.step.startsWith('range ')) {
                    rangeKey = step.step.slice(6);
                }

                // the region map and the numbers
                const regional = regionStats(names, stats);
                expect(
                    canon(
                        html(<ToggleButtons options={METRIC_OPTIONS} active={metric} attribute="metric" onChange={() => {}} typed={false} />),
                        'map-controls',
                    ),
                    step.step,
                ).toBe(canon(step.metrics, 'map-controls'));
                expect(canon(html(<StatCards cards={regional.cards} />), 'grid'), step.step).toBe(canon(step.regionStats, 'grid'));
                expect(regional.note, step.step).toBe(step.note);
                const range = (() => {
                    const values = Object.values(metricValues(metric, names, stats)).filter((v): v is number => v != null);
                    return values.length ? { min: Math.min(...values), max: Math.max(...values) } : { min: null, max: null };
                })();
                const legend = legendSteps(range.min, range.max, metric);
                expect(canon(legend ? html(<MetricLegend legend={legend} />) : '', 'map-legend'), step.step).toBe(canon(step.legend, 'map-legend'));
                expect(normalizeCalls(takeLog()), step.step).toEqual(normalizeCalls(step.calls));

                // the footer's line
                expect(step.status, step.step).toEqual(
                    i === 0
                        ? [
                              ['init', 'overview'],
                              ['status', weather.stale ? 'error' : '', dataStatusMessage(weather.fetchedAt, weather.fromCache, weather.stale)],
                          ]
                        : [],
                );

                // the drought section
                const d = step.drought;
                if (!droughtRows.length) {
                    expect(d.loading, step.step).toEqual({ hidden: false, className: 'error-panel', text: 'Drought data has not been loaded yet.' });
                    expect(step.charts, step.step).toHaveLength(0);
                    continue;
                }
                expect(d.loading.hidden && d.body.hidden === false, step.step).toBe(true);
                expect(canon(html(<StatCards cards={droughtStats(droughtRows)} />), 'grid'), step.step).toBe(canon(d.stats, 'grid'));
                expect(
                    canon(html(<ToggleButtons options={RANGES} active={rangeKey} attribute="range" onChange={() => {}} />), 'map-controls'),
                    step.step,
                ).toBe(canon(d.ranges, 'map-controls'));
                expect(canon(html(<DroughtTable rows={droughtRows} />)), step.step).toBe(canon(d.table));
                // a chart is made first and again each time the time span changes
                const made = i === 0 || step.step.startsWith('range ');
                expect(step.charts, step.step).toHaveLength(made ? 1 : 0);
                if (made) {
                    const config = droughtChart(visibleRows(droughtRows, rangeKey));
                    expect(asJson(serialize(config)), step.step).toEqual(step.charts[0].config);
                    expect(asJson(probeDrought(config)), step.step).toEqual(step.charts[0].probes);
                }
            }
        });
    }

    it('shows what production shows when a request fails', async () => {
        expect(golden.overview.droughtError[0].drought.loading).toEqual({
            hidden: false,
            className: 'error-panel',
            text: 'Drought data could not be loaded right now.',
        });
        const weatherError = golden.overview.weatherError[0];
        await expect(loadWeather(503)).rejects.toThrow();
        const message = await loadWeather(503).catch((e: Error) => e.message);
        expect(weatherError.map).toBe(`<div class="error-panel">Couldn't load weather data right now. ${message}</div>`);
        expect(weatherError.status).toEqual([
            ['init', 'overview'],
            ['status', 'error', 'Data failed to load'],
        ]);
        expect(weatherError.regionStats).toContain('Crunching a decade of daily records');
    });
});

describe('overview helpers', () => {
    it('words the footer line for fresh, cached and offline data', () => {
        expect(dataStatusMessage('2026-10-14T20:01:02.000Z', false, false)).toBe('Data as of 2026-10-14');
        expect(dataStatusMessage('2026-10-14T20:01:02.000Z', true, false)).toBe('Data as of 2026-10-14 (cached)');
        expect(dataStatusMessage('2026-10-14T20:01:02.000Z', true, true)).toBe('Data as of 2026-10-14 (offline copy — refresh failed)');
    });

    it('leaves 2016 out of every city summary, and only 2016', () => {
        const row = (date: string) => ({ date, tmax: 80, tmin: 50, fmax: 80, fmin: 50, precip: 0, gust: 10, wx: 'Clear sky' });
        const rows = ['2015-12-31', '2016-07-17', '2016-12-31', '2017-01-01'].map(row);
        const { stats } = computeCityStats({ Testville: { id: 1, lat: 1, lon: 2, lastChecked: null, rows } });
        expect(stats.Testville.rows.map((r) => r.date)).toEqual(['2015-12-31', '2017-01-01']);
    });
});

describe('drought helpers', () => {
    const row = (d0: number, d1: number, d2: number, d3: number, d4: number): DroughtRow => ({
        date: '2024-01-02',
        d0_pct: d0,
        d1_pct: d1,
        d2_pct: d2,
        d3_pct: d3,
        d4_pct: d4,
    });

    it('turns the cumulative percentages into single bands, never below zero', () => {
        const r = row(80, 60, 40, 10, 2);
        expect(CATEGORIES.map((_, i) => only(r, i))).toEqual([20, 20, 30, 8, 2]);
        expect(only(row(10, 20, 0, 0, 0), 0)).toBe(0); // the data can be out of order
    });

    it('writes small shares as under one percent', () => {
        expect([0, 0.2, 0.99, 1, 49.5, 100].map(fmtPct)).toEqual(['0%', '<1%', '<1%', '1%', '50%', '100%']);
    });

    it('cuts the time span by years back from the latest week', () => {
        const rows = ['2000-01-04', '2015-06-30', '2019-12-31', '2020-01-01', '2024-06-04'].map((date) => ({ ...row(1, 1, 1, 1, 1), date }));
        expect(visibleRows(rows, 'all')).toHaveLength(5);
        expect(visibleRows(rows, '5').map((r) => r.date)).toEqual(['2019-12-31', '2020-01-01', '2024-06-04']);
        expect(visibleRows(rows, '10').map((r) => r.date)).toEqual(['2015-06-30', '2019-12-31', '2020-01-01', '2024-06-04']);
    });

    it('counts the weeks that were mostly in drought', () => {
        const rows = [row(80, 60, 0, 0, 0), row(80, 50, 0, 0, 0), row(10, 0, 0, 0, 0)];
        expect(droughtStats(rows)[3]).toMatchObject({ value: '33%' });
    });
});
