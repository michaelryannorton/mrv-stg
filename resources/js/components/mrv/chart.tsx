import { applyChartDefaults } from '@/lib/mrv/chart-theme';
import { Chart, registerables, type ChartConfiguration } from 'chart.js';
import { useEffect, useRef } from 'react';

// The CDN build production uses registers every controller, scale, element and plugin; so does this.
Chart.register(...registerables);
applyChartDefaults(Chart);

interface MrvChartProps {
    /** The element id production's markup gives the canvas (`chart-daily` and so on). */
    id?: string;
    className?: string;
    /** A Chart.js configuration, exactly as production's pages pass to `new Chart(canvas, config)`. */
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    config: ChartConfiguration<any, any, any>;
    /** Gives the page the chart instance (to update it, or to destroy it early); called with null when it is destroyed. */
    onChart?: (chart: Chart | null) => void;
}

/**
 * One chart on a canvas. Created when the component mounts and again whenever `config` changes (production destroys
 * and recreates its charts on every change), destroyed when it unmounts. The canvas is sized by its parent, as on
 * production (`responsive` is Chart.js's default), so the page puts it in the same wrapper element production does.
 */
export default function MrvChart({ id, className, config, onChart }: MrvChartProps) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const callback = useRef(onChart);
    callback.current = onChart;

    useEffect(() => {
        if (!canvas.current) return;
        const chart = new Chart(canvas.current, config);
        callback.current?.(chart);

        return () => {
            chart.destroy();
            callback.current?.(null);
        };
    }, [config]);

    return <canvas ref={canvas} id={id} className={className} />;
}
