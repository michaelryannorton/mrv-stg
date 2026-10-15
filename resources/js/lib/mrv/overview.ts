/**
 * The Geography Overview's rules (public_html/js/overview.js), ported in port phase 4: the three map metrics, the per-city
 * summaries (2016 left out, it only has data from July), the popup text, the legend, and the region-wide records. The
 * statistics come from the phase 2 port of stats.js. Golden tests run the legacy script over the saved weather data
 * (overview.test.tsx).
 */
import { type StatCardData } from './earthquakes';
import { escapeHtml } from './html';
import { COLOR_STOPS } from './region-map';
import { computeAnnualSummary, computeMonthlyNormals, excludeYear, mean } from './stats';
import type { BaseRow, CityData } from './weather-data';

type Normals = ReturnType<typeof computeMonthlyNormals>;
type Summary = ReturnType<typeof computeAnnualSummary>;

export interface CityStat {
    normals: Normals;
    summary: Summary;
    rows: BaseRow[];
    lat: number | null;
    lon: number | null;
}

export interface Metric {
    label: string;
    unit: string;
    compute: (normals: Normals, summary: Summary) => number | null;
}

export const METRICS: Record<string, Metric> = {
    summerHigh: { label: 'Avg. Summer High', unit: '°F', compute: (n) => mean([n[5].avgTmax, n[6].avgTmax, n[7].avgTmax]) },
    winterLow: { label: 'Avg. Winter Low', unit: '°F', compute: (n) => mean([n[11].avgTmin, n[0].avgTmin, n[1].avgTmin]) },
    annualPrecip: { label: 'Avg. Annual Precip', unit: 'in', compute: (_n, s) => s.avgAnnualPrecip },
};
export const DEFAULT_METRIC = 'summerHigh';

/** Per-city summaries, computed once. 2016 only has data from July 17 onward, so it is excluded everywhere to avoid partial-year skew. */
export function computeCityStats(cities: Record<string, CityData>): { names: string[]; stats: Record<string, CityStat> } {
    const names = Object.keys(cities).sort();
    const stats: Record<string, CityStat> = {};
    for (const name of names) {
        const rows = excludeYear(cities[name].rows, 2016);
        stats[name] = {
            normals: computeMonthlyNormals(rows),
            summary: computeAnnualSummary(rows),
            rows,
            lat: cities[name].lat,
            lon: cities[name].lon,
        };
    }
    return { names, stats };
}

export function metricValues(metricKey: string, names: string[], stats: Record<string, CityStat>): Record<string, number | null> {
    const m = METRICS[metricKey];
    const values: Record<string, number | null> = {};
    for (const name of names) values[name] = m.compute(stats[name].normals, stats[name].summary);
    return values;
}

/** The popup of a city marker. `profileHref` is the City Explorer's address for the city, or null while that page is not ported. */
export function cityPopupHtml(name: string, value: number | null | undefined, metricKey: string, stat: CityStat, profileHref: string | null): string {
    const s = stat.summary;
    const m = METRICS[metricKey];
    const valueStr = value != null ? `${value}${m.unit}` : '—';
    return `
        <strong>${escapeHtml(name)}</strong><br>
        ${m.label}: ${valueStr}<br>
        Record high: ${s.records.hottest ? s.records.hottest.tmax + '°F' : '—'} ·
        Record low: ${s.records.coldest ? s.records.coldest.tmin + '°F' : '—'}${
            profileHref
                ? `<br>
        <a href="${escapeHtml(profileHref)}">View full city profile &rarr;</a>`
                : ''
        }
      `;
}

/** The legend under the map: the metric's name and the lower bound of each of the five color steps. */
export function legendSteps(
    min: number | null,
    max: number | null,
    metricKey: string,
): { label: string; steps: Array<{ color: string; text: string }> } | null {
    if (min == null || max == null) return null;
    const m = METRICS[metricKey];
    const n = COLOR_STOPS.length;
    return {
        label: `${m.label}:`,
        steps: COLOR_STOPS.map((color, i) => {
            const lo = Math.round((min + ((max - min) * i) / n) * 10) / 10;
            return { color, text: `${lo}${m.unit}+` };
        }),
    };
}

/** "The Region, By the Numbers": records across all cities. */
export function regionStats(names: string[], stats: Record<string, CityStat>): { cards: StatCardData[]; note: string } {
    let hottest: { city: string; row: NonNullable<Summary['records']['hottest']> } | null = null;
    let coldest: { city: string; row: NonNullable<Summary['records']['coldest']> } | null = null;
    let wettest: { city: string; row: NonNullable<Summary['records']['wettest']> } | null = null;
    let windiest: { city: string; row: NonNullable<Summary['records']['windiest']> } | null = null;
    for (const name of names) {
        const r = stats[name].summary.records;
        if (r.hottest && (!hottest || (r.hottest.tmax as number) > (hottest.row.tmax as number))) hottest = { city: name, row: r.hottest };
        if (r.coldest && (!coldest || (r.coldest.tmin as number) < (coldest.row.tmin as number))) coldest = { city: name, row: r.coldest };
        if (r.wettest && (!wettest || (r.wettest.precip as number) > (wettest.row.precip as number))) wettest = { city: name, row: r.wettest };
        if (r.windiest && (!windiest || (r.windiest.gust as number) > (windiest.row.gust as number))) windiest = { city: name, row: r.windiest };
    }
    const allDates = names.flatMap((n) => stats[n].rows).map((r) => r.date);
    const range = { first: allDates.reduce((a, b) => (a < b ? a : b)), last: allDates.reduce((a, b) => (a > b ? a : b)) };
    const years = (new Date(range.last).getTime() - new Date(range.first).getTime()) / (365.25 * 24 * 3600 * 1000);

    const cards: StatCardData[] = [
        { label: 'Cities Tracked', value: String(names.length), sub: names.join(', ') },
        { label: 'Years of Data', value: years.toFixed(0), sub: `${range.first} to ${range.last}` },
        {
            label: 'Hottest Day on Record',
            value: hottest ? `${hottest.row.tmax}°F` : '—',
            sub: hottest ? `${hottest.city}, ${hottest.row.date}` : '',
        },
        {
            label: 'Coldest Day on Record',
            value: coldest ? `${coldest.row.tmin}°F` : '—',
            sub: coldest ? `${coldest.city}, ${coldest.row.date}` : '',
        },
        {
            label: 'Wettest Day on Record',
            value: wettest ? `${wettest.row.precip}"` : '—',
            sub: wettest ? `${wettest.city}, ${wettest.row.date}` : '',
        },
        {
            label: 'Windiest Gust on Record',
            value: windiest ? `${windiest.row.gust} mph` : '—',
            sub: windiest ? `${windiest.city}, ${windiest.row.date}` : '',
        },
    ];
    return { cards, note: `Across ${names.length} communities and ${years.toFixed(0)} years of daily records.` };
}

/** The footer's data-status line: as of the day the data was fetched, with a note when it came from the cache or the network failed. */
export function dataStatusMessage(fetchedAt: string, fromCache: boolean, stale: boolean | undefined): string {
    return stale
        ? `Data as of ${fetchedAt.slice(0, 10)} (offline copy — refresh failed)`
        : `Data as of ${fetchedAt.slice(0, 10)}${fromCache ? ' (cached)' : ''}`;
}
