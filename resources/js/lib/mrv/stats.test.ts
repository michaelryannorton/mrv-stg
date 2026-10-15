/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { describe, expect, it, vi } from 'vitest';
import { asJson, readGolden } from './golden';
import type { ApiRow, DayRow } from './stats';
import * as S from './stats';

/**
 * The port against the legacy public_html/js/stats.js: every function was run through the legacy code, unmodified,
 * over Victorville's real saved history (base rows, enrichment rows, air quality) and over hand-made edge inputs, and
 * the outputs saved. The port must give identical outputs.
 */
const inputs = readGolden<{
    base: DayRow[];
    enrichment: ApiRow[];
    airQuality: ApiRow[];
    edgeBase: Record<string, DayRow[]>;
    edgeApi: Record<string, ApiRow[]>;
}>('stats-inputs.json.gz');
const golden = readGolden<any>('stats-golden.json.gz');

// Same calls, same order as port/phase-2/tools/make_golden.mjs (computeSkyGroups warns once per unknown condition).
const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
const b = inputs.base;
const e = inputs.enrichment;
const a = inputs.airQuality;
const real = {
    dateRange: S.getDateRange(b),
    yearsPresent: S.getYearsPresent(b),
    records: S.computeRecords(b),
    monthlyNormals: S.computeMonthlyNormals(b),
    skyGroups: S.computeSkyGroups(b),
    conditionFrequency: S.computeConditionFrequency(b),
    yearlyAverages: S.computeYearlyAverages(b),
    annualSummary: S.computeAnnualSummary(b),
    solarNormals: S.computeSolarNormals(e),
    windRose: S.computeWindRose(e),
    snowNormals: S.computeSnowNormals(e),
    soilNormals: S.computeSoilNormals(e),
    vpdNormals: S.computeVpdNormals(e),
    frostWindow: S.computeFrostWindow(e),
    aqiNormals: S.computeAqiNormals(a),
};
const edge: Record<string, unknown> = {};
for (const k of Object.keys(inputs.edgeBase)) {
    const r = inputs.edgeBase[k];
    edge['base:' + k] = {
        skyGroups: S.computeSkyGroups(r),
        records: S.computeRecords(r),
        monthly: S.computeMonthlyNormals(r),
        yearly: S.computeYearlyAverages(r),
        annual: S.computeAnnualSummary(r),
        freq: S.computeConditionFrequency(r),
        range: S.getDateRange(r),
        years: S.getYearsPresent(r),
    };
}
for (const k of Object.keys(inputs.edgeApi)) {
    const r = inputs.edgeApi[k];
    edge['api:' + k] = {
        solar: S.computeSolarNormals(r),
        wind: S.computeWindRose(r),
        snow: S.computeSnowNormals(r),
        soil: S.computeSoilNormals(r),
        vpd: S.computeVpdNormals(r),
        frost: S.computeFrostWindow(r),
        aqi: S.computeAqiNormals(r),
    };
}
const warnings = warn.mock.calls.map((c) => c.join(' '));
warn.mockRestore();

describe('statistics (port of stats.js)', () => {
    it('ran on the real Victorville history', () => {
        expect(inputs.base.length).toBeGreaterThan(3000);
        expect(inputs.enrichment.length).toBeGreaterThan(3000);
        expect(inputs.airQuality.length).toBeGreaterThan(1000);
    });

    for (const name of Object.keys(golden.real)) {
        it(`${name} on real data matches the legacy code`, () => {
            expect(asJson((real as any)[name])).toEqual(golden.real[name]);
        });
    }

    for (const name of Object.keys(golden.edge)) {
        it(`edge input ${name} matches the legacy code`, () => {
            expect(asJson(edge[name])).toEqual(golden.edge[name]);
        });
    }

    it('warns once about an unmapped weather condition, like the legacy code', () => {
        expect(warnings).toEqual(golden.warnings);
        expect(warnings).toHaveLength(1);
    });

    it('helper functions match the legacy code', () => {
        const undef = (v: unknown) => (v === '__undefined__' ? undefined : v);
        for (const [args, expected] of golden.helpers.round) expect(asJson((S.round as any)(...args.map(undef)))).toEqual(expected);
        for (const [args, expected] of golden.helpers.mean) expect(asJson((S.mean as any)(...args.map(undef)))).toEqual(expected);
        for (const [v, expected] of golden.helpers.num) expect(asJson(S.num(undef(v)))).toEqual(expected);
        for (const [d, expected] of golden.helpers.dateParts) expect(S.dateParts(d)).toEqual(expected);
        for (const [d, expected] of golden.helpers.dayOfYearToLabel)
            expect(S.dayOfYearToLabel(undef(d) as number | null | undefined)).toEqual(expected);
        expect(S.filterByYear(b, 2023).length + ':' + S.excludeYear(b, 2023).length).toBe(golden.helpers.filterByYear);
        expect(asJson({ COMPASS_16: S.COMPASS_16, SOIL_DEPTHS: S.SOIL_DEPTHS })).toEqual(golden.helpers.constants);
    });
});
