// Calls the functions inside a chart configuration (tick labels, tooltip text) with fixed inputs and returns what they
// answer, so the legacy page's functions and the port's can be compared (a configuration's functions cannot be saved as JSON).
export function probeGroundwater(config) {
    const o = config.options;
    const cb = o.plugins.tooltip.callbacks;
    const item = (y, q, approval, method, date) => ({ raw: { y, q, approval, method, date } });

    return {
        xTicks: [1995.2, 2010.5, 2026.49, 2026.5].map((v) => o.scales.x.ticks.callback(v)),
        titles: [cb.title([item(1, '', '', '', '2015-03-02')])],
        labels: [cb.label(item(12.34)), cb.label(item(-5.5)), cb.label(item(0)), cb.label(item(1234.567))],
        afters: [
            cb.afterLabel(item(1, 'Static,Dry', 'Approved', 'Steel tape')),
            cb.afterLabel(item(1, '', null, null)),
            cb.afterLabel(item(1, null, 'Provisional', 'Sonic')),
        ],
    };
}

export function probeDrought(config) {
    const o = config.options;
    const cb = o.plugins.tooltip.callbacks;
    const scale = { getLabelForValue: (v) => config.data.labels[v] };
    const label = (v) => cb.label({ dataset: { label: 'D1 Moderate Drought' }, parsed: { y: v } });

    return {
        xTicks: [0, 5, config.data.labels.length - 1].map((v) => o.scales.x.ticks.callback.call(scale, v)),
        yTicks: [0, 25, 100].map((v) => o.scales.y.ticks.callback(v)),
        titles: [cb.title([{ label: '2021-05-04' }])],
        labels: [0, 0.3, 0.99, 1, 45.5, 99.5, 100].map(label),
        sorts: [
            o.plugins.tooltip.itemSort({ datasetIndex: 1 }, { datasetIndex: 3 }),
            o.plugins.tooltip.itemSort({ datasetIndex: 4 }, { datasetIndex: 0 }),
            o.plugins.tooltip.itemSort({ datasetIndex: 2 }, { datasetIndex: 2 }),
        ],
    };
}

// The threshold-bands plugin (Air Quality & UV): the legacy plugin and the port's are called with the same mock charts and a
// recording canvas context; the drawing calls they make must be the same.
function recorder() {
    const calls = [];
    const target = {
        measureText: (t) => {
            calls.push(['measureText', t]);
            return { width: t.length * 5.5 };
        },
    };
    const ctx = new Proxy(target, {
        get: (t, k) => (k in t ? t[k] : (...a) => calls.push([String(k), ...a])),
        set: (t, k, v) => {
            calls.push(['set ' + String(k), v]);
            return true;
        },
    });
    return { ctx, calls };
}

export function probeBands(plugin, bandsSets) {
    const out = {};
    const scenarios = {
        wide: { w: 600, ys: [250, 240], pickLast: true },
        narrow: { w: 300, ys: [250, 240], pickLast: true },
        highRight: { w: 600, ys: [60, 40], right: 30, left: 250 },
        highBoth: { w: 600, ys: [60, 40], right: 30, left: 30 },
        hiddenSet: { w: 600, ys: [250], hidden: true },
        empty: { w: 600, ys: [] },
        shortAxis: { w: 600, ys: [100], min: 0, max: 20 },
        none: { w: 600, ys: [100], noBands: true },
        borderline: { w: 475, ys: [250, 240] },
        thin: { w: 600, ys: [250], min: 0, max: 965 },
        thinEdge: { w: 600, ys: [250], min: 0, max: 933.4 },
    };
    for (const [name, sc] of Object.entries(scenarios)) {
        for (const [bandName, bands] of Object.entries(bandsSets)) {
            const { ctx, calls } = recorder();
            const min = sc.min ?? 0;
            const max = sc.max ?? (bandName === 'uv' ? 12 : 220);
            const chart = {
                ctx,
                chartArea: { left: 50, right: 50 + sc.w, top: 20, bottom: 300 },
                scales: { y: { min, max, getPixelForValue: (v) => 300 - ((v - min) / (max - min)) * 280 } },
                data: { datasets: sc.ys.map(() => ({})) },
                getDatasetMeta: (i) => ({ hidden: !!sc.hidden, data: [{ y: sc.left ?? sc.ys[i] }, { y: sc.ys[i] }, { y: sc.right ?? sc.ys[i] }] }),
            };
            const opts = sc.noBands ? { bands: null } : { bands };
            plugin.beforeDatasetsDraw(chart, {}, opts);
            plugin.afterDatasetsDraw(chart, {}, opts);
            plugin.beforeDatasetsDraw({ ...chart, scales: {} }, {}, opts);
            plugin.afterDatasetsDraw(chart, {}, undefined);
            out[`${name}/${bandName}`] = calls;
        }
    }
    return out;
}

const bandCases = (cb, bands) => [0, 49.4, 50.4, 100.4, 100.5, 150, 300.2, 999].map((v) => cb({ parsed: { y: v } }));

export function probeAirQuality(config) {
    const o = config.options;
    const cb = o.plugins.tooltip?.callbacks;
    const out = { plugins: (config.plugins || []).map((p) => p.id) };
    if (!cb) return out;
    if (config.type === 'bar') out.labels = bandCases(cb.label);
    else if (o.plugins.thresholdBands) {
        out.labels = [null, 0, 0.5, 49.6, 120, 250, 999].map((v) => cb.label({ parsed: { y: v }, dataset: { label: '2024' } }));
    }
    return out;
}

export function probeGarden(config) {
    const cb = config.options.plugins.tooltip?.callbacks;
    return cb ? { labels: [null, 0, 0.0123, 0.1, 0.25, 1.2345].map((v) => cb.label({ parsed: { y: v } })) } : {};
}

export function probeClimate(config) {
    const o = config.options;
    const cb = o.plugins.tooltip.callbacks;
    const dsets = config.data.datasets;
    const out = {};
    if (o.plugins.legend?.labels?.filter) out.legendFilter = dsets.map((_, i) => o.plugins.legend.labels.filter({ datasetIndex: i }));
    if (o.plugins.tooltip.filter) out.tooltipFilter = dsets.map((_, i) => o.plugins.tooltip.filter({ datasetIndex: i }));
    const last = dsets.length - 1;
    if (config.type === 'line') {
        out.title = cb.title([{ label: '2031' }]);
        out.labels = [0, 1.26, 55.5, 120.04, -3].map((v) => cb.label({ parsed: { y: v } }));
        out.after = [0, 5, 20, 40, 75].map((i) => cb.afterLabel({ dataIndex: i }));
    } else {
        out.labels = [0, 1, 11].flatMap((i) => [0, 1].map((d) => cb.label({ datasetIndex: d, dataIndex: i, parsed: { y: -0.04 + i } })));
    }
    out.last = last;
    return out;
}

// City Explorer (phase 6): the tick labels and tooltip text the page's charts compute (sky groups, wind rose, snowfall by winter).
export function probeCity(config) {
    const o = config.options || {};
    const safe = (fn) => {
        try {
            return fn();
        } catch {
            return 'ERROR';
        }
    };
    const out = {};
    const xt = o.scales?.x?.ticks;
    if (xt && typeof xt.callback === 'function') out.xTicks = [0, 25, 50, 100].map((v) => xt.callback(v));
    const rt = o.scales?.r?.ticks;
    if (rt && typeof rt.callback === 'function') out.rTicks = [0, 5, 12.5].map((v) => rt.callback(v));
    const cb = o.plugins?.tooltip?.callbacks;
    if (cb) {
        if (cb.title) out.titles = [cb.title([{ label: '2019-20' }])];
        if (cb.label) out.labels = [0, 3, 15].map((i) => safe(() => cb.label({ dataIndex: i, parsed: { x: i * 1.5, y: i * 1.5 } })));
        if (cb.afterLabel) out.afters = [0, 1, 2, 30, 65, 66, 200].map((i) => safe(() => cb.afterLabel({ dataIndex: i })));
    }

    return out;
}
