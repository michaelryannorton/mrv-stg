/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { QuakeLegend, QuakeRows } from '@/components/mrv/earthquakes';
import { StatCards } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import {
    distanceMi,
    fetchEvents,
    filteredEvents,
    isShownEvent,
    LOADING_STAT,
    MAG_OPTIONS,
    magColor,
    magRadius,
    magRange,
    quakeList,
    quakePins,
    quakeStats,
    timeAgo,
    windowQuery,
    WINDOWS,
    withinRegion,
    type QuakeEvent,
} from './earthquakes';
import { asJson, readGolden } from './golden';
import { createMarkerMap, drawMarkers, fitToPoints, mountMarkerMap } from './leaflet-map';
import { canon } from './test-helpers/html-canon';

// Leaflet is replaced by the recording stand-in the legacy script ran against to make the golden file.
const log = vi.hoisted(() => [] as unknown[]);
vi.mock('leaflet', async () => {
    const { makeLeaflet } = await import('./__golden__/leaflet-stub.mjs');

    return makeLeaflet(log, { invalidateSize: true, wells: true });
});

/**
 * The Earthquake page against the legacy earthquakes.js (run unmodified over a fake DOM, a recording Leaflet and a fixed
 * clock, through the window and magnitude buttons): what it wrote into the page and every Leaflet call it made.
 */
const golden = readGolden<any>('phase4-golden.json.gz');
const inputs = readGolden<any>('phase4-inputs.json.gz');
const NOW = inputs.clock as number;
const html = (node: React.ReactElement) => renderToStaticMarkup(node);
const takeLog = () => (log as any[]).splice(0, (log as any[]).length);
const normalizeCalls = (calls: any[]) =>
    asJson(calls).map(([name, args]: [string, any[]]) =>
        name === 'marker.bindTooltip' || name === 'marker.bindPopup' ? [name, [canon(args[0]), ...args.slice(1)]] : [name, args],
    );

describe('Earthquake Activity (earthquakes.js)', () => {
    for (const variant of Object.keys(inputs.quakes)) {
        it(variant, async () => {
            const rows = inputs.quakes[variant] as QuakeEvent[];
            const steps = golden.quakes[variant] as any[];
            let windowKey = '30';
            let minMag: number | null = 1.5;
            let events: QuakeEvent[] = [];
            let handle: ReturnType<typeof createMarkerMap> | null = null;

            takeLog();
            for (const [i, step] of steps.entries()) {
                let drew = false;
                if (i === 0) {
                    handle = mountMarkerMap('quake-map', { markers: [], fitPoints: [], fit: { padding: 30, maxZoom: 11 }, appendOnly: true }) as any;
                } else if (step.step.endsWith('days') || step.step === '1 year') {
                    windowKey = step.step === '7 days' ? '7' : step.step === '90 days' ? '90' : step.step === '1 year' ? '365' : '30';
                } else if (step.step === 'all magnitudes' || step.step === 'felt again') {
                    minMag = step.step === 'all magnitudes' ? null : 1.5;
                }
                if (i === 0 || step.urls.length) {
                    const days = (WINDOWS.find((w) => w.key === windowKey) as (typeof WINDOWS)[number]).days;
                    const fakeFetch = async (url: string) => {
                        const u = new URL(url);
                        const from = u.searchParams.get('from') as string;
                        const to = u.searchParams.get('to') as string;
                        const status = variant === 'httpError' ? 503 : 200;

                        return {
                            ok: status === 200,
                            status,
                            json: async () => rows.filter((r) => from <= r.event_time.slice(0, 10) && r.event_time.slice(0, 10) <= to),
                        };
                    };
                    // the request is the one the legacy page made
                    expect(step.urls.map((u: string) => u.replace('https://data.mojaverivervalley.org/v1', ''))).toEqual([
                        windowQuery(days, new Date(NOW)).replace('https://data.mojaverivervalley.org/v1', ''),
                    ]);
                    try {
                        events = await fetchEvents(days, fakeFetch, new Date(NOW));
                    } catch (err) {
                        expect(step.map).toBe(`<div class="error-panel">Couldn't load earthquake data right now. ${(err as Error).message}</div>`);
                        expect(step.list).toBe(step.map);
                        expect(step.stats).toBe('');
                        expect(normalizeCalls(takeLog())).toEqual(normalizeCalls(step.calls));
                        continue;
                    }
                    drew = true;
                } else {
                    drew = true;
                }
                const shown = filteredEvents(events, minMag);
                const { min, max } = magRange(shown);
                if (drew && handle) {
                    const pins = quakePins(shown, min, max);
                    drawMarkers(handle.markers, pins, { clear: true });
                    if (pins.length)
                        fitToPoints(
                            handle.map,
                            pins.map((p) => [p.lat, p.lon] as [number, number]),
                            { padding: 30, maxZoom: 11 },
                        );
                }
                expect(canon(html(<StatCards cards={quakeStats(shown, NOW)} />), 'grid'), step.step).toBe(canon(step.stats, 'grid'));
                expect(canon(html(<QuakeLegend min={min} max={max} />)), step.step).toBe(canon(step.legend));
                const list = quakeList(shown);
                expect(
                    canon(
                        list.shown.length
                            ? html(<QuakeRows events={list.shown} min={min} max={max} />)
                            : '<p class="card-note">No events match the current window and magnitude filter.</p>',
                    ),
                    step.step,
                ).toBe(canon(step.list));
                expect(shown.length ? list.note : '', step.step).toBe(step.note);
                expect(
                    canon(html(<ToggleButtons options={WINDOWS} active={windowKey} attribute="window" onChange={() => {}} />), 'map-controls'),
                    step.step,
                ).toBe(canon(step.windows, 'map-controls'));
                expect(
                    canon(
                        html(
                            <ToggleButtons
                                options={MAG_OPTIONS}
                                active={(MAG_OPTIONS.find((o) => o.value === minMag) as (typeof MAG_OPTIONS)[number]).key}
                                attribute="mag"
                                onChange={() => {}}
                            />,
                        ),
                        'map-controls',
                    ),
                    step.step,
                ).toBe(canon(step.mags, 'map-controls'));
                expect(normalizeCalls(takeLog()), step.step).toEqual(normalizeCalls(step.calls));
            }
        });
    }

    it('says what production says when the request fails', async () => {
        const failing = async () => ({ ok: false, status: 503, json: async () => ({}) });
        await expect(fetchEvents(30, failing)).rejects.toThrow('Earthquake fetch failed: HTTP 503');
        expect(golden.quakes.httpError[0].map).toBe(
            `<div class="error-panel">Couldn't load earthquake data right now. Earthquake fetch failed: HTTP 503</div>`,
        );
    });
});

describe('earthquake helpers', () => {
    it('the distance filter keeps 60 miles around Victorville and drops the Los Angeles basin', () => {
        expect(distanceMi(34.53611111, -117.2911111)).toBeCloseTo(0, 6);
        const at = (lat: number, lon: number) => withinRegion({ latitude: lat, longitude: lon } as QuakeEvent);
        expect(at(34.5, -117.3)).toBe(true);
        expect(at(34.0, -118.3)).toBe(false);
        expect(at(35.4, -117.3)).toBe(true); // about 59.5 miles north
        expect(at(35.45, -117.3)).toBe(false); // about 63 miles north
        expect(withinRegion({ latitude: null, longitude: -117 } as QuakeEvent)).toBe(false);
    });

    it('shows an event unless it is deleted or typed as something other than an earthquake', () => {
        expect(isShownEvent({ status: 'deleted' } as QuakeEvent)).toBe(false);
        expect(isShownEvent({ event_type: 'quarry blast' } as QuakeEvent)).toBe(false);
        expect(isShownEvent({ event_type: null } as QuakeEvent)).toBe(true);
        expect(isShownEvent({ event_type: 'earthquake', status: 'reviewed' } as QuakeEvent)).toBe(true);
    });

    it('colors and sizes by magnitude', () => {
        expect(magColor(null, 1, 4)).toBe('hsl(9, 55%, 72%)');
        expect(magColor(2, 2, 2)).toBe('hsl(9, 55%, 45%)');
        expect(magColor(1, 1, 5)).toBe('hsl(9, 55%, 80%)');
        expect(magColor(5, 1, 5)).toBe('hsl(9, 55%, 35%)');
        expect(magRadius(null)).toBe(4);
        expect(magRadius(-1)).toBe(4);
        expect(magRadius(3)).toBeCloseTo(11.8, 10);
    });

    it('says how long ago an event was', () => {
        const ago = (ms: number) => timeAgo('2026-10-14 00:00:00', Date.UTC(2026, 9, 14) + ms);
        expect(ago(59999)).toBe('just now');
        expect(ago(60000)).toBe('1m ago');
        expect(ago(3599999)).toBe('59m ago');
        expect(ago(3600000)).toBe('1h ago');
        expect(ago(86400000 - 1)).toBe('23h ago');
        expect(ago(86400000 * 3)).toBe('3d ago');
    });

    it('has the loading card production shows while a window loads', () => {
        expect(LOADING_STAT).toEqual({ label: 'Events Shown', value: '…', sub: 'Loading' });
    });
});
