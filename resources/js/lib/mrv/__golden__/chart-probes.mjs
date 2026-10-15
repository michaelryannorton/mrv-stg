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
        afters: [cb.afterLabel(item(1, 'Static,Dry', 'Approved', 'Steel tape')), cb.afterLabel(item(1, '', null, null)), cb.afterLabel(item(1, null, 'Provisional', 'Sonic'))],
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
        sorts: [o.plugins.tooltip.itemSort({ datasetIndex: 1 }, { datasetIndex: 3 }), o.plugins.tooltip.itemSort({ datasetIndex: 4 }, { datasetIndex: 0 }), o.plugins.tooltip.itemSort({ datasetIndex: 2 }, { datasetIndex: 2 })],
    };
}
