/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { describe, expect, it, vi } from 'vitest';
import { runMapScenarios } from './__golden__/leaflet-stub.mjs';
import { asJson, readGolden } from './golden';
import { destroyMap, initMiniMap, initRegionMap, renderMarkers, type RegionMapHandle } from './leaflet-map';
import { COLOR_STOPS, colorForValue, radiusForValue, TILE_ATTRIBUTION, TILE_URL } from './region-map';

// Leaflet is replaced by a recording stand-in (the same one the legacy map.js ran against to make the golden file).
const log = vi.hoisted(() => [] as unknown[]);
vi.mock('leaflet', async () => {
    const { makeLeaflet } = await import('./__golden__/leaflet-stub.mjs');

    return makeLeaflet(log);
});

/** The marker scales and the Leaflet calls against the legacy public_html/js/map.js (RegionMap). */
const golden = readGolden<any>('map-golden.json.gz');

describe('region map (port of map.js)', () => {
    it('has the legacy constants', () => {
        expect(asJson({ COLOR_STOPS, TILE_URL, TILE_ATTRIBUTION })).toEqual(golden.constants);
    });

    it(`color and size scales match the legacy code on ${golden.scale.length} inputs`, () => {
        expect(golden.scale.length).toBeGreaterThan(50);
        for (const [[v, mn, mx], marker, color] of golden.scale) {
            // the legacy probe drew markers for [mn, mx, v], so the scale's own min and max include v
            const all = [mn, mx, v].filter((x) => x != null);
            const min = Math.min(...all);
            const max = Math.max(...all);
            expect(colorForValue(v, min, max)).toBe(marker.color);
            expect(colorForValue(v, mn, mx)).toBe(color);
            expect(radiusForValue(v, min, max)).toBeCloseTo(marker.radius, 12);
        }
    });

    it('makes the same Leaflet calls as the legacy code in every scenario', () => {
        let handle: RegionMapHandle | null = null;
        const api = {
            init: (container: string, points: any) => {
                handle = initRegionMap(container, points);

                return handle.map;
            },
            render: (points: any, values: any, opts: any) => renderMarkers((handle as RegionMapHandle).markers, points, values, opts),
            initMini: (container: string, lat: number | null, lon: number | null, label: string) => initMiniMap(container, lat, lon, label),
            destroy: (map: any) => destroyMap(map),
        };
        const actual = asJson(runMapScenarios(api, log as any[]));

        expect(Object.keys(actual)).toEqual(Object.keys(golden.scenarios));
        for (const name of Object.keys(golden.scenarios)) expect(actual[name], name).toEqual(golden.scenarios[name]);
    });
});
