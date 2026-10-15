/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { ArtCards, ArtLegend, ArtTable, ArtViewTabs } from '@/components/mrv/art';
import { MarketCard, MarketLegend } from '@/components/mrv/markets';
import { StationLegend } from '@/components/mrv/stations';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { artCountNote, artPins, fetchArt, MOCK_ART, type ArtEntry, type ArtView } from './art';
import { asJson, readGolden } from './golden';
import { drawMarkers, invalidateSoon, mountMarkerMap, reshowMarkerMap, type MarkerMapSpec, type RegionMapHandle } from './leaflet-map';
import { hasLocation, marketCountNote, marketPins, parseMarket, sortMarkets } from './markets';
import { allPoints, drawableStations, initialNetworks, stationCountNote, stationPins } from './stations';
import { canon } from './test-helpers/html-canon';

// Leaflet is replaced by the recording stand-in the legacy scripts ran against to make the golden file.
const log = vi.hoisted(() => [] as unknown[]);
vi.mock('leaflet', async () => {
    const { makeLeaflet } = await import('./__golden__/leaflet-stub.mjs');

    return makeLeaflet(log, { invalidateSize: true });
});

/**
 * The Farmers Markets, Data Explorer and Public Art pages against the legacy scripts (markets.js, stations.js, art.js,
 * run unmodified over a fake DOM): what they wrote into the page and every Leaflet call they made. The React components
 * must build the same DOM, and the port's map functions must make the same Leaflet calls in the same order.
 */
const golden = readGolden<any>('phase3-golden.json.gz');
const inputs = readGolden<any>('phase3-inputs.json.gz');
const html = (node: React.ReactElement) => renderToStaticMarkup(node);
const takeLog = () => (log as any[]).splice(0, (log as any[]).length);

/** Tooltips and popups are HTML strings: compare them as DOM, everything else exactly. */
const normalizeCalls = (calls: any[]) =>
    asJson(calls).map(([name, args]: [string, any[]]) =>
        name === 'marker.bindTooltip' || name === 'marker.bindPopup' ? [name, [canon(args[0]), ...args.slice(1)]] : [name, args],
    );

describe('Farmers Markets (markets.js)', () => {
    for (const variant of Object.keys(inputs.markets)) {
        it(variant, () => {
            const expected = golden.markets[variant];
            const markets = sortMarkets(inputs.markets[variant].map(parseMarket));
            const pins = marketPins(markets);

            expect(marketCountNote(markets)).toBe(expected.count);
            expect(canon(html(<MarketLegend markets={markets} />), 'map-legend')).toBe(canon(expected.legend, 'map-legend'));
            expect(
                canon(
                    html(
                        <>
                            {markets.map((m, i) => (
                                <MarketCard key={i} market={m} />
                            ))}
                        </>,
                    ),
                    'grid',
                ),
            ).toBe(canon(expected.grid, 'grid'));
            // No located market: production puts a message where the map would be.
            expect(pins.length === 0).toBe(expected.map.startsWith('<div class="error-panel">No market locations'));
            expect(markets.filter(hasLocation)).toHaveLength(pins.length);

            takeLog();
            if (pins.length) {
                mountMarkerMap('market-map', {
                    markers: pins,
                    fitPoints: pins.map((p) => [p.lat, p.lon]),
                    fit: { padding: 30, maxZoom: 11 },
                    appendOnly: true,
                });
            }
            expect(normalizeCalls(takeLog())).toEqual(normalizeCalls(expected.calls));
        });
    }

    it('keeps the production error text for a failed request', () => {
        expect(golden.markets.httpError.grid).toBe(
            '<div class="error-panel">Couldn\'t load farmers market data right now. Market fetch failed: HTTP 503</div>',
        );
    });
});

describe('Data Explorer (stations.js)', () => {
    for (const variant of Object.keys(inputs.stations)) {
        it(variant, () => {
            const stations = inputs.stations[variant];
            const active = initialNetworks(stations);
            const steps = golden.stations[variant] as any[];
            let handle: RegionMapHandle | null = null;
            const spec = (): MarkerMapSpec => ({
                markers: stationPins(drawableStations(stations, active)),
                fitPoints: allPoints(stations),
                fit: { padding: 24, maxZoom: 11 },
                fitFirst: true,
            });

            takeLog();
            steps.forEach((step, i) => {
                if (i === 0) {
                    handle = mountMarkerMap('station-map', spec());
                } else {
                    const network = step.step.replace('toggle ', '');
                    if (active.has(network)) active.delete(network);
                    else active.add(network);
                    drawMarkers((handle as RegionMapHandle).markers, spec().markers, { clear: true });
                }
                const drawn = drawableStations(stations, active);
                expect(stationCountNote(drawn.length, stations.length), step.step).toBe(step.count);
                expect(canon(html(<StationLegend stations={stations} active={active} onToggle={() => {}} />), 'map-legend'), step.step).toBe(
                    canon(step.legend, 'map-legend'),
                );
                expect(normalizeCalls(takeLog()), step.step).toEqual(normalizeCalls(step.calls));
            });
        });
    }

    it('the legacy page shows the error text for a failed request', () => {
        expect(golden.stations.httpError[0].map).toBe(
            '<div class="error-panel">Couldn\'t load station data right now. Station fetch failed: HTTP 500</div>',
        );
    });
});

describe('Public Art (art.js)', () => {
    // The second scenario edits the legacy script's own data (see ART_EDITS in make_golden.mjs) and the same edits are made to the port's copy.
    const edited = (): ArtEntry[] => {
        const art = JSON.parse(JSON.stringify(MOCK_ART)) as ArtEntry[];
        for (const e of inputs.artEdits) (art.find((a) => a.slug === e.slug) as any)[e.field] = e.value;

        return art;
    };

    it('has the placeholder entries of the legacy script and no others', async () => {
        const rows = golden.art.real[0].views['art-card-view'].html.match(/<div class="card art-card">/g);
        expect(rows).toHaveLength(MOCK_ART.length);
        expect(await fetchArt()).toBe(MOCK_ART);
        expect(artCountNote(12)).toBe(golden.art.real[0].count);
    });

    for (const scenario of ['real', 'edited'] as const) {
        it(`${scenario} data: builds the same views and makes the same Leaflet calls through the tab sequence`, async () => {
            const art = scenario === 'real' ? MOCK_ART : edited();
            const steps = golden.art[scenario] as any[];
            const shown = new Set<ArtView>(['card']);
            let view: ArtView = 'card';
            let handle: RegionMapHandle | null = null;
            const spec = (): MarkerMapSpec => ({
                markers: artPins(art),
                fitPoints: artPins(art).map((p) => [p.lat, p.lon]),
                fit: { padding: 30, maxZoom: 11 },
            });

            takeLog();
            for (const [i, step] of steps.entries()) {
                if (i > 0) {
                    view = step.step.replace('view ', '') as ArtView;
                    if (view === 'map' && !handle) {
                        handle = mountMarkerMap('art-map', spec());
                        invalidateSoon(handle.map);
                    } else if (view === 'map') {
                        reshowMarkerMap(handle as RegionMapHandle, spec());
                    }
                    shown.add(view);
                    await new Promise((r) => setTimeout(r, 0));
                }
                expect(canon(html(<ArtViewTabs view={view} onChange={() => {}} />), 'map-controls'), step.step).toBe(
                    canon(step.tabs, 'map-controls'),
                );
                expect(artCountNote(art.length), step.step).toBe(step.count);
                // A view is filled the first time it is shown and stays (hidden) afterwards; production's legend sits with the map.
                expect(canon(shown.has('card') ? html(<ArtCards art={art} />) : '', 'grid'), step.step).toBe(
                    canon(step.views['art-card-view'].html, 'grid'),
                );
                expect(canon(shown.has('table') ? html(<ArtTable art={art} />) : ''), step.step).toBe(canon(step.views['art-table-view'].html));
                expect(canon(shown.has('map') ? html(<ArtLegend art={art} />) : '', 'map-legend'), step.step).toBe(canon(step.legend, 'map-legend'));
                expect(normalizeCalls(takeLog()), step.step).toEqual(normalizeCalls(step.calls));
            }
        });
    }
});
