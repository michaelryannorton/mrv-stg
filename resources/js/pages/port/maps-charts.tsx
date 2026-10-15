import MrvChart from '@/components/mrv/chart';
import { MiniMapView, RegionMapView } from '@/components/mrv/map';
import { PRODUCTION_PREVIEW_NAV } from '@/components/mrv/navigation';
import PublicLayout from '@/layouts/public-layout';
import { type MapPoint, type MarkerValues } from '@/lib/mrv/leaflet-map';
import { Head } from '@inertiajs/react';
import { type ChartConfiguration, type Plugin } from 'chart.js';
import { useEffect, useMemo, useState } from 'react';

interface Inputs {
    points: MapPoint[];
    values: MarkerValues;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    charts: Record<string, ChartConfiguration<any, any, any>>;
    miniMap: { lat: number; lon: number; label: string };
}

// The same plugin, in the same words, as port/phase-2/reference/maps-charts.html.
const bandPlugin: Plugin = {
    id: 'testBands',
    beforeDatasetsDraw(chart) {
        const {
            ctx,
            chartArea: { left, right },
            scales: { y },
        } = chart;
        ctx.save();
        ctx.fillStyle = 'rgba(193, 89, 47, 0.12)';
        const y0 = y.getPixelForValue(40);
        const y1 = y.getPixelForValue(80);
        ctx.fillRect(left, y1, right - left, y0 - y1);
        ctx.restore();
    },
};

const CARDS: Array<[string, string, string]> = [
    ['normals', 'Monthly Temperature Normals', 'Average daily high and low by month.'],
    ['precip', 'Monthly Precipitation', 'Average total rainfall/snowfall-equivalent by month.'],
    ['windRose', 'Wind Rose', '% of days with each dominant wind direction, all years combined.'],
    ['bands', 'Threshold Bands', 'A line with a plugin that paints a band behind the data (as the air quality charts do).'],
];

/**
 * Local environment only (routes/web.php, `port.maps-charts`): the map and chart wrappers drawing the shared inputs
 * (port/phase-2/reference/inputs.json), compared with production's own map.js and CDN libraries drawing the same
 * thing (port/phase-2/tools/compare_wrappers.mjs). Not part of the public site.
 */
export default function MapsCharts({ inputs }: { inputs: Inputs }) {
    const configs = useMemo(() => {
        const copy = structuredClone(inputs.charts);
        copy.bands.plugins = [bandPlugin];

        return copy;
    }, [inputs]);

    // The charts draw text on a canvas, which uses whatever font is loaded at that moment; wait until Inter (regular
    // weight) is actually loaded, as the reference page does, so the comparison does not depend on how fast the font arrives.
    const [fontReady, setFontReady] = useState(false);
    useEffect(() => {
        const started = Date.now();
        const poll = window.setInterval(() => {
            const loaded = Array.from(document.fonts).some(
                (f) => f.family.replace(/"/g, '') === 'Inter' && f.weight === '400' && f.status === 'loaded',
            );
            if (loaded || Date.now() - started > 8000) {
                window.clearInterval(poll);
                setFontReady(true);
            }
        }, 50);

        return () => window.clearInterval(poll);
    }, []);

    return (
        <PublicLayout activeId="home" pages={PRODUCTION_PREVIEW_NAV} account={false}>
            <Head title="Map and chart wrapper check" />

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Region Map</h2>
                        <p>Markers colored and sized by value.</p>
                    </div>
                    <RegionMapView
                        id="map"
                        points={inputs.points}
                        values={inputs.values}
                        formatPopup={(p, v) => '<strong>' + p.name + '</strong><br>' + v}
                    />
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Charts</h2>
                        <p>Four charts from one set of inputs.</p>
                    </div>
                    <div className="grid-charts grid">
                        {CARDS.map(([id, heading, note]) => (
                            <div className="card chart-card" key={id}>
                                <h3>{heading}</h3>
                                <p className="card-note">{note}</p>
                                {fontReady && <MrvChart id={`chart-${id}`} config={configs[id]} />}
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Where It Is</h2>
                    </div>
                    <MiniMapView id="city-mini-map" lat={inputs.miniMap.lat} lon={inputs.miniMap.lon} label={inputs.miniMap.label} />
                </div>
            </section>
        </PublicLayout>
    );
}
