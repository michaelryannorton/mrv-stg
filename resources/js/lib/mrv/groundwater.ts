/**
 * The Groundwater page's rules (public_html/js/groundwater.js), ported in port phase 4: the falling, steady and rising
 * status scale, the two aquifer areas, the summary statistics, the change wording, which wells the table ranks, which
 * well the page opens on, and the chart of one well's record. Depth is feet below land surface, so a bigger number is a
 * lower water table. Golden tests run the legacy script over the saved summary and wells (groundwater.test.tsx).
 */
import type { ChartConfiguration } from 'chart.js';
import { fetchJson, MRV_API_BASE, type FetchLike } from './api';
import { type StatCardData } from './earthquakes';
import { escapeHtml } from './html';

export type WellStatus = 'falling' | 'steady' | 'rising' | 'unknown';

export interface Well {
    site_id: string;
    name: string;
    latitude: number;
    longitude: number;
    area: string;
    status: WellStatus;
    change_ft: number | null;
    last_depth_ft: number | null;
    last_date: string | null;
    compare_depth_ft: number | null;
    compare_date: string | null;
    first_date: string | null;
    readings_used: number;
    readings_total: number;
    altitude_ft: number | null;
    well_depth_ft: number | null;
}

export interface Summary {
    wells: Well[];
    totals: { newest_reading: string };
}

export interface Reading {
    reading_date: string;
    depth_ft: number | string | null;
    qualifiers: string | null;
    approval?: string | null;
    method?: string | null;
}

export const STATUS: Record<WellStatus, { label: string; note: string; color: string; hollow: boolean }> = {
    falling: { label: 'Water level falling', note: 'more than 3 ft deeper than about ten years earlier', color: '#B5472E', hollow: false },
    steady: { label: 'Steady', note: 'within 3 ft of about ten years earlier', color: '#8C8A82', hollow: false },
    rising: { label: 'Water level rising', note: 'more than 3 ft shallower than about ten years earlier', color: '#2A6FB0', hollow: false },
    unknown: { label: 'No ten-year comparison', note: 'not enough readings ten years apart', color: '#8C8A82', hollow: true },
};
export const STATUS_ORDER: WellStatus[] = ['falling', 'steady', 'rising', 'unknown'];
export const INK = '#55504A';

// Wells over the desert basins and wells in the Santa Ana River basin south of Cajon Pass sit on different aquifer systems.
export const AREAS: Record<string, string> = { high_desert: 'High Desert', san_bernardino_valley: 'San Bernardino Valley', all: 'All wells' };
export const DEFAULT_AREA = 'high_desert';

export const fmtDate = (iso: string): string =>
    new Date(`${iso}T12:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
export const fmtFt = (v: number | string, places = 1): string =>
    `${Number(v).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places })} ft`;
/** A negative depth means the water stood above the ground (a flowing well). */
export const fmtDepth = (v: number | string): string => (Number(v) < 0 ? `${fmtFt(Math.abs(Number(v)))} above ground` : fmtFt(v));
export function decimalYear(iso: string): number {
    const y = Number(iso.slice(0, 4));
    const start = Date.UTC(y, 0, 1);
    return y + (Date.parse(`${iso}T12:00:00Z`) - start) / (365.25 * 86400000);
}

export const visibleWells = (wells: Well[], area: string): Well[] => (area === 'all' ? wells : wells.filter((w) => w.area === area));

export function median(values: number[]): number | null {
    if (!values.length) return null;
    const v = [...values].sort((a, b) => a - b);
    const n = v.length;
    return n % 2 ? v[(n - 1) / 2] : Math.round(((v[n / 2 - 1] + v[n / 2]) / 2) * 10) / 10;
}

/** "12.0 ft deeper" / "3.2 ft shallower" / "no change". */
export function describeChange(ft: number | null): string {
    if (ft == null) return 'no ten-year comparison';
    if (Math.abs(ft) < 0.05) return 'no change';
    return `${fmtFt(Math.abs(ft))} ${ft > 0 ? 'deeper' : 'shallower'}`;
}

export function gwStats(wells: Well[], area: string, totals: Summary['totals']): { cards: StatCardData[]; note: string } {
    const ws = visibleWells(wells, area);
    const count = (st: WellStatus) => ws.filter((w) => w.status === st).length;
    const compared = ws.filter((w) => w.change_ft != null);
    const med = median(compared.map((w) => w.change_ft as number));
    const readings = ws.reduce((sum, w) => sum + w.readings_total, 0);
    const cards = [
        {
            label: 'Wells Tracked',
            value: ws.length.toLocaleString(),
            sub: `${readings.toLocaleString()} readings, the latest on ${fmtDate(totals.newest_reading)}`,
        },
        {
            label: 'Water Level Falling',
            value: `${count('falling')} of ${compared.length}`,
            sub: 'wells with a ten-year comparison are more than 3 ft deeper now',
        },
        {
            label: 'Water Level Rising',
            value: `${count('rising')} of ${compared.length}`,
            sub: `are more than 3 ft shallower now; ${count('steady')} are steady`,
        },
        {
            label: 'Typical Change',
            value: med == null ? '—' : describeChange(med),
            sub: `median across the ${compared.length} wells with a ten-year comparison`,
        },
    ];
    const where = area === 'all' ? 'within 35 miles of Victorville' : `in the ${AREAS[area]} area, within 35 miles of Victorville`;
    return {
        cards,
        note: `${ws.length} wells ${where}, measured by USGS and partners. ${count('unknown')} do not yet have two usable readings about ten years apart.`,
    };
}

export function areaCounts(wells: Well[]): Record<string, number> {
    const counts: Record<string, number> = { all: wells.length };
    for (const w of wells) counts[w.area] = (counts[w.area] || 0) + 1;
    return counts;
}

export function statusCounts(wells: Well[], area: string): Record<string, number> {
    const counts: Record<string, number> = {};
    for (const w of visibleWells(wells, area)) counts[w.status] = (counts[w.status] || 0) + 1;
    return counts;
}

export function markerStyle(w: Well, selected: boolean) {
    const s = STATUS[w.status];
    return {
        radius: selected ? 9 : 6,
        color: selected ? '#2E2A26' : s.hollow ? s.color : '#2E2A26',
        weight: selected ? 3 : s.hollow ? 2 : 1,
        fillColor: s.color,
        fillOpacity: s.hollow ? 0 : 0.85,
    };
}

export const wellTooltip = (w: Well): string =>
    `${escapeHtml(w.name)} · ${STATUS[w.status].label}${w.change_ft != null ? ` (${describeChange(w.change_ft)})` : ''}`;

/** The wells on the map: the area's wells whose status is switched on. */
export const drawnWells = (wells: Well[], area: string, active: Set<WellStatus>): Well[] =>
    visibleWells(wells, area).filter((w) => active.has(w.status));

export const mapNote = (shown: number, total: number): string => `Showing ${shown} of ${total} wells. Select a well to see its record.`;

/** The well the page opens on: the one with a ten-year comparison and the longest usable record, else the first. */
export function startWell(ws: Well[]): Well | undefined {
    return ws.filter((w) => w.change_ft != null).sort((a, b) => b.readings_used - a.readings_used)[0] || ws[0];
}

export function wellFacts(w: Well): StatCardData[] {
    return [
        {
            label: 'Latest Depth to Water',
            value: w.last_depth_ft != null ? fmtDepth(w.last_depth_ft) : '—',
            sub: w.last_date ? `measured ${fmtDate(w.last_date)}` : 'no usable readings',
        },
        {
            label: 'Ten-Year Change',
            value: w.change_ft != null ? describeChange(w.change_ft) : '—',
            sub: w.compare_date
                ? `from ${fmtDepth(w.compare_depth_ft as number)} on ${fmtDate(w.compare_date)}`
                : 'no reading about ten years before the latest',
        },
        {
            label: 'Readings Used',
            value: w.readings_used.toLocaleString(),
            sub: w.first_date
                ? `since ${fmtDate(w.first_date)}${w.readings_total > w.readings_used ? `; ${w.readings_total - w.readings_used} more not comparable` : ''}`
                : '',
        },
        {
            label: 'Land Surface',
            value: w.altitude_ft != null ? `${Math.round(w.altitude_ft).toLocaleString()} ft` : '—',
            sub: w.well_depth_ft != null ? `well drilled to ${Math.round(w.well_depth_ft).toLocaleString()} ft` : 'well depth not recorded',
        },
    ];
}

export const wellSub = (w: Well): string =>
    `${STATUS[w.status].label}${w.change_ft != null ? ` · ${describeChange(w.change_ft)} than about ten years earlier` : ''}`;

/** The ten wells whose level changed most. */
export const rankedWells = (wells: Well[], area: string): Well[] =>
    visibleWells(wells, area)
        .filter((w) => w.change_ft != null)
        .sort((a, b) => Math.abs(b.change_ft as number) - Math.abs(a.change_ft as number))
        .slice(0, 10);

export const fetchSummary = (fetchImpl?: FetchLike): Promise<Summary> =>
    fetchJson<Summary>(`${MRV_API_BASE}/groundwater_summary.php`, fetchImpl, (status) => `HTTP ${status}`);

export const fetchReadings = (siteId: string, fetchImpl?: FetchLike): Promise<Reading[]> =>
    fetchJson<Reading[]>(
        `${MRV_API_BASE}/query.php?table=groundwater_levels&site_id=${encodeURIComponent(siteId)}&columns=depth_ft,qualifiers,approval,method&limit=5000`,
        fetchImpl,
        (status) => `HTTP ${status}`,
    );

interface Point {
    x: number;
    y: number;
    date: string;
    q: string | null;
    approval: string | null | undefined;
    method: string | null | undefined;
}

/** The chart of one well, and the sentence under it. `config` is null when there is nothing to plot (the chart is then removed). */
export function wellChart(w: Well, rows: Reading[]): { config: ChartConfiguration<'line', Point[], unknown> | null; note: string } {
    const isUsable = (r: Reading) => r.qualifiers === '' || r.qualifiers === 'Static';
    const usable = rows.filter((r) => r.depth_ft != null && isUsable(r));
    const other = rows.filter((r) => r.depth_ft != null && !isUsable(r));
    const pt = (r: Reading): Point => ({
        x: decimalYear(r.reading_date),
        y: Number(r.depth_ft),
        date: r.reading_date,
        q: r.qualifiers,
        approval: r.approval,
        method: r.method,
    });

    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- Chart.js dataset options differ by series
    const datasets: any[] = [
        {
            label: 'Static readings (used)',
            data: usable.map(pt),
            borderColor: '#2A6FB0',
            backgroundColor: '#2A6FB0',
            borderWidth: 1.5,
            pointRadius: 2.5,
            pointHoverRadius: 5,
            tension: 0,
            order: 3,
        },
    ];
    if (w.compare_date) {
        const cmp = [
            pt({ reading_date: w.compare_date, depth_ft: w.compare_depth_ft, qualifiers: 'Static' }),
            pt({ reading_date: w.last_date as string, depth_ft: w.last_depth_ft, qualifiers: 'Static' }),
        ];
        datasets.push({
            label: 'Ten-year comparison',
            data: cmp,
            borderColor: '#B5472E',
            backgroundColor: '#FFFDF9',
            borderWidth: 2,
            borderDash: [5, 4],
            pointRadius: 7,
            pointBorderWidth: 3,
            pointBorderColor: '#B5472E',
            pointHoverRadius: 8,
            tension: 0,
            order: 1,
        });
    }
    if (other.length) {
        datasets.push({
            label: 'Not comparable (pumping, recently pumped, other)',
            data: other.map(pt),
            showLine: false,
            borderColor: '#8C8A82',
            backgroundColor: 'transparent',
            pointStyle: 'rectRot',
            pointRadius: 4,
            pointBorderWidth: 1.5,
            order: 2,
        });
    }

    const xs = datasets.flatMap((d) => d.data.map((p: Point) => p.x));
    if (!xs.length) {
        // A well with nothing to plot (no readings loaded, or only dry or blocked visits).
        return {
            config: null,
            note: rows.length
                ? `${rows.length} readings on file, none with a depth to plot (the well was dry or blocked).`
                : 'No readings are on file for this well yet.',
        };
    }
    const config = {
        type: 'line',
        data: { datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: true },
            scales: {
                x: {
                    type: 'linear',
                    min: Math.floor(Math.min(...xs)),
                    max: Math.ceil(Math.max(...xs)),
                    grid: { display: false },
                    ticks: { color: INK, precision: 0, maxTicksLimit: 10, callback: (v: number | string) => String(Math.round(Number(v))) },
                },
                // Reversed so the line runs downward when the water gets deeper, the way the water table would.
                y: {
                    reverse: true,
                    title: { display: true, text: 'Depth to water (feet below ground)', color: INK },
                    ticks: { color: INK },
                    grid: { color: 'rgba(46, 42, 38, 0.08)' },
                },
            },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, color: INK } },
                tooltip: {
                    callbacks: {
                        title: (items: Array<{ raw: Point }>) => fmtDate(items[0].raw.date),
                        label: (item: { raw: Point }) => (item.raw.y < 0 ? fmtDepth(item.raw.y) : `${fmtFt(item.raw.y)} below ground`),
                        afterLabel: (item: { raw: Point }) =>
                            [
                                item.raw.q ? `Condition: ${item.raw.q.split(',').join(', ')}` : null,
                                item.raw.method || null,
                                item.raw.approval ? `${item.raw.approval} data` : null,
                            ].filter(Boolean),
                    },
                },
            },
        },
    } as unknown as ChartConfiguration<'line', Point[], unknown>;

    const noDepth = rows.filter((r) => r.depth_ft == null).length;
    const parts = [`${usable.length} readings shown`];
    if (other.length) parts.push(`${other.length} taken under other conditions, such as pumping (open diamonds, left out of the change)`);
    if (noDepth) parts.push(`${noDepth} with no depth because the well was dry or blocked (not plotted)`);
    return { config, note: `${parts.join('; ')}. The line runs downward when the water gets deeper.` };
}
