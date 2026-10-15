/**
 * The drought section of the Overview (public_html/js/drought.js), ported in port phase 4: county-level U.S. Drought
 * Monitor statistics, the cumulative percentages turned into "this category only" bands, the statistics cards, the
 * time-span choices, the chart and the table of the latest twelve weeks. Golden tests run the legacy script over the
 * saved response (drought.test.tsx).
 */
import type { ChartConfiguration, Scale } from 'chart.js';
import { fetchJson, MRV_API_BASE, type FetchLike } from './api';
import { type StatCardData } from './earthquakes';

export interface DroughtRow {
    date: string;
    d0_pct: string | number;
    d1_pct: string | number;
    d2_pct: string | number;
    d3_pct: string | number;
    d4_pct: string | number;
}

// An ordered scale, one hue from light to dark (the earthquake page's rust hue); the lightest steps are low contrast, so the
// legend, hover tooltips and the table carry identity as well as color.
export const CATEGORIES = [
    { key: 'd0', short: 'D0', name: 'Abnormally Dry', color: '#F5DBD6' },
    { key: 'd1', short: 'D1', name: 'Moderate Drought', color: '#E29A8D' },
    { key: 'd2', short: 'D2', name: 'Severe Drought', color: '#D05843' },
    { key: 'd3', short: 'D3', name: 'Extreme Drought', color: '#933525' },
    { key: 'd4', short: 'D4', name: 'Exceptional Drought', color: '#491B12' },
] as const;
export const INK = '#55504A';

export const RANGES = [
    { key: '5', label: 'Last 5 Years', years: 5 as number | null },
    { key: '10', label: 'Last 10 Years', years: 10 as number | null },
    { key: 'all', label: 'Since 2000', years: null as number | null },
];
export const DEFAULT_RANGE = 'all';

const num = (v: unknown) => Number(v);
const pct = (row: DroughtRow, key: string) => num((row as unknown as Record<string, unknown>)[key + '_pct']);

/** d_i is cumulative (D1 or worse), so the share in exactly this category is d_i minus the next one down. */
export const only = (row: DroughtRow, i: number): number => Math.max(0, pct(row, CATEGORIES[i].key) - (i < 4 ? pct(row, CATEGORIES[i + 1].key) : 0));
export const fmtPct = (v: number): string => (v > 0 && v < 1 ? '<1%' : `${Math.round(v)}%`);
/** Dates arrive as plain YYYY-MM-DD; parsed as local noon so no time zone shifts the day. */
export const fmtDate = (iso: string, opts: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric', year: 'numeric' }): string =>
    new Date(`${iso}T12:00:00`).toLocaleDateString('en-US', opts);

export const fetchDrought = (fetchImpl?: FetchLike): Promise<DroughtRow[]> =>
    fetchJson<DroughtRow[]>(`${MRV_API_BASE}/query.php?table=drought_weekly&from=2000-01-01&limit=5000`, fetchImpl, (status) => `HTTP ${status}`);

export function droughtStats(rows: DroughtRow[]): StatCardData[] {
    const latest = rows[rows.length - 1];
    const worst = rows.reduce((a, b) => (num(b.d3_pct) > num(a.d3_pct) ? b : a));
    const over = rows.filter((r) => num(r.d1_pct) > 50).length;

    return [
        { label: 'In Drought Now', value: fmtPct(num(latest.d1_pct)), sub: `D1 or worse, week of ${fmtDate(latest.date)}` },
        { label: 'Abnormally Dry or Worse', value: fmtPct(num(latest.d0_pct)), sub: 'D0 or worse, same week' },
        {
            label: 'Worst Week Since 2000',
            value: fmtPct(num(worst.d3_pct)),
            sub: `in Extreme Drought or worse (D3+), week of ${fmtDate(worst.date)}`,
        },
        {
            label: 'Weeks Mostly in Drought',
            value: fmtPct((over / rows.length) * 100),
            sub: `of the ${rows.length.toLocaleString()} weeks since 2000, more than half the county was in D1 or worse`,
        },
    ];
}

export function visibleRows(rows: DroughtRow[], rangeKey: string): DroughtRow[] {
    const range = RANGES.find((r) => r.key === rangeKey) as (typeof RANGES)[number];
    if (!range.years) return rows;
    const last = rows[rows.length - 1].date;
    const cutoff = `${Number(last.slice(0, 4)) - range.years}${last.slice(4)}`;
    return rows.filter((r) => r.date >= cutoff);
}

/** The stacked-area chart: worst category at the bottom, so the dark base reads as how bad and the light top as how widespread. */
export function droughtChart(rows: DroughtRow[]): ChartConfiguration<'line', number[], string> {
    const labels = rows.map((r) => r.date);
    const datasets = CATEGORIES.map((c, i) => ({
        label: `${c.short} ${c.name}`,
        data: rows.map((r) => only(r, i)),
        backgroundColor: c.color,
        borderColor: '#FFFDF9', // a 1px edge in the card's color doubles as the gap between bands
        borderWidth: 1,
        fill: true,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0,
        order: -i, // draw D4 first, so the lighter bands sit above it
    }));

    return {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: INK,
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 9,
                        callback(this: Scale, value: number | string) {
                            return this.getLabelForValue(value as number).slice(0, 4);
                        },
                    },
                },
                y: {
                    stacked: true,
                    min: 0,
                    max: 100,
                    ticks: { color: INK, callback: (v: number | string) => `${v}%` },
                    grid: { color: 'rgba(46, 42, 38, 0.08)' },
                    title: { display: true, text: 'Share of county', color: INK },
                },
            },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'rectRounded', color: INK } },
                tooltip: {
                    callbacks: {
                        title: (items) => `Week of ${fmtDate(items[0].label)}`,
                        // Hover lists every category (including 0%), worst first, to match the stack.
                        label: (item) => `${item.dataset.label}: ${fmtPct(item.parsed.y as number)}`,
                    },
                    itemSort: (a, b) => b.datasetIndex - a.datasetIndex,
                },
            },
        },
    };
}

export const tableRows = (rows: DroughtRow[]): DroughtRow[] => rows.slice(-12).reverse();
export const tableHead = (): string[] => ['Week of', ...CATEGORIES.map((c) => `${c.short} (${c.name}) or worse`)];
export const cumulative = (row: DroughtRow, i: number): string => fmtPct(pct(row, CATEGORIES[i].key));
