// Shared by the golden generator (port/phase-2/tools/make_golden.mjs, which runs the LEGACY public_html/js/data.js
// through it) and by weather-data.test.ts (which runs the port through it). Each scenario builds a fresh instance
// from a fake `fetch`, `localStorage` and clock, performs some calls, and returns a plain record of what was
// requested, what was stored, what was warned about and what came back. Equal records mean equal behavior.
//
// `createInstance(env)` must return an object with load, refresh, getCityNames, getCity, getAllCities,
// getCityEnrichment and getCityAirQuality. env = { fetch, storage, now, warn }.

const START = Date.UTC(2026, 9, 14, 12, 0, 0); // 2026-10-14 12:00 UTC
const HOUR = 3600 * 1000;

/** Serve saved API responses by request shape; `mode` switches the behavior for the failure scenarios. */
function makeEnv(inputs, { mode = 'ok', storageSeed = null, storageThrows = false, baseRows = null } = {}) {
    const clock = { t: START };
    const requests = [];
    const warnings = [];
    const writes = [];
    const store = new Map(storageSeed ? Object.entries(storageSeed) : []);
    const body = (url) => {
        const q = new URL(url).searchParams;
        const table = q.get('table');
        if (table === 'cities') return inputs.cities;
        if (table === 'weather_daily' && q.get('columns')) return mode === 'insane' ? [] : (baseRows ?? inputs.weatherBase);
        if (table === 'weather_daily') return inputs.enrichment[q.get('city_id')] ?? [];
        if (table === 'air_quality_daily') return inputs.airQuality[q.get('city_id')] ?? [];
        throw new Error('scenario fetch: unexpected request ' + url);
    };
    const fetch = async (url) => {
        requests.push(url);
        if (mode === 'fail') throw new Error('network down');
        if (mode === 'http500') return { ok: false, status: 500, json: async () => ({}) };
        const b = body(url);
        return { ok: true, status: 200, json: async () => JSON.parse(JSON.stringify(b)) };
    };
    const storage = {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => {
            if (storageThrows) {
                const e = new Error('The quota has been exceeded.');
                e.name = 'QuotaExceededError';
                throw e;
            }
            writes.push([k, v]);
            store.set(k, v);
        },
    };
    const warn = (...a) => warnings.push(a.map((x) => (x instanceof Error ? `${x.name}: ${x.message}` : String(x))).join(' '));
    return { env: { fetch, storage: () => storage, now: () => clock.t, warn }, clock, requests, warnings, writes, store, setMode: (m) => (mode = m) };
}

const settle = async (promise) => {
    try {
        return { ok: true, value: await promise };
    } catch (e) {
        return { ok: false, error: String(e && e.message ? e.message : e) };
    }
};

/** What a load() result looks like in a record: everything, with row arrays kept whole (the inputs are trimmed). */
const loaded = (r) =>
    r.ok ? { ok: true, fetchedAt: r.value.fetchedAt, fromCache: r.value.fromCache, stale: r.value.stale ?? null, cities: r.value.cities } : r;

export async function runScenarios(createInstance, inputs) {
    const out = {};
    const named = (inst) => ({
        names: inst.getCityNames(),
        firstCity: inst.getCity(inst.getCityNames()[0]) ? inst.getCity(inst.getCityNames()[0]).id : null,
    });

    // 1. Fresh load, nothing cached: requests, parsed data, the compact string written to the cache.
    let h = makeEnv(inputs);
    let inst = createInstance(h.env);
    let r = await settle(inst.load());
    out.fresh = {
        result: loaded(r),
        requests: h.requests,
        writes: h.writes,
        warnings: h.warnings,
        ...named(inst),
        all: Object.keys(inst.getAllCities()).length,
    };
    const seeded = Object.fromEntries(h.store);

    // 2. A second load() on the same instance reuses the first (one set of requests).
    const again = await settle(inst.load());
    out.sameInstanceTwice = { sameObject: again.ok && r.ok && again.value === r.value, requests: h.requests.length };

    // 3. A new page view with a fresh cache: served from the cache, no requests.
    h = makeEnv(inputs, { storageSeed: seeded });
    h.clock.t = START + 1 * HOUR;
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.freshCache = { result: loaded(r), requests: h.requests, writes: h.writes, warnings: h.warnings, ...named(inst) };

    // 4. Cache older than 6 hours: fetched again and rewritten.
    h = makeEnv(inputs, { storageSeed: seeded });
    h.clock.t = START + 7 * HOUR;
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.expiredCache = { result: loaded(r), requests: h.requests, writes: h.writes, warnings: h.warnings };

    // 5. Cache expired and the network is down: the old data is used, marked stale.
    h = makeEnv(inputs, { storageSeed: seeded, mode: 'fail' });
    h.clock.t = START + 7 * HOUR;
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.networkDownWithStaleCache = { result: loaded(r), requests: h.requests, writes: h.writes, warnings: h.warnings };

    // 6. Network down, nothing cached: the error reaches the caller.
    h = makeEnv(inputs, { mode: 'fail' });
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.networkDownNoCache = { result: loaded(r), requests: h.requests, writes: h.writes, warnings: h.warnings, names: inst.getCityNames() };

    // 7. HTTP error status: the error names the status and the address.
    h = makeEnv(inputs, { mode: 'http500' });
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.httpError = { result: loaded(r), requests: h.requests };

    // 8. A "fresh" but empty weather response fails the sanity check and is never cached or used.
    h = makeEnv(inputs, { mode: 'insane' });
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.insaneResponse = { result: loaded(r), requests: h.requests, writes: h.writes, warnings: h.warnings, names: inst.getCityNames() };

    // 9. The cache cannot be written (browser quota): data still works, one warning, every visit refetches.
    h = makeEnv(inputs, { storageThrows: true });
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.cacheWriteRefused = { result: loaded(r), requests: h.requests, writes: h.writes, warnings: h.warnings };

    // 10. A cache holding a city with no rows is discarded and the data fetched again.
    const bad = JSON.parse(seeded['mrv_weather_cache_v4']);
    bad.cities[Object.keys(bad.cities)[0]].rows = [];
    h = makeEnv(inputs, { storageSeed: { mrv_weather_cache_v4: JSON.stringify(bad) } });
    h.clock.t = START + 1 * HOUR;
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.insaneCache = { result: loaded(r), requests: h.requests, writes: h.writes, warnings: h.warnings };

    // 11. A cache that is not JSON: warned about, then fetched.
    h = makeEnv(inputs, { storageSeed: { mrv_weather_cache_v4: '{not json' } });
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.corruptCache = { result: loaded(r), requests: h.requests, writes: h.writes, warnings: h.warnings };

    // 12. refresh() ignores a fresh cache.
    h = makeEnv(inputs, { storageSeed: seeded });
    h.clock.t = START + 1 * HOUR;
    inst = createInstance(h.env);
    await settle(inst.load());
    const before = h.requests.length;
    r = await settle(inst.refresh());
    out.refresh = { fromCache: r.ok ? r.value.fromCache : null, newRequests: h.requests.slice(before), writes: h.writes.length };

    // 13. Enrichment and air quality: request shape, per-city promise caching, unknown city, before load.
    h = makeEnv(inputs);
    inst = createInstance(h.env);
    const early = await settle(inst.getCityEnrichment('Hesperia'));
    await inst.load();
    // The first call's failure is remembered for that city (the legacy code caches the rejected promise).
    const earlyAgain = await settle(inst.getCityEnrichment('Hesperia'));
    const mark = h.requests.length;
    const e1 = await settle(inst.getCityEnrichment('Victorville'));
    const e2 = await settle(inst.getCityEnrichment('Victorville'));
    const a1 = await settle(inst.getCityAirQuality('Victorville'));
    const a2 = await settle(inst.getCityAirQuality('Victorville'));
    const unknownE = await settle(inst.getCityEnrichment('Nowhere'));
    const unknownA = await settle(inst.getCityAirQuality('Nowhere'));
    out.onDemand = {
        beforeLoad: early,
        beforeLoadRemembered: earlyAgain,
        requests: h.requests.slice(mark),
        enrichmentRows: e1.ok ? e1.value.length : e1,
        enrichmentSame: e1.ok && e2.ok && e1.value === e2.value,
        airQualityRows: a1.ok ? a1.value.length : a1,
        airQualitySame: a1.ok && a2.ok && a1.value === a2.value,
        enrichmentFirst: e1.ok ? e1.value[0] : null,
        airQualityFirst: a1.ok ? a1.value[0] : null,
        unknownE,
        unknownA,
    };

    // 15. Cache age boundaries: fresh just under 6 hours, stale at exactly 6 hours, and fresh at 5.5 hours.
    out.ttlBoundaries = {};
    for (const [label, ms] of [
        ['5h30', 5.5 * HOUR],
        ['5h59m59s', 6 * HOUR - 1000],
        ['6h00', 6 * HOUR],
        ['6h00m01s', 6 * HOUR + 1000],
    ]) {
        h = makeEnv(inputs, { storageSeed: seeded });
        h.clock.t = START + ms;
        inst = createInstance(h.env);
        r = await settle(inst.load());
        out.ttlBoundaries[label] = { fromCache: r.ok ? r.value.fromCache : null, requests: h.requests.length };
    }

    // 16. Sanity floor: every city has rows, but the total is 999 (refused) or 1000 (accepted).
    const namedIds = [...new Set(inputs.weatherBase.map((x) => x.city_id))];
    const firstPerCity = namedIds.map((id) => inputs.weatherBase.find((x) => x.city_id === id));
    const takeTotal = (n) => [...firstPerCity, ...inputs.weatherBase.filter((x) => !firstPerCity.includes(x))].slice(0, n);
    out.floorBoundaries = {};
    for (const n of [namedIds.length, 999, 1000, 1001]) {
        h = makeEnv(inputs, { baseRows: takeTotal(n) });
        inst = createInstance(h.env);
        r = await settle(inst.load());
        out.floorBoundaries['rows' + n] = {
            ok: r.ok,
            error: r.ok ? null : r.error,
            cachedWrites: h.writes.length,
            totalRows: r.ok ? Object.values(r.value.cities).reduce((t, c) => t + c.rows.length, 0) : null,
        };
    }

    // 17. A city missing entirely from the weather rows (zero rows for one named city) is refused, however many rows there are.
    const droppedId = namedIds[0];
    h = makeEnv(inputs, { baseRows: inputs.weatherBase.filter((x) => x.city_id !== droppedId) });
    inst = createInstance(h.env);
    r = await settle(inst.load());
    out.oneCityEmpty = { ok: r.ok, error: r.ok ? null : r.error, writes: h.writes.length };

    // 14. Accessors before any load.
    h = makeEnv(inputs);
    inst = createInstance(h.env);
    out.beforeLoad = { names: inst.getCityNames(), city: inst.getCity('Victorville') ?? null, all: inst.getAllCities() };

    return out;
}

/** Build the trimmed inputs object the scenarios use from the files the generator saves. */
export function inputsFromFiles({ cities, weatherBase, enrichment, airQuality }) {
    return { cities, weatherBase, enrichment, airQuality };
}
