// A recording stand-in for Leaflet's `L`, shared by the golden generator (legacy map.js runs against it) and by
// region-map tests (the port's leaflet-map.ts runs against it through vi.mock): every call is logged with its
// arguments, so two implementations that make the same calls in the same order build the same map.
export function makeLeaflet(log, extra = {}) {
    const rec = (name, args) => log.push([name, JSON.parse(JSON.stringify(args, (k, v) => (typeof v === 'function' ? '[function]' : v)))]);
    const chain = (prefix, methods) => {
        const o = {};
        for (const m of methods)
            o[m] = (...a) => {
                rec(prefix + '.' + m, a);
                return o;
            };
        return o;
    };
    const map = () => chain('map', extra.invalidateSize ? ['setView', 'fitBounds', 'remove', 'invalidateSize'] : ['setView', 'fitBounds', 'remove']);
    const layerGroup = () => chain('layerGroup', ['addTo', 'clearLayers']);
    return {
        map: (container, opts) => {
            rec('L.map', [typeof container === 'string' ? container : 'element', opts]);
            return map();
        },
        tileLayer: (url, opts) => {
            rec('L.tileLayer', [url, opts]);
            return chain('tileLayer', ['addTo']);
        },
        layerGroup: () => {
            rec('L.layerGroup', []);
            return layerGroup();
        },
        latLngBounds: (pts) => {
            rec('L.latLngBounds', [pts]);
            return { bounds: pts };
        },
        circleMarker: (ll, opts) => {
            rec('L.circleMarker', [ll, opts]);
            return chain('marker', ['bindPopup', 'bindTooltip', 'addTo']);
        },
    };
}

/** The calls both implementations are put through; `api` is { init(container, points), render(points, values, opts), initMini(...), destroy(map) }. */
export function runMapScenarios(api, log) {
    const out = {};
    const take = () => log.splice(0, log.length);
    const P = (name, lat, lon) => ({ name, lat, lon });
    const points = [P('A', 34.5, -117.3), P('B', 34.9, -116.1), P('C', 34.2, -117.9), P('D', null, -117), P('E', 34.0, null), P('F', 35.1, -116.6)];
    const popup = (p, v) => `<b>${p.name}</b> ${v}`;

    api.init('map', points);
    out.initRegionMap = take();
    api.init('map', []);
    out.initEmpty = take();
    api.init('map', [P('X', null, null)]);
    out.initNoValidPoints = take();

    api.init('map', points);
    take();
    const cases = {
        spread: { A: 10, B: 50, C: 30, D: 20, E: 40, F: 70 },
        equal: { A: 5, B: 5, C: 5, F: 5 },
        singleValue: { A: 5 },
        allNull: {},
        withNulls: { A: null, B: 12, C: undefined, F: 90 },
        negatives: { A: -10, B: 0, C: 10, F: 5 },
    };
    out.render = {};
    for (const [name, values] of Object.entries(cases)) {
        const scale = api.render(points, values, { formatPopup: popup });
        out.render[name] = { scale, calls: take() };
    }
    out.renderDefaultPopup = { scale: api.render(points, cases.spread, {}), calls: take() };
    out.renderNoPoints = { scale: api.render([], {}, {}), calls: take() };

    const mini = api.initMini('city-mini-map', 34.5, -117.3, 'Victorville');
    out.miniMap = { returned: mini !== null, calls: take() };
    const none = api.initMini('city-mini-map', null, -117.3, 'x');
    out.miniMapNullLat = { returned: none !== null, calls: take() };
    const none2 = api.initMini('city-mini-map', 34, null, 'x');
    out.miniMapNullLon = { returned: none2 !== null, calls: take() };
    api.destroy(mini);
    out.destroy = take();
    api.destroy(null);
    out.destroyNull = take();
    return out;
}
