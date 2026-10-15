// A recording stand-in for Chart.js's `Chart`, shared by the golden generator (the legacy page scripts run against it) and
// by tests: every chart made or destroyed is logged with its configuration, functions shown as '[function]'. The functions
// themselves are kept in `charts` so a test can call them (tooltip text, tick labels) and compare what they return.
export const serialize = (v) => JSON.parse(JSON.stringify(v, (k, x) => (typeof x === 'function' ? '[function]' : x)));

export function makeChart(log, charts = []) {
    return class Chart {
        // The pages that set Chart.js defaults (City Explorer, Garden, Air Quality) write into this.
        static defaults = { font: {}, plugins: { legend: { labels: {} } } };
        constructor(canvas, config) {
            this.config = config;
            log.push(['new Chart', canvas && canvas.id, serialize(config)]);
            charts.push({ canvas: canvas && canvas.id, config });
        }
        destroy() {
            log.push(['destroy chart']);
        }
    };
}
