import { applyChartDefaults } from '@/lib/mrv/chart-theme';
import { Chart, registerables, type ChartConfiguration } from 'chart.js';
import { useEffect, useRef } from 'react';

// The CDN build production uses registers every controller, scale, element and plugin; so does this.
Chart.register(...registerables);

// Chart.js's own defaults, kept before any page changes them: on production only some pages (City Explorer, Garden,
// Air Quality) set the site font and ink color; the others (Overview's drought chart, Groundwater) draw with these.
const STOCK_DEFAULTS = {
    family: Chart.defaults.font.family,
    color: Chart.defaults.color,
    usePointStyle: Chart.defaults.plugins.legend.labels.usePointStyle,
};

/** `site`: the defaults production's chart pages set. `stock`: Chart.js's own, for the pages that set none. */
export type ChartStyle = 'site' | 'stock';

function setDefaults(style: ChartStyle): void {
    if (style === 'site') {
        applyChartDefaults(Chart);
    } else {
        Chart.defaults.font.family = STOCK_DEFAULTS.family;
        Chart.defaults.color = STOCK_DEFAULTS.color;
        Chart.defaults.plugins.legend.labels.usePointStyle = STOCK_DEFAULTS.usePointStyle;
    }
}

interface MrvChartProps {
    /** The element id production's markup gives the canvas (`chart-daily` and so on). */
    id?: string;
    className?: string;
    /** A Chart.js configuration, exactly as production's pages pass to `new Chart(canvas, config)`. */
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    config: ChartConfiguration<any, any, any> | null;
    /** Which Chart.js defaults the page works with (see ChartStyle); `site` unless the production page sets none. */
    style?: ChartStyle;
    /** Gives the page the chart instance (to update it, or to destroy it early); called with null when it is destroyed. */
    onChart?: (chart: Chart | null) => void;
}

/**
 * One chart on a canvas. Created when the component mounts and again whenever `config` changes (production destroys
 * and recreates its charts on every change), destroyed when it unmounts. The canvas is sized by its parent, as on
 * production (`responsive` is Chart.js's default), so the page puts it in the same wrapper element production does.
 */
export default function MrvChart({ id, className, config, style = 'site', onChart }: MrvChartProps) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const callback = useRef(onChart);
    callback.current = onChart;

    useEffect(() => {
        // A null configuration is an empty canvas (Groundwater, for a well with nothing to plot).
        if (!canvas.current || !config) return;
        // Defaults are global to Chart.js and read when a chart is created, so set the page's own just before.
        setDefaults(style);
        const chart = new Chart(canvas.current, config);
        callback.current?.(chart);

        return () => {
            chart.destroy();
            callback.current?.(null);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config]);

    return <canvas ref={canvas} id={id} className={className} />;
}
