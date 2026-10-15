/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { describe, expect, it } from 'vitest';
import { runScenarios } from './__golden__/scenarios.mjs';
import { asJson, readGolden } from './golden';
import { createWeatherData } from './weather-data';

/**
 * The port against the legacy public_html/js/data.js: the same scenarios (fresh load, cached, expired, network down,
 * bad response, refused cache write, on-demand fetches...) were run through the legacy code to make the golden file;
 * the port must request the same addresses, write the same cache string, warn the same way and return the same data.
 */
const inputs = readGolden('data-inputs.json.gz');
const golden = readGolden<Record<string, unknown>>('data-golden.json.gz');

describe('weather data layer (port of data.js)', async () => {
    const actual = asJson(
        await runScenarios(
            (env: any) =>
                createWeatherData({
                    fetch: env.fetch,
                    storage: env.storage,
                    now: env.now,
                    warn: env.warn,
                    apiBase: 'https://data.mojaverivervalley.org/v1',
                }),
            inputs,
        ),
    );

    it('covers every scenario in the golden file', () => {
        expect(Object.keys(actual)).toEqual(Object.keys(golden));
        expect(Object.keys(golden).length).toBeGreaterThanOrEqual(14);
    });

    for (const name of Object.keys(golden)) {
        it(`scenario "${name}" matches the legacy code`, () => {
            expect(actual[name]).toEqual(golden[name]);
        });
    }

    it('writes the cache under the legacy key in the legacy compact form', () => {
        const fresh = (actual as any).fresh;
        expect(fresh.writes).toHaveLength(1);
        expect(fresh.writes[0][0]).toBe('mrv_weather_cache_v4');
        const compact = JSON.parse(fresh.writes[0][1]);
        expect(Object.keys(compact)).toEqual(['fetchedAt', 'weatherCodes', 'cities']);
        expect(Array.isArray(compact.cities['Victorville'].rows[0])).toBe(true);
        expect(compact.cities['Victorville'].rows[0]).toHaveLength(8);
    });

    it('keeps the legacy quota-failure behavior: a refused cache write only warns', () => {
        const refused = (actual as any).cacheWriteRefused;
        expect(refused.result.ok).toBe(true);
        expect(refused.warnings).toHaveLength(1);
        expect(refused.warnings[0]).toContain('cache write failed');
    });
});
