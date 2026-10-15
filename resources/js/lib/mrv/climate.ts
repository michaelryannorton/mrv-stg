/**
 * The Climate Projections page's rules (public_html/js/climate.js), ported in port phase 5. Seven CMIP6 high-resolution
 * models, one high-emissions pathway, 1991 to 2050, for the eleven weather-page cities. Everything comes pre-aggregated from
 * climate_summary.php; the page computes medians, ranges and ten-year averages across the seven models. Golden tests run the
 * legacy script over the saved response (climate.test.tsx).
 */
import type { ChartConfiguration } from 'chart.js';
import { fetchJson, MRV_API_BASE, type FetchLike } from './api';
import { type StatCardData } from './earthquakes';

type Cfg = ChartConfiguration<any, any, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

// cities.id 1 to 11, as confirmed against the live API.
export const CITIES = [
    { id: 1, name: 'Victorville' },
    { id: 2, name: 'Hesperia' },
    { id: 3, name: 'Apple Valley' },
    { id: 4, name: 'Adelanto' },
    { id: 5, name: 'Barstow' },
    { id: 6, name: 'Phelan' },
    { id: 7, name: 'Big Bear City' },
    { id: 8, name: 'Joshua Tree' },
    { id: 9, name: 'Mojave' },
    { id: 10, name: 'Lucerne Valley' },
    { id: 11, name: 'Oak Hills' },
];

export interface MetricDef {
    label: string;
    unit: string;
    digits: number;
    rule?: (d: { temp_max_f: number | null; temp_min_f: number | null }) => boolean;
    observed: 'count' | 'mean' | 'sum';
    field?: string;
}
export const METRICS: Record<string, MetricDef> = {
    days_ge_100: { label: 'Days at or above 100°F', unit: 'days a year', digits: 0, rule: (d) => (d.temp_max_f as number) >= 100, observed: 'count' },
    days_ge_105: { label: 'Days at or above 105°F', unit: 'days a year', digits: 0, rule: (d) => (d.temp_max_f as number) >= 105, observed: 'count' },
    tmax_f: { label: 'Average daily high', unit: '°F', digits: 1, observed: 'mean', field: 'temp_max_f' },
    days_le_32: {
        label: 'Nights at or below 32°F',
        unit: 'nights a year',
        digits: 0,
        rule: (d) => (d.temp_min_f as number) <= 32,
        observed: 'count',
    },
    precip_in: { label: 'Yearly precipitation', unit: 'inches', digits: 1, observed: 'sum', field: 'precipitation_in' },
};
export const METRIC_ORDER = ['days_ge_100', 'days_ge_105', 'tmax_f', 'days_le_32', 'precip_in'];
export const DEFAULT_METRIC = 'days_ge_100';

export const BASE: [number, number] = [1991, 2020];
export const FUTURE: [number, number] = [2035, 2049];
export const OBSERVED_YEARS: [number, number] = [2017, 2025]; // full years of recorded weather (2016 is excluded site-wide)
export const SMOOTH = 10;
export const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const MODEL_NAMES: Record<string, string> = {
    CMCC_CM2_VHR4: 'CMCC-CM2-VHR4 (Italy)',
    EC_Earth3P_HR: 'EC-Earth3P-HR (Europe)',
    FGOALS_f3_H: 'FGOALS-f3-H (China)',
    HiRAM_SIT_HR: 'HiRAM-SIT-HR (Taiwan)',
    MPI_ESM1_2_XR: 'MPI-ESM1-2-XR (Germany)',
    MRI_AGCM3_2_S: 'MRI-AGCM3-2-S (Japan)',
    NICAM16_8S: 'NICAM16-8S (Japan)',
};
export const WARM = '#B5472E';
export const WARM_FAINT = 'rgba(181, 71, 46, 0.35)';
export const INK = '#55504A';

export interface Entry {
    [key: string]: number | null;
}
export interface ClimateData {
    snapshot: unknown;
    years: number[];
    models: string[];
    annual: Record<string, Array<Entry | null>>;
    monthly: Record<string, { baseline: Array<Entry | null>; future: Array<Entry | null> }>;
    queried_from?: string | null;
}

export const fmtDate = (iso: string): string =>
    new Date(`${iso}T12:00:00`).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
export const fmt = (v: number, d = 1): string => Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
/** The sign follows the rounded number, so a change that rounds to zero shows as "0", never "−0". */
export const signed = (v: number, d = 1): string => {
    const r = Number(v.toFixed(d));
    return `${r > 0 ? '+' : r < 0 ? '−' : ''}${fmt(Math.abs(r), d)}`;
};

export const mean = (a: number[]): number => a.reduce((s, x) => s + x, 0) / a.length;
export function median(a: number[]): number {
    const v = [...a].sort((x, y) => x - y);
    const n = v.length;
    return n % 2 ? v[(n - 1) / 2] : (v[n / 2 - 1] + v[n / 2]) / 2;
}

/** Average of a model's yearly values over [from, to]; null if any year is missing. */
export function periodMean(data: ClimateData, model: string, key: string, [from, to]: [number, number]): number | null {
    const vals: Array<number | null> = [];
    data.years.forEach((y, i) => {
        if (y >= from && y <= to) {
            const e = data.annual[model][i];
            vals.push(e ? e[key] : null);
        }
    });
    return vals.length && vals.every((v) => v != null) ? mean(vals as number[]) : null;
}

/** [min, median, max] across the models of a per-model number, ignoring models without one. */
export function spread(values: Array<number | null>): [number, number, number] | null {
    const v = values.filter((x): x is number => x != null);
    return v.length ? [Math.min(...v), median(v), Math.max(...v)] : null;
}

export function climateStats(data: ClimateData, cityId: number): { cards: StatCardData[]; sub: string; note: string } {
    const change = (key: string) =>
        spread(
            data.models.map((m) => {
                const b = periodMean(data, m, key, BASE);
                const f = periodMean(data, m, key, FUTURE);
                return b != null && f != null ? f - b : null;
            }),
        ) as [number, number, number];
    const level = (key: string, p: [number, number]) => spread(data.models.map((m) => periodMean(data, m, key, p))) as [number, number, number];
    const hot = change('tmax_f');
    const days = [level('days_ge_100', BASE), level('days_ge_100', FUTURE)];
    const frost = [level('days_le_32', BASE), level('days_le_32', FUTURE)];
    const pr = spread(
        data.models.map((m) => {
            const b = periodMean(data, m, 'precip_in', BASE);
            const f = periodMean(data, m, 'precip_in', FUTURE);
            return b ? (((f as number) - b) / b) * 100 : null;
        }),
    ) as [number, number, number];
    const range = (r: [number, number, number], dg: number, unit = '') => `models range ${signed(r[0], dg)}${unit} to ${signed(r[2], dg)}${unit}`;
    const city = (CITIES.find((c) => c.id === cityId) as (typeof CITIES)[number]).name;

    return {
        cards: [
            { label: 'Average Daily High', value: `${signed(hot[1])}°F`, sub: range(hot, 1, '°F') },
            {
                label: 'Days at or above 100°F',
                value: `${fmt(days[0][1], 0)} → ${fmt(days[1][1], 0)}`,
                sub: `a year, middle of the models; ${fmt(days[1][0], 0)} to ${fmt(days[1][2], 0)} by mid-century`,
            },
            {
                label: 'Nights at or below 32°F',
                value: `${fmt(frost[0][1], 0)} → ${fmt(frost[1][1], 0)}`,
                sub: `a year, middle of the models; ${fmt(frost[1][0], 0)} to ${fmt(frost[1][2], 0)} by mid-century`,
            },
            { label: 'Yearly Precipitation', value: `${signed(pr[1], 0)}%`, sub: `${range(pr, 0, '%')}; the models do not agree even on the sign` },
        ],
        sub: `${city}: 2035–2049 compared with the 1991–2020 average, under one high-emissions pathway, across seven models.`,
        note: `The middle of the seven models is shown, with the lowest and highest in the small print. A "→" runs from the 1991–2020 average to the 2035–2049 average.`,
    };
}

export function rolling(arr: Array<number | null>): Array<number | null> {
    return arr.map((_, i) => {
        if (i < SMOOTH - 1) return null;
        const w = arr.slice(i - SMOOTH + 1, i + 1);
        return w.every((v) => v != null) ? mean(w as number[]) : null;
    });
}

/** The year-by-year chart for one measure: ten-year averages, each model a thin line, the middle a heavy one. */
export function yearConfig(data: ClimateData, metricKey: string): Cfg {
    const m = METRICS[metricKey];
    const perModel = data.models.map((model) => rolling(data.annual[model].map((e) => (e ? e[metricKey] : null))));
    const med = data.years.map((_, i) => {
        const v = perModel.map((s) => s[i]).filter((x): x is number => x != null);
        return v.length === data.models.length ? median(v) : null;
    });
    const lo = data.years.map((_, i) => {
        const v = perModel.map((s) => s[i]).filter((x): x is number => x != null);
        return v.length ? Math.min(...v) : null;
    });
    const hi = data.years.map((_, i) => {
        const v = perModel.map((s) => s[i]).filter((x): x is number => x != null);
        return v.length ? Math.max(...v) : null;
    });
    // A ten-year average cannot start before the tenth year, so the chart starts there.
    const from = SMOOTH - 1;
    const years = data.years.slice(from);
    const datasets: object[] = perModel.map((series, i) => ({
        label: i === 0 ? 'Each of the seven models' : `model ${i + 1}`,
        data: series.slice(from),
        borderColor: WARM_FAINT,
        backgroundColor: WARM_FAINT,
        borderWidth: 1.2,
        pointRadius: 0,
        pointHoverRadius: 0,
        tension: 0.2,
        spanGaps: false,
        order: 2,
    }));
    datasets.push({
        label: 'Middle of the models',
        data: med.slice(from),
        borderColor: WARM,
        backgroundColor: WARM,
        borderWidth: 3,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0.2,
        spanGaps: false,
        order: 1,
    });

    return {
        type: 'line',
        data: { labels: years, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { display: false }, ticks: { color: INK, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                y: {
                    title: { display: true, text: `${m.label} (${m.unit}), 10-year average`, color: INK },
                    ticks: { color: INK },
                    grid: { color: 'rgba(46, 42, 38, 0.08)' },
                    beginAtZero: metricKey !== 'tmax_f',
                },
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        color: INK,
                        filter: (item: { datasetIndex: number }) => item.datasetIndex === 0 || item.datasetIndex === datasets.length - 1,
                    },
                },
                tooltip: {
                    filter: (item: { datasetIndex: number }) => item.datasetIndex === datasets.length - 1,
                    callbacks: {
                        title: (items: Array<{ label: string }>) => `${items[0].label} (average of the ${SMOOTH} years ending then)`,
                        label: (item: { parsed: { y: number } }) => `Middle of the models: ${fmt(item.parsed.y, m.digits)} ${m.unit}`,
                        afterLabel: (item: { dataIndex: number }) =>
                            lo[item.dataIndex + from] != null
                                ? `Models range ${fmt(lo[item.dataIndex + from] as number, m.digits)} to ${fmt(hi[item.dataIndex + from] as number, m.digits)}`
                                : '',
                    },
                },
            },
        },
    } as Cfg;
}

/** The month-by-month chart: change in the average daily high between 1991-2020 and 2035-2049, the range of the models and their middle. */
export function monthConfig(data: ClimateData): Cfg {
    const deltas = MONTHS.map((_, mi) =>
        data.models
            .map((model) => {
                const b = data.monthly[model].baseline[mi];
                const f = data.monthly[model].future[mi];
                return b && f ? (f.tmax_f as number) - (b.tmax_f as number) : null;
            })
            .filter((x): x is number => x != null),
    );
    const lows = deltas.map((v) => Math.min(...v));
    const highs = deltas.map((v) => Math.max(...v));
    const mids = deltas.map(median);

    return {
        data: {
            labels: MONTHS,
            datasets: [
                {
                    type: 'bar',
                    label: 'Range across the seven models',
                    data: lows.map((l, i) => [l, highs[i]]),
                    backgroundColor: WARM_FAINT,
                    borderColor: WARM_FAINT,
                    borderRadius: 3,
                    order: 2,
                },
                {
                    type: 'line',
                    label: 'Middle of the models',
                    data: mids,
                    showLine: false,
                    pointStyle: 'line',
                    pointRadius: 14,
                    pointBorderWidth: 4,
                    pointBorderColor: WARM,
                    borderColor: WARM,
                    order: 1,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false }, ticks: { color: INK } },
                y: {
                    title: { display: true, text: 'Change in average daily high (°F)', color: INK },
                    ticks: { color: INK },
                    grid: { color: 'rgba(46, 42, 38, 0.08)' },
                },
            },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, color: INK } },
                tooltip: {
                    callbacks: {
                        label: (item: { datasetIndex: number; dataIndex: number; parsed: { y: number } }) =>
                            item.datasetIndex === 0
                                ? `Range: ${signed(lows[item.dataIndex])}°F to ${signed(highs[item.dataIndex])}°F`
                                : `Middle: ${signed(item.parsed.y)}°F`,
                    },
                },
            },
        },
    } as Cfg;
}

export interface ModelRow {
    name: string;
    high: string;
    days: string;
    precip: string;
}
export function modelRows(data: ClimateData): ModelRow[] {
    return data.models.map((model) => {
        const tb = periodMean(data, model, 'tmax_f', BASE);
        const tf = periodMean(data, model, 'tmax_f', FUTURE);
        const hb = periodMean(data, model, 'days_ge_100', BASE);
        const hf = periodMean(data, model, 'days_ge_100', FUTURE);
        const pb = periodMean(data, model, 'precip_in', BASE);
        const pf = periodMean(data, model, 'precip_in', FUTURE);
        return {
            name: MODEL_NAMES[model] || model,
            high: tb != null && tf != null ? `${signed(tf - tb)}°F` : '—',
            days: hb != null && hf != null ? `${fmt(hb, 0)} → ${fmt(hf, 0)}` : '—',
            precip: pb && pf != null ? `${signed(((pf - pb) / pb) * 100, 0)}%` : '—',
        };
    });
}

export const fetchClimate = (cityId: number, fetchImpl?: FetchLike): Promise<ClimateData> =>
    fetchJson<ClimateData>(`${MRV_API_BASE}/climate_summary.php?city_id=${cityId}`, fetchImpl, (status) => `HTTP ${status}`);

export interface ObservedRow {
    temp_max_f: number | null;
    temp_min_f: number | null;
    precipitation_in: number | null;
}

/** Annual observed values from the recorded weather, for the years the models also cover. */
export async function loadObserved(cityId: number, fetchImpl?: FetchLike): Promise<Record<number, ObservedRow[]>> {
    const rows = await fetchJson<Array<Record<string, unknown>>>(
        `${MRV_API_BASE}/query.php?table=weather_daily&city_id=${cityId}&columns=temp_max_f,temp_min_f,precipitation_in&from=${OBSERVED_YEARS[0]}-01-01&limit=20000`,
        fetchImpl,
        (status) => `HTTP ${status}`,
    );
    const byYear: Record<number, ObservedRow[]> = {};
    for (const r of rows) {
        const y = Number(String(r.date).slice(0, 4));
        if (y < OBSERVED_YEARS[0] || y > OBSERVED_YEARS[1]) continue;
        (byYear[y] ||= []).push({
            temp_max_f: r.temp_max_f == null ? null : Number(r.temp_max_f),
            temp_min_f: r.temp_min_f == null ? null : Number(r.temp_min_f),
            precipitation_in: r.precipitation_in == null ? null : Number(r.precipitation_in),
        });
    }
    return byYear;
}

export function observedValue(metricKey: string, byYear: Record<number, ObservedRow[]>): { value: number; years: number } | null {
    const m = METRICS[metricKey];
    const yearly: number[] = [];
    for (const rows of Object.values(byYear)) {
        const field = (m.field || (m.rule ? (metricKey.includes('le_32') ? 'temp_min_f' : 'temp_max_f') : null)) as keyof ObservedRow;
        const valid = rows.filter((r) => r[field] != null);
        if (valid.length < 360) continue; // only years with essentially every day recorded
        if (m.observed === 'count') yearly.push(valid.filter((r) => (m.rule as NonNullable<MetricDef['rule']>)(r)).length);
        else if (m.observed === 'mean') yearly.push(mean(valid.map((r) => r[field] as number)));
        else yearly.push(valid.reduce((s, r) => s + (r[field] as number), 0));
    }
    return yearly.length ? { value: mean(yearly), years: yearly.length } : null;
}

/** The "reality check" cards under the year chart, or null when there is nothing to show. */
export function realityCards(
    data: ClimateData,
    metricKey: string,
    observed: Record<number, ObservedRow[]> | null,
): { cards: StatCardData[]; note: string } | null {
    const m = METRICS[metricKey];
    const models = spread(data.models.map((model) => periodMean(data, model, metricKey, OBSERVED_YEARS)));
    if (!observed || !models) return null;
    const obs = observedValue(metricKey, observed);
    if (!obs) return null;

    return {
        cards: [
            {
                label: `Recorded here, ${OBSERVED_YEARS[0]}–${OBSERVED_YEARS[1]}`,
                value: `${fmt(obs.value, m.digits)}`,
                sub: `${m.label}, ${m.unit}; average of ${obs.years} full years of weather records`,
            },
            {
                label: 'What the models gave for those years',
                value: `${fmt(models[1], m.digits)}`,
                sub: `middle of the seven; they ranged ${fmt(models[0], m.digits)} to ${fmt(models[2], m.digits)}`,
            },
        ],
        note: 'A rough check, not a test: nine years is short, and a model is not meant to match any particular year. It shows whether the models start from the right neighborhood.',
    };
}
