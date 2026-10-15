/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { AreaButtons, ChangesTable, StatusLegend } from '@/components/mrv/groundwater';
import { StatCards } from '@/components/mrv/stat-card';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { probeGroundwater } from './__golden__/chart-probes.mjs';
import { serialize } from './__golden__/chart-stub.mjs';
import { asJson, readGolden } from './golden';
import {
    AREAS,
    decimalYear,
    DEFAULT_AREA,
    describeChange,
    drawnWells,
    fetchReadings,
    fetchSummary,
    fmtDepth,
    gwStats,
    mapNote,
    median,
    rankedWells,
    startWell,
    STATUS_ORDER,
    visibleWells,
    wellChart,
    wellFacts,
    wellSub,
    type Reading,
    type Summary,
    type Well,
    type WellStatus,
} from './groundwater';
import { createMarkerMap, drawWellMarkers, fitToPoints, selectWellMarker, type WellMarkers } from './leaflet-map';
import { canon } from './test-helpers/html-canon';

// Leaflet is replaced by the recording stand-in the legacy script ran against to make the golden file.
const log = vi.hoisted(() => [] as unknown[]);
const handlers = vi.hoisted(() => ({ list: [] as Array<() => void> }));
vi.mock('leaflet', async () => {
    const { makeLeaflet } = await import('./__golden__/leaflet-stub.mjs');

    return makeLeaflet(log, { invalidateSize: true, wells: true, handlers });
});

/**
 * The Groundwater page against the legacy groundwater.js (run unmodified over a fake DOM, a recording Leaflet and Chart.js,
 * through the area buttons, the legend, the table and the markers): what it wrote into the page, every Leaflet call, every
 * chart configuration and what the chart's own functions answer.
 */
const golden = readGolden<any>('phase4-golden.json.gz');
const inputs = readGolden<any>('phase4-inputs.json.gz');
const html = (node: React.ReactElement) => renderToStaticMarkup(node);
const takeLog = () => (log as any[]).splice(0, (log as any[]).length);
// Chart creation and removal are in the same log; the charts are compared on their own (configuration and what its functions answer).
const normalizeCalls = (calls: any[]) =>
    asJson(calls)
        .filter(([name]: [string]) => name !== 'new Chart' && name !== 'destroy chart')
        .map(([name, args]: [string, any[]]) =>
            name === 'marker.bindTooltip' || name === 'marker.bindPopup' ? [name, [canon(args[0]), ...args.slice(1)]] : [name, args],
        );

describe('Groundwater (groundwater.js)', () => {
    for (const variant of ['real', 'edge'] as const) {
        it(variant, async () => {
            const {
                summary,
                levels,
                failLevels = [],
            } = inputs.groundwater[variant] as { summary: Summary; levels: Record<string, Reading[]>; failLevels?: string[] };
            const steps = golden.groundwater[variant] as any[];
            const wells = summary.wells;
            let area = DEFAULT_AREA;
            let active = new Set<WellStatus>(STATUS_ORDER);
            let selected: string | null = null;
            let markers: WellMarkers = new Map();
            let map: ReturnType<typeof createMarkerMap> | null = null;
            handlers.list.length = 0;
            takeLog();

            const select = async (siteId: string) => {
                selected = siteId;
                selectWellMarker(markers, wells, siteId);
                const well = wells.find((w) => w.site_id === siteId) as Well;
                let rows: Reading[];
                try {
                    if (failLevels.includes(siteId)) throw new Error('HTTP 500');
                    rows = await fetchReadings(siteId, async () => ({ ok: true, status: 200, json: async () => levels[siteId] || [] }));
                } catch {
                    return { well, config: undefined as any, note: "This well's readings could not be loaded right now." };
                }
                return { well, ...wellChart(well, rows) };
            };
            const drawAll = () => {
                markers = drawWellMarkers((map as any).markers, drawnWells(wells, area, active), selected, () => {});
            };
            let detail: Awaited<ReturnType<typeof select>> | null = null;

            for (const [i, step] of steps.entries()) {
                const before = detail;
                if (i === 0) {
                    map = createMarkerMap('gw-map', { center: [34.5, -117.2], zoom: 9 });
                    const vis = visibleWells(wells, area);
                    fitToPoints(
                        map.map,
                        vis.map((w) => [w.latitude, w.longitude] as [number, number]),
                        { padding: 24, maxZoom: 11 },
                    );
                    drawAll();
                    detail = await select((startWell(vis) as Well).site_id);
                } else if (step.step.startsWith('area ')) {
                    area = step.step.slice(5);
                    const vis = visibleWells(wells, area);
                    fitToPoints(
                        (map as any).map,
                        vis.map((w) => [w.latitude, w.longitude] as [number, number]),
                        { padding: 24, maxZoom: 11 },
                    );
                    if (!vis.some((w) => w.site_id === selected)) selected = null;
                    drawAll();
                    if (selected === null && vis.length) detail = await select((startWell(vis) as Well).site_id);
                } else if (step.step.startsWith('toggle ')) {
                    const key = step.step.slice(7) as WellStatus;
                    if (active.has(key)) active.delete(key);
                    else active.add(key);
                    active = new Set(active);
                    drawAll();
                } else if (step.step.startsWith('table row ')) {
                    const row = rankedWells(wells, area)[Number(step.step.slice(10))];
                    detail = await select(row.site_id);
                } else if (step.step.startsWith('marker ')) {
                    const n = Number(step.step.slice(7));
                    const id = drawnWells(wells, area, active)[n].site_id;
                    detail = await select(id);
                }

                const vis = visibleWells(wells, area);
                const stats = gwStats(wells, area, summary.totals);
                expect(canon(html(<StatCards cards={stats.cards} />), 'grid'), step.step).toBe(canon(step['gw-stats'], 'grid'));
                expect(stats.note, step.step).toBe(step['gw-summary-note']);
                expect(canon(html(<AreaButtons wells={wells} area={area} onChange={() => {}} />), 'map-controls'), step.step).toBe(
                    canon(step['gw-area-controls'], 'map-controls'),
                );
                expect(canon(html(<StatusLegend wells={wells} area={area} active={active} onToggle={() => {}} />), 'map-legend'), step.step).toBe(
                    canon(step['gw-legend'], 'map-legend'),
                );
                expect(mapNote(drawnWells(wells, area, active).length, vis.length), step.step).toBe(step['gw-map-note']);
                const ranked = rankedWells(wells, area);
                expect(canon(ranked.length ? html(<ChangesTable wells={ranked} onSelect={() => {}} />) : ''), step.step).toBe(
                    canon(step['gw-table']),
                );
                expect(!ranked.length, step.step).toBe(step['gw-table-section:state'].hidden);
                if (detail) {
                    expect(`Well ${detail.well.name}`, step.step).toBe(step['gw-well-title']);
                    expect(wellSub(detail.well), step.step).toBe(step['gw-well-sub']);
                    expect(canon(html(<StatCards cards={wellFacts(detail.well)} />), 'grid'), step.step).toBe(canon(step['gw-well-stats'], 'grid'));
                    expect(detail.note, step.step).toBe(step['gw-well-note']);
                }
                // the chart made in this step (only when a well was chosen), and what its functions answer
                const chosen = detail !== before;
                expect(step.charts, step.step).toHaveLength(chosen && detail?.config ? 1 : 0);
                if (chosen && detail?.config) {
                    expect(asJson(serialize(detail.config)), step.step).toEqual(step.charts[0].config);
                    expect(asJson(probeGroundwater(detail.config)), step.step).toEqual(step.charts[0].probes);
                }
                expect(normalizeCalls(takeLog()), step.step).toEqual(normalizeCalls(step.calls));
            }
        });
    }

    it('shows the messages production shows when the summary is missing or empty', () => {
        expect(golden.groundwater.summaryError[0]['gw-loading:state']).toEqual({
            hidden: false,
            className: 'error-panel',
            text: 'Groundwater data could not be loaded right now.',
        });
        expect(golden.groundwater.empty[0]['gw-loading:state']).toEqual({
            hidden: false,
            className: 'error-panel',
            text: 'Groundwater data has not been loaded yet.',
        });
    });

    it("asks for the summary and a well's readings with production's addresses", async () => {
        const urls: string[] = [];
        const fake = async (url: string) => {
            urls.push(url);

            return { ok: true, status: 200, json: async () => ({}) };
        };
        await fetchSummary(fake);
        await fetchReadings('USGS-1 2', fake);
        expect(urls).toEqual([
            'https://data.mojaverivervalley.org/v1/groundwater_summary.php',
            'https://data.mojaverivervalley.org/v1/query.php?table=groundwater_levels&site_id=USGS-1%202&columns=depth_ft,qualifiers,approval,method&limit=5000',
        ]);
    });
});

describe('groundwater helpers', () => {
    it('describes a change', () => {
        expect(describeChange(null)).toBe('no ten-year comparison');
        expect(describeChange(0.04)).toBe('no change');
        expect(describeChange(0.05)).toBe('0.1 ft deeper');
        expect(describeChange(-3.25)).toBe('3.3 ft shallower');
        expect(describeChange(1234.5)).toBe('1,234.5 ft deeper');
    });

    it('writes a negative depth as above ground', () => {
        expect(fmtDepth(-4.2)).toBe('4.2 ft above ground');
        expect(fmtDepth('12')).toBe('12.0 ft');
    });

    it('takes the median of an even and an odd number of values', () => {
        expect(median([])).toBeNull();
        expect(median([3, 1, 2])).toBe(2);
        expect(median([1, 2, 3, 4.5])).toBe(2.5);
        expect(median([1.04, 1.06])).toBe(1.1);
    });

    it("has the three areas in production's order and the decimal year of a date", () => {
        expect(Object.keys(AREAS)).toEqual(['high_desert', 'san_bernardino_valley', 'all']);
        expect(decimalYear('2020-01-01')).toBeCloseTo(2020.0014, 3);
        expect(decimalYear('2020-07-02')).toBeCloseTo(2020.5, 2);
    });
});
