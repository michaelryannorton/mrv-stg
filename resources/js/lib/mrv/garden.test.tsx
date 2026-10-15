/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { PanelContent } from '@/components/mrv/stat-card';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { probeGarden } from './__golden__/chart-probes.mjs';
import { serialize } from './__golden__/chart-stub.mjs';
import {
    depthColors,
    ET0_NOTE,
    et0Config,
    findNearestCity,
    frostCards,
    haversineMiles,
    soilMoistureConfig,
    soilTempConfig,
    vpdConfig,
} from './garden';
import { asJson, readGolden } from './golden';
import { computeFrostWindow, computeSoilNormals, computeSolarNormals, computeVpdNormals, excludeYear, type ApiRow } from './stats';
import { canon } from './test-helpers/html-canon';
import { createWeatherData } from './weather-data';

/**
 * The Garden & Grow page against the legacy garden.js (run unmodified over a fake DOM, a recording Chart.js, a fake geolocation and
 * the saved data, through the city selector): what it wrote into the page, every chart configuration, what the tooltip says, and
 * when the browser's position moves the page to the nearest city.
 */
const golden = readGolden<any>('phase5-golden.json.gz');
const inputs = readGolden<any>('phase5-inputs.json.gz');
const dataInputs = readGolden<any>('data-inputs.json.gz');
const NOW = inputs.clock as number;
const html = (node: React.ReactElement) => renderToStaticMarkup(node);

function loader(status?: number) {
    const fake = async (url: string) => {
        const q = new URL(url).searchParams;
        if (status) return { ok: false, status, json: async () => ({}) };
        const body =
            q.get('table') === 'cities'
                ? dataInputs.cities
                : q.get('columns')
                  ? dataInputs.weatherBase
                  : inputs.enrich[q.get('city_id') as string] || [];

        return { ok: true, status: 200, json: async () => body };
    };

    return createWeatherData({ fetch: fake, storage: () => ({ getItem: () => null, setItem() {} }), now: () => NOW, warn() {} });
}

const SCENARIOS: Array<[string, string, 'none' | 'denied' | { lat: number; lon: number }]> = [
    ['default', '', 'none'],
    ['geolocated', '', { lat: 34.9, lon: -117.02 }],
    ['geolocatedSame', '', { lat: 34.58, lon: -117.41 }],
    ['geolocatedAfterChoice', '', { lat: 34.9, lon: -117.02 }],
    ['denied', '', 'denied'],
    ['inAddress', '?city=Big Bear City', { lat: 34.9, lon: -117.02 }],
    ['victorville', '?city=Victorville', 'none'],
];

describe('Garden & Grow (garden.js)', () => {
    for (const [name, search, geo] of SCENARIOS) {
        it(name, async () => {
            const wd = loader();
            const weather = await wd.load();
            const cityNames = wd.getCityNames();
            const requested = new URLSearchParams(search).get('city');
            const initial = requested && cityNames.includes(requested) ? requested : cityNames[0];
            let city = initial;
            let userChanged = false;
            const steps = golden.garden[name] as any[];

            for (const [i, step] of steps.entries()) {
                let drawn = i === 0;
                if (i > 0) {
                    const [kind, value] = step.action;
                    if (kind === 'city') {
                        city = value;
                        userChanged = true;
                        drawn = true;
                    } else if (kind === 'position' && typeof geo === 'object' && !requested && !userChanged) {
                        const nearest = findNearestCity(geo.lat, geo.lon, cityNames, (n) => wd.getCity(n));
                        if (nearest && nearest.name !== initial) {
                            city = nearest.name;
                            drawn = true;
                        }
                    }
                }
                const label = `${name}: ${step.step}`;
                const data = wd.getCity(city);
                const raw = await wd.getCityEnrichment<ApiRow[]>(city);
                const rows = excludeYear(raw, 2016);
                expect(`Garden & Grow — ${city}`, label).toBe(step['garden-title']);
                expect(data?.lastChecked ? `Data last checked ${data.lastChecked}` : '', label).toBe(step['garden-updated']);
                expect(step.selected, label).toBe(city);
                expect(canon(html(<PanelContent panel={{ kind: 'cards', cards: frostCards(computeFrostWindow(rows)) }} />), 'grid'), label).toBe(
                    canon(step['garden-frost-stats'], 'grid'),
                );
                expect(cityNames.map((n) => `<option value="${n}">${n}</option>`).join(''), label).toBe(step.options);
                expect(step.geoCalls, label).toBe(geo === 'none' || requested ? 0 : 1);
                if (i === 0)
                    expect(step.status, label).toEqual([
                        ['init', 'garden'],
                        ['status', weather.stale ? 'error' : '', `Data as of ${weather.fetchedAt.slice(0, 10)}`],
                    ]);

                const made = drawn
                    ? [
                          et0Config(computeSolarNormals(rows)),
                          vpdConfig(computeVpdNormals(rows)),
                          soilTempConfig(computeSoilNormals(rows)),
                          soilMoistureConfig(computeSoilNormals(rows)),
                      ]
                    : [];
                const canvases = drawn ? ['chart-et0', 'chart-vpd', 'chart-soil-temp', 'chart-soil-moisture'] : [];
                expect(
                    step.charts.map((c: any) => c.canvas),
                    label,
                ).toEqual(canvases);
                made.forEach((config, k) => {
                    expect(asJson(serialize(config)), `${label} ${canvases[k]}`).toEqual(step.charts[k].config);
                    expect(asJson(probeGarden(config)), `${label} ${canvases[k]}`).toEqual(step.charts[k].probes);
                });
                expect(drawn ? ET0_NOTE : step['et0-note'], label).toBe(step['et0-note']);
            }
        });
    }

    it('shows what production shows when the weather data fail to load', async () => {
        const step = golden.garden.weatherError[0];
        const message = await loader(503)
            .load()
            .catch((e: Error) => e.message);
        expect(step['garden-frost-stats']).toBe(`<div class="error-panel">Couldn't load weather data right now. ${message}</div>`);
        expect(step.status).toEqual([
            ['init', 'garden'],
            ['status', 'error', 'Data failed to load'],
        ]);
    });
});

describe('garden helpers', () => {
    it('measures miles between two points', () => {
        expect(haversineMiles(34.5, -117.3, 34.5, -117.3)).toBe(0);
        expect(haversineMiles(34.5, -117.3, 35.5, -117.3)).toBeCloseTo(69.0941, 3);
    });

    it('finds the nearest city and skips a city without coordinates', () => {
        const cities: Record<string, { lat: number | null; lon: number | null }> = {
            A: { lat: 34, lon: -117 },
            B: { lat: 35, lon: -117 },
            C: { lat: null, lon: null },
        };
        const get = (n: string) => cities[n];
        expect(findNearestCity(34.9, -117, ['A', 'B', 'C'], get)?.name).toBe('B');
        expect(findNearestCity(34.1, -117, ['A', 'B', 'C'], get)?.name).toBe('A');
        expect(findNearestCity(34, -117, ['C'], get)).toBeNull();
        expect(findNearestCity(34, -117, [], get)).toBeNull();
        // two cities the same distance away: the first one listed wins
        const twins = { X: { lat: 34, lon: -117 }, Y: { lat: 34, lon: -117 } } as Record<string, { lat: number; lon: number }>;
        expect(findNearestCity(35, -117, ['X', 'Y'], (n) => twins[n])?.name).toBe('X');
    });

    it('ramps the soil depth colors from light (shallow) to dark (deep)', () => {
        expect(depthColors(18)).toEqual(['hsl(18, 50%, 75%)', 'hsl(18, 50%, 62%)', 'hsl(18, 50%, 48%)', 'hsl(18, 50%, 35%)']);
    });
});
