/**
 * Mojave River Valley data loading layer: a port of public_html/js/data.js (port phase 2).
 *
 * Pulls base daily weather for every named city from the API in one request (projected down to the columns the
 * site needs), keeps a compact copy in localStorage for 6 hours, falls back to a stale copy when the network
 * fails, and refuses to use or cache a response that fails a sanity check. Enrichment (all columns for one city)
 * and air quality are separate on-demand requests, remembered per city.
 *
 * It is a factory so that tests can supply `fetch`, `storage` and the clock; `weatherData` is the instance pages
 * use. Behavior, requests, cache key and cache format are the legacy ones, and the golden scenarios in
 * weather-data.test.ts prove it against the legacy code itself (port/phase-2/tools/make_golden.mjs). One known
 * legacy weakness is kept on purpose: the cache write fails with a quota error on the real data size (phase 0
 * finding), after which every visit refetches; it is not fixed here.
 */
import { fetchJson, MRV_API_BASE, type FetchLike } from './api';

export interface BaseRow {
    date: string;
    tmax: number | null;
    tmin: number | null;
    fmax: number | null;
    fmin: number | null;
    precip: number | null;
    gust: number | null;
    wx: string | null;
}

export interface CityData {
    id: number | string;
    lat: number | null;
    lon: number | null;
    lastChecked: string | null;
    rows: BaseRow[];
}

export interface Dataset {
    fetchedAt: string;
    cities: Record<string, CityData>;
}

export type LoadResult = Dataset & { fromCache: boolean; stale?: boolean };

type CompactRow = [string, number | null, number | null, number | null, number | null, number | null, number | null, number];
interface CompactData {
    fetchedAt: string;
    weatherCodes: string[];
    cities: Record<string, { id: number | string; lat: number | null; lon: number | null; lastChecked: string | null; rows: CompactRow[] }>;
}

export interface WeatherDataEnv {
    fetch?: FetchLike;
    /** The browser's localStorage by default; looked up on every use, inside a try, as the legacy code did. */
    storage?: () => Pick<Storage, 'getItem' | 'setItem'>;
    /** Milliseconds since the epoch. */
    now?: () => number;
    apiBase?: string;
    warn?: (...args: unknown[]) => void;
}

// Bumped v2->v3 2026-07-30, v3->v4 2026-08-24 (a truncated response had been cached): the key tracks the shape of
// what is stored in localStorage, not file identity.
export const CACHE_KEY = 'mrv_weather_cache_v4';
export const CACHE_TTL_MS = 6 * 60 * 60 * 1000;
export const BASE_COLUMNS = [
    'city_id',
    'date',
    'temp_max_f',
    'temp_min_f',
    'feels_max_f',
    'feels_min_f',
    'precipitation_in',
    'wind_gust_max_mph',
    'weather_condition',
    'updated_at',
];
// A content check catches what a freshness check cannot: require every cached city to have at least one row, and
// the whole dataset to clear a floor (production incident 2026-08-24: a "fresh" response with 35 of 36 cities
// empty was trusted for its whole TTL).
export const MIN_ROWS_TOTAL = 1000;

function numOrNull(v: unknown): number | null {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return Number.isNaN(n) ? null : n;
}

export function createWeatherData(env: WeatherDataEnv = {}) {
    const fetchImpl: FetchLike = env.fetch ?? ((u, i) => fetch(u, i));
    const getStorage = env.storage ?? (() => window.localStorage);
    const now = env.now ?? (() => Date.now());
    const apiBase = env.apiBase ?? MRV_API_BASE;
    const warn = env.warn ?? ((...args: unknown[]) => console.warn(...args));

    const weatherCodes: string[] = [];
    const weatherCodeIndex = new Map<string, number>();
    let cache: Dataset | null = null;
    let loadPromise: Promise<LoadResult> | null = null;
    const enrichmentCache = new Map<string, Promise<unknown>>(); // city name -> promise resolving to full-column rows
    const airQualityCache = new Map<string, Promise<unknown>>(); // city name -> promise resolving to air_quality_daily rows

    function wxCode(name: string | null): number {
        const key = name as string;
        if (!weatherCodeIndex.has(key)) {
            weatherCodeIndex.set(key, weatherCodes.length);
            weatherCodes.push(key);
        }
        return weatherCodeIndex.get(key) as number;
    }

    const getJson = <T>(url: string) => fetchJson<T>(url, fetchImpl);

    async function fetchFromAPI(): Promise<Dataset> {
        const citiesRaw = await getJson<Array<Record<string, unknown>>>(`${apiBase}/query.php?table=cities&limit=2000`);

        // `cities` also holds the 441-point weather grid and 1,257-point soil grid; neither is a named place, so every
        // selector built on this stays scoped to place_type 'city'.
        const namedCities = citiesRaw.filter((c) => c.place_type === 'city');
        const idToName = new Map(namedCities.map((c) => [String(c.id), c.name as string]));

        // Weather rows are scoped server-side to the named-city ids. query.php always orders by date ascending, so an
        // unscoped request is truncated by the row limit long before it reaches recent years (production bug, 2026-08-24).
        const namedCityIds = namedCities.map((c) => c.id).join(',');
        const rowsRaw = await getJson<Array<Record<string, unknown>>>(
            `${apiBase}/query.php?table=weather_daily&columns=${BASE_COLUMNS.join(',')}&city_id=${namedCityIds}&limit=500000`,
        );
        const cities: Record<string, CityData> = {};
        for (const c of namedCities) {
            cities[c.name as string] = {
                id: c.id as number | string,
                lat: numOrNull(c.latitude),
                lon: numOrNull(c.longitude),
                lastChecked: null,
                rows: [],
            };
        }

        const maxUpdated: Record<string, string> = {};
        for (const r of rowsRaw) {
            const name = idToName.get(String(r.city_id));
            if (!name) continue;
            cities[name].rows.push({
                date: r.date as string,
                tmax: numOrNull(r.temp_max_f),
                tmin: numOrNull(r.temp_min_f),
                fmax: numOrNull(r.feels_max_f),
                fmin: numOrNull(r.feels_min_f),
                precip: numOrNull(r.precipitation_in),
                gust: numOrNull(r.wind_gust_max_mph),
                wx: (r.weather_condition as string) || null,
            });
            const updated = r.updated_at as string | undefined;
            if (updated && (!maxUpdated[name] || updated > maxUpdated[name])) {
                maxUpdated[name] = updated;
            }
        }

        for (const name of Object.keys(cities)) {
            cities[name].rows.sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
            cities[name].lastChecked = maxUpdated[name] ? maxUpdated[name].slice(0, 10) : null;
        }

        return { fetchedAt: new Date(now()).toISOString(), cities };
    }

    // Compact form for localStorage: rows become positional arrays with the weather condition interned as an integer code.
    function toCompact(parsed: Dataset): CompactData {
        const compactCities: CompactData['cities'] = {};
        for (const [name, city] of Object.entries(parsed.cities)) {
            compactCities[name] = {
                id: city.id,
                lat: city.lat,
                lon: city.lon,
                lastChecked: city.lastChecked,
                rows: city.rows.map((r) => [r.date, r.tmax, r.tmin, r.fmax, r.fmin, r.precip, r.gust, wxCode(r.wx)]),
            };
        }
        return { fetchedAt: parsed.fetchedAt, weatherCodes, cities: compactCities };
    }

    function fromCompact(compact: CompactData): Dataset {
        const codes = compact.weatherCodes || [];
        const cities: Record<string, CityData> = {};
        for (const [name, city] of Object.entries(compact.cities)) {
            cities[name] = {
                id: city.id,
                lat: city.lat,
                lon: city.lon,
                lastChecked: city.lastChecked,
                rows: city.rows.map((r) => ({
                    date: r[0],
                    tmax: r[1],
                    tmin: r[2],
                    fmax: r[3],
                    fmin: r[4],
                    precip: r[5],
                    gust: r[6],
                    wx: codes[r[7]] ?? null,
                })),
            };
        }
        return { fetchedAt: compact.fetchedAt, cities };
    }

    function readCache(): { compact: CompactData; parsed: Dataset } | null {
        try {
            const raw = getStorage().getItem(CACHE_KEY);
            if (!raw) return null;
            const compact = JSON.parse(raw) as CompactData;
            return { compact, parsed: fromCompact(compact) };
        } catch (e) {
            warn('WeatherData: cache read failed', e);
            return null;
        }
    }

    function writeCache(parsed: Dataset): void {
        try {
            const compact = toCompact(parsed);
            getStorage().setItem(CACHE_KEY, JSON.stringify(compact));
        } catch (e) {
            warn('WeatherData: cache write failed (data will still work this session)', e);
        }
    }

    function isFresh(fetchedAt: string): boolean {
        return now() - new Date(fetchedAt).getTime() < CACHE_TTL_MS;
    }

    function looksSane(parsed: Dataset): boolean {
        const names = Object.keys(parsed.cities);
        if (!names.length) return false;
        let total = 0;
        for (const name of names) {
            const rows = parsed.cities[name].rows;
            if (!rows.length) return false;
            total += rows.length;
        }
        return total >= MIN_ROWS_TOTAL;
    }

    /** Loads weather data, preferring a fresh local cache, falling back to the network, and to a stale cache if the network fails. */
    function load({ force = false }: { force?: boolean } = {}): Promise<LoadResult> {
        if (loadPromise && !force) return loadPromise;

        loadPromise = (async () => {
            const cached = force ? null : readCache();
            if (cached && isFresh(cached.parsed.fetchedAt) && looksSane(cached.parsed)) {
                cache = cached.parsed;
                return { ...cache, fromCache: true };
            }
            if (cached && !looksSane(cached.parsed)) {
                warn('WeatherData: cached data failed sanity check, discarding and re-fetching');
            }

            try {
                const parsed = await fetchFromAPI();
                if (!looksSane(parsed)) {
                    throw new Error('API response failed sanity check (too few cities/rows) — not caching or using it');
                }
                writeCache(parsed);
                cache = parsed;
                return { ...cache, fromCache: false };
            } catch (err) {
                if (cached && looksSane(cached.parsed)) {
                    warn('WeatherData: fresh fetch failed or was invalid, using last known-good cache', err);
                    cache = cached.parsed;
                    return { ...cache, fromCache: true, stale: true };
                }
                throw err;
            }
        })();

        return loadPromise;
    }

    function refresh(): Promise<LoadResult> {
        return load({ force: true });
    }

    function getCityNames(): string[] {
        if (!cache) return [];
        return Object.keys(cache.cities).sort();
    }

    function getCity(name: string): CityData | undefined {
        return cache ? cache.cities[name] : undefined;
    }

    function getAllCities(): Record<string, CityData> {
        return cache ? cache.cities : {};
    }

    /**
     * On-demand fetch of every column for one city (all enrichment data, not just the base 8), for the sections that
     * need it, without inflating the eager bulk load every page pays for.
     */
    function getCityEnrichment<T = Array<Record<string, unknown>>>(name: string): Promise<T> {
        if (enrichmentCache.has(name)) return enrichmentCache.get(name) as Promise<T>;
        const promise = (async () => {
            const city = cache && cache.cities[name];
            if (!city) throw new Error(`WeatherData.getCityEnrichment: unknown city "${name}"`);
            return getJson<T>(`${apiBase}/query.php?table=weather_daily&city_id=${city.id}&limit=20000`);
        })();
        enrichmentCache.set(name, promise);
        return promise;
    }

    /** On-demand fetch of a city's full air_quality_daily history, a separate table (data from about Oct 2022 on). */
    function getCityAirQuality<T = Array<Record<string, unknown>>>(name: string): Promise<T> {
        if (airQualityCache.has(name)) return airQualityCache.get(name) as Promise<T>;
        const promise = (async () => {
            const city = cache && cache.cities[name];
            if (!city) throw new Error(`WeatherData.getCityAirQuality: unknown city "${name}"`);
            return getJson<T>(`${apiBase}/query.php?table=air_quality_daily&city_id=${city.id}&limit=5000`);
        })();
        airQualityCache.set(name, promise);
        return promise;
    }

    return { load, refresh, getCityNames, getCity, getAllCities, getCityEnrichment, getCityAirQuality };
}

export type WeatherData = ReturnType<typeof createWeatherData>;

/** The instance pages use (one load per page view, shared by everything on the page). */
export const weatherData: WeatherData = createWeatherData();
