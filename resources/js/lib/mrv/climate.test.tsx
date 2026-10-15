/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { ModelTable, SourceNote } from '@/components/mrv/climate';
import { StatCards } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { probeClimate } from './__golden__/chart-probes.mjs';
import { serialize } from './__golden__/chart-stub.mjs';
import {
    CITIES,
    climateStats,
    DEFAULT_METRIC,
    fetchClimate,
    fmt,
    loadObserved,
    median,
    METRIC_ORDER,
    METRICS,
    monthConfig,
    observedValue,
    periodMean,
    realityCards,
    rolling,
    signed,
    spread,
    yearConfig,
    type ClimateData,
} from './climate';
import { asJson, readGolden } from './golden';
import { canon } from './test-helpers/html-canon';

/**
 * The Climate Projections page against the legacy climate.js (run unmodified over a fake DOM, a recording Chart.js and the saved
 * response, through the community selector and the measure buttons): what it wrote into the page, every chart configuration and
 * what the chart functions answer, including the communities that have no projections yet and a response with gaps.
 */
const golden = readGolden<any>('phase5-golden.json.gz');
const inputs = readGolden<any>('phase5-inputs.json.gz');
const html = (node: React.ReactElement) => renderToStaticMarkup(node);
const METRIC_OPTIONS = METRIC_ORDER.map((k) => ({ key: k, label: METRICS[k].label }));

async function observedFor(cityId: number, status?: number) {
    const fake = async () => ({ ok: !status, status: status ?? 200, json: async () => inputs.climateObserved });

    return loadObserved(cityId, fake);
}

const SCENARIOS: Array<[string, string, Record<string, ClimateData>]> = [
    ['default', '', {}],
    ['barstow', '?city=bArStOw', {}],
    ['sparse', '', { '2': inputs.climateSparse }],
];

describe('Climate Projections (climate.js)', () => {
    for (const [name, search, byCity] of SCENARIOS) {
        it(name, async () => {
            const steps = golden.climate[name] as any[];
            const wanted = new URLSearchParams(search).get('city');
            let cityId = (CITIES.find((c) => c.name.toLowerCase() === (wanted || '').toLowerCase()) || CITIES[0]).id;
            let metric = DEFAULT_METRIC;
            let shown: { data: ClimateData; cityId: number } | null = null;
            let loaded: ClimateData | null = null;
            const load = (id: number): ClimateData | null => {
                const data = byCity[String(id)] || (id === 1 ? inputs.climate1 : { snapshot: null });
                return data.snapshot ? data : null;
            };

            for (const [i, step] of steps.entries()) {
                let made = false;
                if (i === 0) {
                    loaded = load(cityId);
                    made = !!loaded;
                } else {
                    const [kind, value] = step.action;
                    if (kind === 'city') {
                        cityId = value;
                        loaded = load(cityId);
                        made = !!loaded;
                    } else if (kind === 'metric') {
                        metric = value;
                        made = true;
                    }
                }
                if (loaded) shown = { data: loaded, cityId };
                const label = `${name}: ${step.step}`;
                expect(step.city, label).toBe(String(cityId));
                expect(CITIES.map((c) => `<option value="${c.id}">${c.name}</option>`).join(''), label).toBe(step['cl-city']);
                expect(step.loading.hidden, label).toBe(!!loaded);
                expect(step.hidden['cl-body'], label).toBe(!loaded);
                if (!loaded) expect(step.loading.text, label).toBe('Projections for this community have not been loaded yet.');
                if (!shown) continue;

                const stats = climateStats(shown.data, shown.cityId);
                expect(stats.sub, label).toBe(step['cl-sub']);
                expect(stats.note, label).toBe(step['cl-stats-note']);
                expect(canon(html(<StatCards cards={stats.cards} />), 'grid'), label).toBe(canon(step['cl-stats'], 'grid'));
                expect(
                    canon(html(<ToggleButtons options={METRIC_OPTIONS} active={metric} attribute="metric" onChange={() => {}} />), 'map-controls'),
                    label,
                ).toBe(canon(step['cl-metric-controls'], 'map-controls'));
                expect(canon(html(<ModelTable data={shown.data} />)), label).toBe(canon(step['cl-table']));
                expect(canon(html(<SourceNote data={shown.data} />)), label).toBe(canon(step['cl-source']));

                // the charts made in this step: both when a community loads, the year chart when the measure changes
                const everything = i === 0 || step.action?.[0] === 'city';
                const configs = made
                    ? everything
                        ? [yearConfig(shown.data, metric), monthConfig(shown.data)]
                        : [yearConfig(shown.data, metric)]
                    : [];
                expect(
                    step.charts.map((c: any) => c.canvas),
                    label,
                ).toEqual(configs.length === 2 ? ['chart-cl-year', 'chart-cl-month'] : configs.length === 1 ? ['chart-cl-year'] : []);
                configs.forEach((config, k) => {
                    expect(asJson(serialize(config)), `${label} chart ${k}`).toEqual(step.charts[k].config);
                    expect(asJson(probeClimate(config)), `${label} chart ${k}`).toEqual(step.charts[k].probes);
                });

                // the reality check (recorded weather arrives after the figures)
                const observed = load(shown.cityId) === shown.data ? await observedFor(shown.cityId) : null;
                const reality = realityCards(shown.data, metric, observed);
                expect(canon(reality ? html(<StatCards cards={reality.cards} />) : '', 'grid'), label).toBe(
                    canon(loaded ? step['cl-reality'] : step['cl-reality'], 'grid'),
                );
                if (loaded) expect(reality ? reality.note : '', label).toBe(step['cl-reality-note']);
            }
        });
    }

    it('says what production says when a request fails', async () => {
        expect(golden.climate.summaryError[0].loading).toEqual({
            hidden: false,
            className: 'error-panel',
            text: 'Climate projections could not be loaded right now.',
        });
        await expect(fetchClimate(1, async () => ({ ok: false, status: 503, json: async () => ({}) }))).rejects.toThrow('HTTP 503');
        // a failed recorded-weather request leaves the reality check empty
        expect(golden.climate.observedError[0]['cl-reality']).toBe('');
        await expect(observedFor(1, 500)).rejects.toThrow('HTTP 500');
    });
});

describe('climate helpers', () => {
    it('writes a signed change, never "−0"', () => {
        expect(signed(0.04)).toBe('0.0');
        expect(signed(-0.04)).toBe('0.0');
        expect(signed(2.35)).toBe('+2.4');
        expect(signed(-2.35)).toBe('−2.4');
        expect(signed(1234.5, 0)).toBe('+1,235');
        expect(fmt(1234.567, 1)).toBe('1,234.6');
    });

    it('takes medians and spreads', () => {
        expect(median([3, 1, 2])).toBe(2);
        expect(median([1, 2, 3, 4])).toBe(2.5);
        expect(spread([null, 3, 1, 2])).toEqual([1, 2, 3]);
        expect(spread([null, null])).toBeNull();
    });

    it('averages ten years and leaves the start empty', () => {
        const r = rolling([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
        expect(r.slice(0, 9)).toEqual(Array(9).fill(null));
        expect(r[9]).toBe(5.5);
        expect(r[10]).toBe(6.5);
        expect(rolling([1, 2, 3, 4, 5, 6, 7, 8, 9, null, 11])[10]).toBeNull();
    });

    it('averages a model over a period only when every year is there', () => {
        const data = { years: [2000, 2001, 2002], models: ['m'], annual: { m: [{ x: 1 }, { x: 3 }, null] } } as unknown as ClimateData;
        expect(periodMean(data, 'm', 'x', [2000, 2001])).toBe(2);
        expect(periodMean(data, 'm', 'x', [2000, 2002])).toBeNull();
        expect(periodMean(data, 'm', 'x', [2010, 2011])).toBeNull();
    });

    it('counts only years with essentially every day recorded', () => {
        const day = (max: number) => ({ temp_max_f: max, temp_min_f: 40, precipitation_in: 0.01 });
        const full = Array.from({ length: 360 }, (_, i) => day(i < 10 ? 101 : 90));
        const short = Array.from({ length: 359 }, () => day(120));
        expect(observedValue('days_ge_100', { 2020: full, 2021: short })).toEqual({ value: 10, years: 1 });
        expect(observedValue('days_ge_100', { 2021: short })).toBeNull();
        expect(observedValue('precip_in', { 2020: full })?.value).toBeCloseTo(3.6, 10);
        expect(observedValue('tmax_f', { 2020: full })?.value).toBeCloseTo((10 * 101 + 350 * 90) / 360, 10);
    });
});
