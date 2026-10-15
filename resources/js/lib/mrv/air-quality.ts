/**
 * The Air Quality & UV page's rules (public_html/js/air-quality.js), ported in port phase 5: the two health-band scales
 * (EPA's US AQI, WHO's UV index) with their status colors, how a value is classified, the canvas plugin that draws the
 * bands behind a chart, the statistics cards, the share of days in each category, and the chart configurations. Golden
 * tests run the legacy script over the saved data for two cities (air-quality.test.tsx).
 */
import type { ChartConfiguration } from 'chart.js';
import { CATEGORICAL, PALETTE } from './chart-theme';
import { type StatCardData } from './earthquakes';
import { dateParts, getDateRange, mean, MONTH_NAMES, num, type ApiRow } from './stats';

// Reserved status colors (good, moderate, elevated, high, severe, extreme), never used for a series. Every band also
// carries its name, because yellow is below 3:1 against the card by nature.
export const STATUS = { good: '#3E9F6B', moderate: '#D2A92B', elevated: '#E0692A', high: '#B02C2C', severe: '#553A9A', extreme: '#6E2030' };

export interface Band {
    hi: number;
    name: string;
    label: string;
    short?: string;
    color: string;
    ink: string;
}

// `hi` is the inclusive upper bound, used for classifying and as the drawn edge. AQI is an integer, so values are rounded
// before classifying (a 100.4 monthly average reads as Moderate, as EPA reports it); UV edges sit at .5 so the integer WHO
// categories classify exactly and fractional monthly averages fall on the nearer side.
export const AQI_BANDS: Band[] = [
    { hi: 50, name: 'Good', label: 'Good', color: STATUS.good, ink: '#2E2A26' },
    { hi: 100, name: 'Moderate', label: 'Moderate', color: STATUS.moderate, ink: '#2E2A26' },
    { hi: 150, name: 'Unhealthy for Sensitive Groups', label: 'Sensitive Groups', short: 'Sensitive', color: STATUS.elevated, ink: '#2E2A26' },
    { hi: 200, name: 'Unhealthy', label: 'Unhealthy', color: STATUS.high, ink: '#FFFDF9' },
    { hi: 300, name: 'Very Unhealthy', label: 'Very Unhealthy', color: STATUS.severe, ink: '#FFFDF9' },
    { hi: 500, name: 'Hazardous', label: 'Hazardous', color: STATUS.extreme, ink: '#FFFDF9' },
];
export const UV_BANDS: Band[] = [
    { hi: 2.5, name: 'Low', label: 'Low', color: STATUS.good, ink: '#2E2A26' },
    { hi: 5.5, name: 'Moderate', label: 'Moderate', color: STATUS.moderate, ink: '#2E2A26' },
    { hi: 7.5, name: 'High', label: 'High', color: STATUS.elevated, ink: '#2E2A26' },
    { hi: 10.5, name: 'Very High', label: 'Very High', color: STATUS.high, ink: '#FFFDF9' },
    // WHO's Extreme category is open-ended (11+); 14 is a drawing ceiling that clears any daily peak this region records.
    { hi: 14, name: 'Extreme', label: 'Extreme', color: STATUS.severe, ink: '#FFFDF9' },
];
export const BANDS_BY_METRIC: Record<string, Band[]> = { aqi_us: AQI_BANDS, uv_index_max: UV_BANDS };

export function bandFor(bands: Band[], value: number | null | undefined): Band | null {
    if (value == null || Number.isNaN(value)) return null;
    const v = bands === AQI_BANDS ? Math.round(value) : value;
    return bands.find((b) => v <= b.hi) || bands[bands.length - 1];
}

/** The top of the band that contains the largest value, so the chart always shows that band in full. */
export function bandCeiling(bands: Band[], values: Array<number | null | undefined>): number | undefined {
    const max = Math.max(...values.filter((v): v is number => v != null));
    if (!Number.isFinite(max)) return undefined;
    return (bandFor(bands, max) || bands[bands.length - 1]).hi;
}

export function withAlpha(hex: string, alpha: number): string {
    const n = parseInt(hex.slice(1), 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
}

/* eslint-disable @typescript-eslint/no-explicit-any -- Chart.js plugin hooks receive its own chart object */
function visibleBands(chart: any, bands: Band[]) {
    const y = chart.scales.y;
    const out: Array<{ band: Band; top: number; bottom: number }> = [];
    let lo = y.min;
    for (const band of bands) {
        if (lo >= y.max) break;
        const hi = Math.min(band.hi, y.max);
        out.push({ band, top: y.getPixelForValue(hi), bottom: y.getPixelForValue(lo) });
        lo = band.hi;
    }
    return out;
}

/**
 * Draws the category bands as recessive tinted zones behind the data, each named at its upper edge. Registered per chart
 * (`plugins: [thresholdBandsPlugin]`) and configured through `options.plugins.thresholdBands = { bands }`; a chart whose
 * metric has no bands passes `bands: null` and draws nothing. Fills go under the data; labels go over it on a small cream
 * backing, so a bar or line that reaches a band's top edge cannot hide the name of the band it is in.
 */
export const thresholdBandsPlugin = {
    id: 'thresholdBands',
    beforeDatasetsDraw(chart: any, _args: unknown, opts: any) {
        const bands: Band[] | null | undefined = opts && opts.bands;
        if (!bands || !chart.scales.y) return;
        const { ctx, chartArea } = chart;
        ctx.save();
        for (const { band, top, bottom } of visibleBands(chart, bands)) {
            ctx.fillStyle = withAlpha(band.color, 0.13);
            ctx.fillRect(chartArea.left, top, chartArea.right - chartArea.left, bottom - top);
        }
        ctx.restore();
    },
    afterDatasetsDraw(chart: any, _args: unknown, opts: any) {
        const bands: Band[] | null | undefined = opts && opts.bands;
        if (!bands || !chart.scales.y) return;
        const { ctx, chartArea } = chart;
        const narrow = chartArea.right - chartArea.left < 480;

        // Highest drawn point at each edge of the plot, so a label can pick the side where it will not sit on data.
        const edgeTop = (pickLast: boolean) => {
            let best = Infinity;
            chart.data.datasets.forEach((_: unknown, i: number) => {
                const meta = chart.getDatasetMeta(i);
                if (meta.hidden || !meta.data.length) return;
                const el = meta.data[pickLast ? meta.data.length - 1 : 0];
                if (el && Number.isFinite(el.y)) best = Math.min(best, el.y);
            });
            return best;
        };
        const leftTop = edgeTop(false);
        const rightTop = edgeTop(true);

        ctx.save();
        ctx.font = `600 ${narrow ? 9 : 10}px 'Inter', sans-serif`;
        ctx.textBaseline = 'middle';
        for (const { band, top, bottom } of visibleBands(chart, bands)) {
            if (bottom - top < 15) continue;
            const text = (narrow && band.short) || band.label;
            const w = ctx.measureText(text).width;
            const yMid = top + 9;
            const labelBottom = yMid + 7;
            // Prefer the right edge; use the left if the right edge's data reaches up into this label and the left edge's does not.
            const rightClear = rightTop > labelBottom;
            const leftClear = leftTop > labelBottom;
            const useLeft = !rightClear && leftClear;
            const x = useLeft ? chartArea.left + 6 : chartArea.right - 6;
            ctx.textAlign = useLeft ? 'left' : 'right';
            ctx.fillStyle = 'rgba(255,253,249,0.82)';
            ctx.beginPath();
            ctx.roundRect(useLeft ? x - 5 : x - w - 5, yMid - 7, w + 10, 14, 4);
            ctx.fill();
            ctx.fillStyle = PALETTE.dusk;
            ctx.fillText(text, x, yMid);
        }
        ctx.restore();
    },
};
/* eslint-enable @typescript-eslint/no-explicit-any */

export const MAX_COMPARE_YEARS = 6;
// [key, label, unit]
export const METRICS: Array<[string, string, string]> = [
    ['aqi_us', 'AQI', 'US AQI'],
    ['pm2_5', 'PM2.5', 'µg/m³'],
    ['pm10', 'PM10', 'µg/m³'],
    ['ozone', 'Ozone', 'µg/m³'],
    ['uv_index_max', 'UV Index', 'UV Index'],
];
export const DEFAULT_METRIC = 'aqi_us';

// Canonical Jan 1 to Dec 31 "MM-DD" labels (366, includes Feb 29) so daily curves from different years share one axis.
export const MMDD_LABELS: string[] = (() => {
    const labels: string[] = [];
    const d = new Date(2020, 0, 1); // 2020 is a leap year
    while (d.getFullYear() === 2020) {
        labels.push(`${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`);
        d.setDate(d.getDate() + 1);
    }
    return labels;
})();

// [startIndex, endIndexExclusive] into MMDD_LABELS for each month (1 to 12).
export const MONTH_RANGES: Array<[number, number]> = Array.from({ length: 12 }, (_, m) => {
    const mm = String(m + 1).padStart(2, '0');
    const first = MMDD_LABELS.findIndex((l) => l.startsWith(`${mm}-`));
    let last = first;
    for (let i = first; i < MMDD_LABELS.length && MMDD_LABELS[i].startsWith(`${mm}-`); i++) last = i;
    return [first, last + 1];
});

export function buildYearSeries(rows: ApiRow[], year: number, field: string): Array<number | null> {
    const byMMDD = new Map<string, number | null>();
    for (const r of rows) {
        if (dateParts(r.date).year !== year) continue;
        byMMDD.set(r.date.slice(5), num(r[field]));
    }
    return MMDD_LABELS.map((mmdd) => (byMMDD.has(mmdd) ? (byMMDD.get(mmdd) as number | null) : null));
}

/** The year buttons: years that no longer exist for the city drop out, and with none left the latest three are chosen. */
export function normalizeCompareYears(chosen: Set<number>, years: number[]): Set<number> {
    const kept = new Set(Array.from(chosen).filter((y) => years.includes(y)));
    if (kept.size === 0) years.slice(-3).forEach((y) => kept.add(y));
    return kept;
}

/** A click on a year button: removes it (never the last one) or adds it (at most six). */
export function toggleCompareYear(chosen: Set<number>, year: number): Set<number> {
    const next = new Set(chosen);
    if (next.has(year)) {
        if (next.size > 1) next.delete(year);
    } else if (next.size < MAX_COMPARE_YEARS) {
        next.add(year);
    }
    return next;
}

export interface Panel {
    kind: 'cards' | 'loading' | 'error';
    cards?: StatCardData[];
    text?: string;
}

export function aqStats(rows: ApiRow[]): Panel {
    if (!rows.length) return { kind: 'loading', text: 'No air quality data for this city yet — the D4 backfill is still catching up.' };
    const aqiRows = rows.filter((r) => num(r.aqi_us) != null);
    const avgAqi = mean(aqiRows.map((r) => num(r.aqi_us)));
    let worst: ApiRow | null = null;
    for (const r of aqiRows) {
        if (!worst || (num(r.aqi_us) as number) > (num(worst.aqi_us) as number)) worst = r;
    }
    const dateRange = getDateRange(rows) as { first: string; last: string };
    const avgBand = bandFor(AQI_BANDS, avgAqi);
    const worstBand = worst ? bandFor(AQI_BANDS, num(worst.aqi_us)) : null;

    return {
        kind: 'cards',
        cards: [
            {
                label: 'Data Range',
                value: `${dateRange.first.slice(0, 7)}–${dateRange.last.slice(0, 7)}`,
                sub: `${rows.length.toLocaleString()} days recorded`,
            },
            {
                label: 'Typical AQI',
                value: avgAqi != null ? String(avgAqi) : '—',
                sub: avgBand ? `${avgBand.name} · average of daily peak US AQI` : 'Average of daily peak US AQI',
            },
            {
                label: 'Worst AQI Recorded',
                value: worst ? String(num(worst.aqi_us)) : '—',
                sub: worstBand ? `${worstBand.name} · ${(worst as ApiRow).date}` : worst ? worst.date : '',
            },
        ],
    };
}

export interface DaysByCategory {
    segments: Array<{ band: Band; pct: number; title: string; text: string }>;
    legend: Array<{ band: Band; text: string }>;
    note: string;
}

/** The share of days in each category over the trailing year (the 365 days ending at the last day with an AQI). */
export function daysByCategory(rows: ApiRow[]): DaysByCategory | null {
    const aqiRows = rows.filter((r) => num(r.aqi_us) != null);
    if (!aqiRows.length) return null;
    const last = aqiRows[aqiRows.length - 1].date;
    const startD = new Date(`${last}T00:00:00`);
    startD.setDate(startD.getDate() - 364);
    const start = `${startD.getFullYear()}-${String(startD.getMonth() + 1).padStart(2, '0')}-${String(startD.getDate()).padStart(2, '0')}`;
    const windowRows = aqiRows.filter((r) => r.date >= start);

    const counts = new Map<string, number>(AQI_BANDS.map((b) => [b.name, 0]));
    for (const r of windowRows) {
        const b = bandFor(AQI_BANDS, num(r.aqi_us));
        if (b) counts.set(b.name, (counts.get(b.name) as number) + 1);
    }
    const total = windowRows.length;

    const segments = AQI_BANDS.flatMap((b) => {
        const n = counts.get(b.name) as number;
        if (!n) return [];
        const pct = (n / total) * 100;
        const showLabel = pct >= 9;
        return [{ band: b, pct, title: `${b.name}: ${n} days (${pct.toFixed(0)}%)`, text: showLabel ? `${pct.toFixed(0)}%` : '' }];
    });
    const legend = AQI_BANDS.map((b) => {
        const n = counts.get(b.name) as number;
        const pct = total ? (n / total) * 100 : 0;
        return { band: b, text: `${n} day${n === 1 ? '' : 's'} (${pct.toFixed(0)}%)` };
    });
    const note =
        total >= 300
            ? `Daily peak US AQI, ${start} to ${last} (${total} days).`
            : `Daily peak US AQI, ${start} to ${last} — only ${total} days available in this window.`;

    return { segments, legend, note };
}

type Cfg = ChartConfiguration<any, any, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

/** Shared shape of the two banded monthly bar charts: bars wear the color of the band they fall in, and the tooltip names the category. */
export function bandedBarConfig(bands: Band[], labels: string[], values: Array<number | null>, unitLabel: string): Cfg {
    return {
        type: 'bar',
        plugins: [thresholdBandsPlugin],
        data: {
            labels,
            datasets: [
                {
                    label: unitLabel,
                    data: values,
                    backgroundColor: values.map((v) => (bandFor(bands, v) || ({} as Partial<Band>)).color || PALETTE.dusk),
                    borderRadius: 4,
                },
            ],
        },
        options: {
            responsive: true,
            scales: {
                y: { title: { display: true, text: unitLabel }, beginAtZero: true, max: bandCeiling(bands, values), grid: { display: false } },
                x: { grid: { display: false } },
            },
            plugins: {
                legend: { display: false },
                thresholdBands: { bands },
                tooltip: {
                    callbacks: {
                        label: (ctx: { parsed: { y: number } }) => {
                            const v = ctx.parsed.y;
                            const b = bandFor(bands, v);
                            return b ? `${v} — ${b.name}` : `${v}`;
                        },
                    },
                },
            },
        },
    } as Cfg;
}

type Normals = ReturnType<typeof import('./stats').computeAqiNormals>;

export const aqiConfig = (normals: Normals): Cfg =>
    bandedBarConfig(
        AQI_BANDS,
        normals.map((n) => n.monthName),
        normals.map((n) => n.avgAqi),
        'US AQI',
    );
export const uvConfig = (normals: Normals): Cfg =>
    bandedBarConfig(
        UV_BANDS,
        normals.map((n) => n.monthName),
        normals.map((n) => n.avgUvMax),
        'UV Index',
    );

export const particulatesConfig = (normals: Normals): Cfg =>
    ({
        type: 'line',
        data: {
            labels: normals.map((n) => n.monthName),
            datasets: [
                {
                    label: 'PM2.5',
                    data: normals.map((n) => n.avgPm25),
                    borderColor: PALETTE.terracotta,
                    backgroundColor: PALETTE.terracotta,
                    tension: 0.3,
                    borderWidth: 2,
                },
                {
                    label: 'PM10',
                    data: normals.map((n) => n.avgPm10),
                    borderColor: PALETTE.sky,
                    backgroundColor: PALETTE.sky,
                    tension: 0.3,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'µg/m³' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: { legend: { position: 'top' } },
        },
    }) as Cfg;

export const ozoneConfig = (normals: Normals): Cfg =>
    ({
        type: 'line',
        data: {
            labels: normals.map((n) => n.monthName),
            datasets: [
                {
                    label: 'Ozone (µg/m³)',
                    data: normals.map((n) => n.avgOzone),
                    borderColor: PALETTE.teal,
                    backgroundColor: PALETTE.teal,
                    tension: 0.3,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'µg/m³' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: { legend: { display: false } },
        },
    }) as Cfg;

/** The year-comparison chart and the note under it about years with missing days in the view. */
export function compareConfig(rows: ApiRow[], chosen: Set<number>, metric: string, zoomMonth: number | null): { config: Cfg; gapNote: string } {
    const years = Array.from(chosen).sort((a, b) => a - b);
    const metricInfo = METRICS.find(([key]) => key === metric) || METRICS[0];
    const unit = metricInfo[2];
    const slice = zoomMonth ? MONTH_RANGES[zoomMonth - 1] : null;
    const labels = slice ? MMDD_LABELS.slice(slice[0], slice[1]) : MMDD_LABELS;
    const sliced = <T>(series: T[]) => (slice ? series.slice(slice[0], slice[1]) : series);
    const datasets = years.map((y, i) => {
        const color = CATEGORICAL[i % CATEGORICAL.length];
        return {
            label: String(y),
            data: sliced(buildYearSeries(rows, y, metric)),
            borderColor: color,
            backgroundColor: color,
            borderWidth: 1.8,
            pointRadius: 0,
            tension: 0.2,
        };
    });
    // Year lines keep their categorical colors; the bands behind them give the health context for AQI and UV only.
    const bands = BANDS_BY_METRIC[metric] || null;
    const allValues = datasets.flatMap((d) => d.data);
    const config = {
        type: 'line',
        plugins: [thresholdBandsPlugin],
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { maxTicksLimit: 12 }, grid: { display: false } },
                y: {
                    title: { display: true, text: unit },
                    beginAtZero: !!bands,
                    max: bands ? bandCeiling(bands, allValues) : undefined,
                    grid: { display: !bands },
                },
            },
            plugins: {
                legend: { position: 'top' },
                thresholdBands: { bands },
                tooltip: {
                    callbacks: {
                        label: (ctx: { parsed: { y: number | null }; dataset: { label: string } }) => {
                            const v = ctx.parsed.y;
                            if (v == null) return `${ctx.dataset.label}: —`;
                            const b = bands ? bandFor(bands, v) : null;
                            return b ? `${ctx.dataset.label}: ${v} — ${b.name}` : `${ctx.dataset.label}: ${v}`;
                        },
                    },
                },
            },
        },
    } as Cfg;

    const partialYears = years.filter((y) => {
        const present = new Set<string>();
        for (const r of rows) {
            if (dateParts(r.date).year === y) present.add(r.date.slice(5));
        }
        const isLeap = (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
        return labels.some((mmdd) => {
            if (mmdd === '02-29' && !isLeap) return false; // not a real gap: that date does not exist this year
            return !present.has(mmdd);
        });
    });
    return { config, gapNote: partialYears.length ? `Partial data in this view for: ${partialYears.join(', ')}.` : '' };
}

export const MONTH_CHOICES: Array<[string, string]> = [['', 'Full Year'], ...MONTH_NAMES.map((name, i): [string, string] => [String(i + 1), name])];
