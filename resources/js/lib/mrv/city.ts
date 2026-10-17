/**
 * The City Explorer's rules (public_html/js/city.js), ported in port phase 6: the Daily Temperatures date-range state and
 * its shareable address, the control states, the year-over-year overlay, the statistics cards, the observed-snow cards
 * and the thirteen chart configurations. The page keeps one `CityState` (production keeps the same values in module
 * variables); every change is a function from one state to the next, so a golden test can drive the legacy script and
 * the port through the same steps and compare what each shows (city.test.tsx).
 */
import type { ChartConfiguration } from 'chart.js';
import { fetchJson, type FetchLike, MRV_API_BASE } from './api';
import { CATEGORICAL, NEUTRAL_COMPARE, PALETTE } from './chart-theme';
import { type StatCardData } from './earthquakes';
import {
    type ApiRow,
    computeAnnualSummary,
    computeConditionFrequency,
    computeMonthlyNormals,
    computeSkyGroups,
    computeSnowNormals,
    computeSolarNormals,
    computeWindRose,
    computeYearlyAverages,
    dateParts,
    type DayRow,
    getDateRange,
    getYearsPresent,
    MONTH_NAMES,
} from './stats';

type Cfg = ChartConfiguration<any, any, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export interface DateRange {
    start: string;
    end: string;
}
export interface Bounds {
    min: string;
    max: string;
}

export type ViewMode = 'year' | 'month' | 'season' | 'range';
export type OverlayMetric = 'both' | 'high' | 'low';

export const MAX_OVERLAY_YEARS = 6;

export const SEASONS = [
    { key: 'winter', label: 'Winter' },
    { key: 'spring', label: 'Spring' },
    { key: 'summer', label: 'Summer' },
    { key: 'fall', label: 'Fall' },
];
const SEASON_ORDER = SEASONS.map((s) => s.key);

export const DAILY_PRESETS = [
    { key: '30d', label: 'Past 30 Days' },
    { key: '90d', label: 'Past 90 Days' },
    { key: '180d', label: 'Past 180 Days' },
    { key: '1y', label: 'Past Year' },
    { key: 'thisYear', label: 'This Year' },
    { key: 'all', label: 'All Data' },
];

export const OVERLAY_METRICS: Array<{ key: string; label: string }> = [
    { key: 'both', label: 'High & Low' },
    { key: 'high', label: 'High Only' },
    { key: 'low', label: 'Low Only' },
];

export const MONTH_BUTTONS = MONTH_NAMES.map((name, i) => ({ key: String(i + 1), label: name }));
export const ZOOM_BUTTONS = [{ key: '', label: 'Full Year' }, ...MONTH_BUTTONS];

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
const MONTH_RANGES: Array<[number, number]> = Array.from({ length: 12 }, (_, m) => {
    const mm = String(m + 1).padStart(2, '0');
    const first = MMDD_LABELS.findIndex((l) => l.startsWith(`${mm}-`));
    let last = first;
    for (let i = first; i < MMDD_LABELS.length && MMDD_LABELS[i].startsWith(`${mm}-`); i++) last = i;
    return [first, last + 1];
});

// ---- dates --------------------------------------------------------------------------------------------------------------

export function isoDate(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function addDays(dateStr: string, delta: number): string {
    const d = new Date(`${dateStr}T00:00:00`);
    d.setDate(d.getDate() + delta);
    return isoDate(d);
}

export function shiftYearStr(dateStr: string, delta: number): string {
    const [y, m, d] = dateStr.split('-').map(Number);
    return `${y + delta}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

export function clampDate(dateStr: string, min: string, max: string): string {
    if (dateStr < min) return min;
    if (dateStr > max) return max;
    return dateStr;
}

export function lastDayOfMonth(year: number, month: number): number {
    return new Date(year, month, 0).getDate();
}

const clampRange = (range: DateRange, bounds: Bounds): DateRange => ({
    start: clampDate(range.start, bounds.min, bounds.max),
    end: clampDate(range.end, bounds.min, bounds.max),
});

export function rangesEqual(a: DateRange | null, b: DateRange | null): boolean {
    return !!a && !!b && a.start === b.start && a.end === b.end;
}

export function rangeOverlapsBounds(range: DateRange, bounds: Bounds): boolean {
    return range.start <= bounds.max && range.end >= bounds.min;
}

export function computePresetRange(key: string, bounds: Bounds): DateRange {
    const max = bounds.max;
    switch (key) {
        case '30d':
            return { start: clampDate(addDays(max, -29), bounds.min, max), end: max };
        case '90d':
            return { start: clampDate(addDays(max, -89), bounds.min, max), end: max };
        case '180d':
            return { start: clampDate(addDays(max, -179), bounds.min, max), end: max };
        case '1y':
            return { start: clampDate(addDays(max, -364), bounds.min, max), end: max };
        case 'thisYear':
            return { start: clampDate(`${max.slice(0, 4)}-01-01`, bounds.min, max), end: max };
        default:
            return { start: bounds.min, end: bounds.max };
    }
}

// Meteorological seasons. Winter is keyed by the year its Jan/Feb portion falls in ("Winter 2026" = Dec 2025 + Jan-Feb 2026).
export function seasonBounds(year: number, season: string): DateRange {
    const feb = String(lastDayOfMonth(year, 2)).padStart(2, '0');
    switch (season) {
        case 'winter':
            return { start: `${year - 1}-12-01`, end: `${year}-02-${feb}` };
        case 'spring':
            return { start: `${year}-03-01`, end: `${year}-05-31` };
        case 'summer':
            return { start: `${year}-06-01`, end: `${year}-08-31` };
        case 'fall':
            return { start: `${year}-09-01`, end: `${year}-11-30` };
        default:
            return null as unknown as DateRange;
    }
}

export function fullYearLabels(year: number): string[] {
    const labels: string[] = [];
    const d = new Date(year, 0, 1);
    while (d.getFullYear() === year) {
        labels.push(isoDate(d));
        d.setDate(d.getDate() + 1);
    }
    return labels;
}

export function fullMonthLabels(year: number, month: number): string[] {
    const days = lastDayOfMonth(year, month);
    const mm = String(month).padStart(2, '0');
    return Array.from({ length: days }, (_, i) => `${year}-${mm}-${String(i + 1).padStart(2, '0')}`);
}

export function fullSeasonLabels(year: number, season: string): string[] {
    const { start, end } = seasonBounds(year, season);
    const labels: string[] = [];
    const d = new Date(`${start}T00:00:00`);
    const endD = new Date(`${end}T00:00:00`);
    while (d <= endD) {
        labels.push(isoDate(d));
        d.setDate(d.getDate() + 1);
    }
    return labels;
}

/** Says where a padded axis outruns the data, so a partial period is labeled as partial. */
export function describeGap(labels: string[], hasDate: (label: string) => boolean): string {
    if (!labels.length) return '';
    let leading = 0;
    while (leading < labels.length && !hasDate(labels[leading])) leading++;
    let trailing = 0;
    while (trailing < labels.length && !hasDate(labels[labels.length - 1 - trailing])) trailing++;
    if (!leading && !trailing) return '';
    const parts: string[] = [];
    if (leading) parts.push(`data begins ${labels[leading]}`);
    if (trailing) parts.push(`data available through ${labels[labels.length - 1 - trailing]}`);
    return `Partial — ${parts.join('; ')}.`;
}

// ---- the page's state ------------------------------------------------------------------------------------------------------

export interface CityState {
    /** The Daily Temperatures view: which kind of period, which one, and the clamped range it covers (null before the first city renders). */
    mode: ViewMode;
    year: number | null;
    month: number | null;
    season: string | null;
    range: DateRange | null;
    avg: boolean;
    compare: boolean;
    overlayYears: number[];
    metric: OverlayMetric;
    zoom: number | null;
    /** The `range` token from the address, consumed by the first render. */
    pendingToken: string | null;
    /** `html[data-season]`: set by a season choice, never stored, never cleared. */
    theme: string | null;
    /** Production re-creates a chart on every change, even to the same value (the legend toggles reset); these count those. */
    dailyTick: number;
    overlayTick: number;
}

/** What the address says before the first render (production reads it in the page's start-up). */
export function stateFromSearch(search: string): CityState {
    const params = new URLSearchParams(search);
    const overlayYears: number[] = [];
    const yearsParam = params.get('years');
    if (yearsParam)
        yearsParam
            .split(',')
            .map(Number)
            .filter((y) => Number.isFinite(y))
            .forEach((y) => {
                if (!overlayYears.includes(y)) overlayYears.push(y);
            });
    const metricParam = params.get('metric');
    const zoomParam = Number(params.get('zoom'));

    return {
        mode: 'year',
        year: null,
        month: null,
        season: null,
        range: null,
        avg: params.get('avg') === '7',
        compare: params.get('compare') === '1',
        overlayYears,
        metric: metricParam === 'high' || metricParam === 'low' ? metricParam : 'both',
        zoom: zoomParam >= 1 && zoomParam <= 12 ? zoomParam : null,
        pendingToken: params.get('range'),
        theme: null,
        dailyTick: 0,
        overlayTick: 0,
    };
}

export function boundsOf(rows: Array<{ date: string }>): Bounds {
    const dr = getDateRange(rows) as { first: string; last: string };

    return { min: dr.first, max: dr.last };
}

// A single compact `range` token carries the whole Daily Temperatures view: a preset key (30d 90d 180d 1y all), a year (2025),
// a year and month (2025-07), a year and season (2025-winter) or an explicit range (2025-06-01_2025-08-15). "This Year" is not a
// token of its own: it round-trips as a plain year, which is exactly what it is.
export function encodeRangeToken(state: CityState, bounds: Bounds): string {
    if (state.mode === 'year') return String(state.year);
    if (state.mode === 'month') return `${state.year}-${String(state.month).padStart(2, '0')}`;
    if (state.mode === 'season') return `${state.year}-${state.season}`;
    for (const p of DAILY_PRESETS) {
        if (p.key === 'thisYear') continue;
        if (rangesEqual(computePresetRange(p.key, bounds), state.range)) return p.key;
    }
    const range = state.range as DateRange;

    return `${range.start}_${range.end}`;
}

interface Decoded {
    mode: ViewMode;
    year?: number;
    month?: number;
    season?: string;
    range: DateRange;
}

export function decodeRangeToken(token: string | null, bounds: Bounds): Decoded | null {
    if (!token) return null;
    if (['30d', '90d', '180d', '1y', 'all'].includes(token)) return { mode: 'range', range: computePresetRange(token, bounds) };
    let m: RegExpMatchArray | null;
    if ((m = token.match(/^(\d{4})$/))) {
        const y = Number(m[1]);

        return { mode: 'year', year: y, range: clampRange({ start: `${y}-01-01`, end: `${y}-12-31` }, bounds) };
    }
    if ((m = token.match(/^(\d{4})-(\d{2})$/))) {
        const y = Number(m[1]);
        const mo = Number(m[2]);
        if (mo >= 1 && mo <= 12) {
            const mm = String(mo).padStart(2, '0');

            return {
                mode: 'month',
                year: y,
                month: mo,
                range: clampRange({ start: `${y}-${mm}-01`, end: `${y}-${mm}-${String(lastDayOfMonth(y, mo)).padStart(2, '0')}` }, bounds),
            };
        }
    }
    if ((m = token.match(/^(\d{4})-(winter|spring|summer|fall)$/))) {
        const y = Number(m[1]);
        const season = m[2];

        return { mode: 'season', year: y, season, range: clampRange(seasonBounds(y, season), bounds) };
    }
    if ((m = token.match(/^(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})$/))) {
        return { mode: 'range', range: clampRange({ start: m[1], end: m[2] }, bounds) };
    }

    return null;
}

/** The address query for the page's current state (production: syncURL), keeping every parameter it does not own. */
export function buildSearch(currentSearch: string, city: string, state: CityState, bounds: Bounds): string {
    const params = new URLSearchParams(currentSearch);
    params.set('city', city);
    if (state.range) params.set('range', encodeRangeToken(state, bounds));
    else params.delete('range');
    const years = [...state.overlayYears].sort((a, b) => a - b);
    if (years.length) params.set('years', years.join(','));
    else params.delete('years');
    if (state.metric !== 'both') params.set('metric', state.metric);
    else params.delete('metric');
    if (state.zoom) params.set('zoom', String(state.zoom));
    else params.delete('zoom');
    if (state.avg) params.set('avg', '7');
    else params.delete('avg');
    if (state.compare) params.set('compare', '1');
    else params.delete('compare');

    return params.toString();
}

/**
 * What a render of a city does to the state (production: renderDailyControls and renderOverlayControls): the address's `range` is
 * taken once; with no range yet the view is "This Year"; a range already set stays and is clamped to this city's data; years in
 * the overlay that this city lacks are dropped, and with none left the last three are chosen.
 */
export function enterCity(state: CityState, rows: DayRow[]): CityState {
    const bounds = boundsOf(rows);
    let next = { ...state };
    if (next.pendingToken) {
        const decoded = decodeRangeToken(next.pendingToken, bounds);
        next.pendingToken = null;
        if (decoded) {
            next.mode = decoded.mode;
            next.year = decoded.year ?? null;
            next.month = decoded.month ?? null;
            next.season = decoded.season ?? null;
            next.range = decoded.range;
            if (next.mode === 'season') next.theme = next.season;
        }
    }
    if (!next.range) {
        next.range = computePresetRange('thisYear', bounds);
        next.mode = 'year';
        next.year = Number(bounds.max.slice(0, 4));
    } else {
        next.range = clampRange(next.range, bounds);
    }
    const years = getYearsPresent(rows);
    next.overlayYears = next.overlayYears.filter((y) => years.includes(y));
    if (next.overlayYears.length === 0) next.overlayYears = years.slice(-3);
    next.dailyTick += 1;
    next.overlayTick += 1;
    next = { ...next };

    return next;
}

interface StepTarget {
    year: number | null;
    month: number | null;
    season: string | null;
    range: DateRange;
}

/** What the previous (-1) or next (1) step would show, without changing anything. */
export function computeStepTarget(state: CityState, direction: number): StepTarget {
    if (state.mode === 'year') {
        const y = (state.year as number) + direction;

        return { year: y, month: null, season: null, range: { start: `${y}-01-01`, end: `${y}-12-31` } };
    }
    if (state.mode === 'month') {
        let m = (state.month as number) + direction;
        let y = state.year as number;
        if (m < 1) {
            m = 12;
            y -= 1;
        } else if (m > 12) {
            m = 1;
            y += 1;
        }
        const mm = String(m).padStart(2, '0');

        return {
            year: y,
            month: m,
            season: null,
            range: { start: `${y}-${mm}-01`, end: `${y}-${mm}-${String(lastDayOfMonth(y, m)).padStart(2, '0')}` },
        };
    }
    if (state.mode === 'season') {
        let idx = SEASON_ORDER.indexOf(state.season as string) + direction;
        let y = state.year as number;
        if (idx < 0) {
            idx = 3;
            y -= 1;
        } else if (idx > 3) {
            idx = 0;
            y += 1;
        }

        return { year: y, month: null, season: SEASON_ORDER[idx], range: seasonBounds(y, SEASON_ORDER[idx]) };
    }
    const range = state.range as DateRange;
    const lenDays = Math.round((new Date(`${range.end}T00:00:00`).getTime() - new Date(`${range.start}T00:00:00`).getTime()) / 86400000) + 1;

    return {
        year: null,
        month: null,
        season: null,
        range: { start: addDays(range.start, direction * lenDays), end: addDays(range.end, direction * lenDays) },
    };
}

const withRange = (state: CityState, range: DateRange): CityState => ({ ...state, range, dailyTick: state.dailyTick + 1 });

const contextYear = (state: CityState): number => Number((state.range as DateRange).end.slice(0, 4));

export function chooseDailyPreset(state: CityState, key: string, bounds: Bounds): CityState {
    // "This Year" is a year view like the year chips (padded to Dec 31); every other preset is an exact range, unpadded.
    const next =
        key === 'thisYear' ? { ...state, mode: 'year' as const, year: Number(bounds.max.slice(0, 4)) } : { ...state, mode: 'range' as const };

    return withRange(next, computePresetRange(key, bounds));
}

export function chooseDailySeason(state: CityState, season: string, bounds: Bounds): CityState {
    const year = contextYear(state);

    return withRange({ ...state, mode: 'season', year, season, theme: season }, clampRange(seasonBounds(year, season), bounds));
}

export function chooseDailyYear(state: CityState, y: number, bounds: Bounds): CityState {
    return withRange({ ...state, mode: 'year', year: y }, clampRange({ start: `${y}-01-01`, end: `${y}-12-31` }, bounds));
}

export function chooseDailyMonth(state: CityState, m: number, bounds: Bounds): CityState {
    const year = contextYear(state);
    const mm = String(m).padStart(2, '0');

    return withRange(
        { ...state, mode: 'month', year, month: m },
        clampRange({ start: `${year}-${mm}-01`, end: `${year}-${mm}-${String(lastDayOfMonth(year, m)).padStart(2, '0')}` }, bounds),
    );
}

export function stepDaily(state: CityState, direction: number, bounds: Bounds): CityState {
    const target = computeStepTarget(state, direction);
    let next = state;
    if (state.mode === 'year' || state.mode === 'month' || state.mode === 'season') {
        next = { ...state, year: target.year, month: target.month, season: target.season };
        if (state.mode === 'season') next.theme = target.season;
    }

    return withRange(next, clampRange(target.range, bounds));
}

/** The picker's two chosen dates (production: commitPickerSelection); an unchanged range in range view does nothing. */
export function commitPickerRange(state: CityState, start: string, end: string, bounds: Bounds): CityState {
    const range = clampRange({ start, end }, bounds);
    if (rangesEqual(range, state.range) && state.mode === 'range') return state;

    return withRange({ ...state, mode: 'range' }, range);
}

export function toggleDailyOption(state: CityState, option: 'avg' | 'compare'): CityState {
    return { ...(option === 'avg' ? { ...state, avg: !state.avg } : { ...state, compare: !state.compare }), dailyTick: state.dailyTick + 1 };
}

export function toggleOverlayYear(state: CityState, year: number): CityState {
    let years = state.overlayYears;
    if (years.includes(year)) {
        if (years.length > 1) years = years.filter((y) => y !== year);
    } else if (years.length < MAX_OVERLAY_YEARS) {
        years = [...years, year];
    }

    return { ...state, overlayYears: years, overlayTick: state.overlayTick + 1 };
}

export const setOverlayMetric = (state: CityState, metric: OverlayMetric): CityState => ({ ...state, metric, overlayTick: state.overlayTick + 1 });

export const setOverlayZoom = (state: CityState, month: number | null): CityState => ({ ...state, zoom: month, overlayTick: state.overlayTick + 1 });

// ---- control states ---------------------------------------------------------------------------------------------------------

export interface ButtonState {
    key: string;
    label: string;
    active: boolean;
    disabled: boolean;
}

export interface DailyControls {
    presets: ButtonState[];
    seasons: ButtonState[];
    years: ButtonState[];
    months: ButtonState[];
    options: ButtonState[];
    prevDisabled: boolean;
    nextDisabled: boolean;
}

/** Which buttons are on or off for the current view (production: updateDailyControlStates). */
export function dailyControls(state: CityState, years: number[], bounds: Bounds): DailyControls {
    const range = state.range as DateRange;
    const ctx = contextYear(state);

    return {
        presets: DAILY_PRESETS.map((p) => ({ ...p, active: rangesEqual(computePresetRange(p.key, bounds), range), disabled: false })),
        seasons: SEASONS.map((s) => {
            const { start, end } = seasonBounds(ctx, s.key);
            const hasData = start <= bounds.max && end >= bounds.min;

            return { ...s, disabled: !hasData, active: hasData && rangesEqual(clampRange({ start, end }, bounds), range) };
        }),
        years: years.map((y) => ({
            key: String(y),
            label: String(y),
            disabled: false,
            active: rangesEqual(clampRange({ start: `${y}-01-01`, end: `${y}-12-31` }, bounds), range),
        })),
        months: MONTH_BUTTONS.map((b) => {
            const m = Number(b.key);
            const mm = String(m).padStart(2, '0');
            const monthStart = `${ctx}-${mm}-01`;
            const monthEnd = `${ctx}-${mm}-${String(lastDayOfMonth(ctx, m)).padStart(2, '0')}`;
            const hasData = monthStart <= bounds.max && monthEnd >= bounds.min;

            return { ...b, disabled: !hasData, active: hasData && rangesEqual(clampRange({ start: monthStart, end: monthEnd }, bounds), range) };
        }),
        options: [
            { key: 'avg', label: '7-Day Avg', active: state.avg, disabled: false },
            { key: 'compare', label: 'Compare to Last Year', active: state.compare, disabled: false },
        ],
        prevDisabled: !rangeOverlapsBounds(computeStepTarget(state, -1).range, bounds),
        nextDisabled: !rangeOverlapsBounds(computeStepTarget(state, 1).range, bounds),
    };
}

// ---- nearest city -----------------------------------------------------------------------------------------------------------

export { findNearestCity } from './garden';

// ---- statistics cards -------------------------------------------------------------------------------------------------------

export function cityStatCards(rows: DayRow[]): StatCardData[] {
    const summary = computeAnnualSummary(rows);
    const r = summary.records;
    const dr = summary.dateRange as { first: string; last: string };

    return [
        { label: 'Data Range', value: `${dr.first.slice(0, 4)}–${dr.last.slice(0, 4)}`, sub: `${summary.totalDays.toLocaleString()} days recorded` },
        { label: 'Avg. Annual High', value: summary.avgAnnualHigh != null ? `${summary.avgAnnualHigh}°F` : '—', sub: '' },
        { label: 'Avg. Annual Low', value: summary.avgAnnualLow != null ? `${summary.avgAnnualLow}°F` : '—', sub: '' },
        { label: 'Avg. Annual Precip', value: summary.avgAnnualPrecip != null ? `${summary.avgAnnualPrecip}"` : '—', sub: '' },
        { label: 'Record High', value: r.hottest ? `${r.hottest.tmax}°F` : '—', sub: r.hottest ? r.hottest.date : '' },
        { label: 'Record Low', value: r.coldest ? `${r.coldest.tmin}°F` : '—', sub: r.coldest ? r.coldest.date : '' },
        { label: 'Most Common Sky', value: summary.mostCommonCondition || '—', sub: '' },
    ];
}

/** "Averages computed across N years of daily records (2017–2026)." */
export function normalsNote(rows: DayRow[]): string {
    const range = boundsOf(rows);
    const elapsedYears = Math.round((new Date(range.max).getTime() - new Date(range.min).getTime()) / (365.25 * 24 * 3600 * 1000));

    return `Averages computed across ${elapsedYears} years of daily records (${range.min.slice(0, 4)}–${range.max.slice(0, 4)}).`;
}

// ---- Daily Temperatures ----------------------------------------------------------------------------------------------------

function movingAverage(rows: DayRow[], field: 'tmax' | 'tmin', windowSize = 7): Map<string, number> {
    const half = Math.floor(windowSize / 2);
    const result = new Map<string, number>();
    for (let i = 0; i < rows.length; i++) {
        let sum = 0;
        let count = 0;
        for (let j = Math.max(0, i - half); j <= Math.min(rows.length - 1, i + half); j++) {
            const v = rows[j][field];
            if (v != null) {
                sum += v;
                count++;
            }
        }
        if (count >= Math.ceil(windowSize / 2)) result.set(rows[i].date, Math.round((sum / count) * 10) / 10);
    }

    return result;
}

export interface YoyDelta {
    avgMaxDiff: number;
    avgMinDiff: number;
    days: number;
}

function computeYoyDelta(labels: string[], fullByDate: Map<string, DayRow>): YoyDelta | null {
    let sumMax = 0;
    let sumMin = 0;
    let count = 0;
    for (const date of labels) {
        const cur = fullByDate.get(date);
        const cmp = fullByDate.get(shiftYearStr(date, -1));
        if (cur?.tmax != null && cmp?.tmax != null && cur?.tmin != null && cmp?.tmin != null) {
            sumMax += cur.tmax - cmp.tmax;
            sumMin += cur.tmin - cmp.tmin;
            count++;
        }
    }

    return count ? { avgMaxDiff: Math.round((sumMax / count) * 10) / 10, avgMinDiff: Math.round((sumMin / count) * 10) / 10, days: count } : null;
}

const withOpacity = (hex: string, alpha: number): string => {
    const n = parseInt(hex.slice(1), 16);

    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
};

export interface DailyChart {
    config: Cfg;
    gapNote: string;
    /** The year-over-year note: nothing, the "no overlap" sentence, or the figures. */
    yoy: null | { kind: 'none' } | { kind: 'delta'; delta: YoyDelta };
}

export function dailyChart(rows: DayRow[], state: CityState): DailyChart {
    const range = state.range as DateRange;
    const rangeRows = rows.filter((r) => r.date >= range.start && r.date <= range.end);
    const byDate = new Map(rangeRows.map((r) => [r.date, r]));
    const fullByDate = new Map(rows.map((r) => [r.date, r]));

    let labels: string[];
    if (state.mode === 'year') labels = fullYearLabels(state.year as number);
    else if (state.mode === 'month') labels = fullMonthLabels(state.year as number, state.month as number);
    else if (state.mode === 'season') labels = fullSeasonLabels(state.year as number, state.season as string);
    else labels = rangeRows.map((r) => r.date);

    const pick = (field: 'tmax' | 'tmin' | 'fmax' | 'fmin') => labels.map((date) => (byDate.has(date) ? (byDate.get(date) as DayRow)[field] : null));
    const rawOpacity = state.avg ? 0.32 : 1;

    const datasets: Array<Record<string, unknown>> = [
        {
            label: 'Max Temp (°F)',
            data: pick('tmax'),
            borderColor: withOpacity(PALETTE.terracotta, rawOpacity),
            backgroundColor: PALETTE.terracotta,
            pointRadius: 0,
            borderWidth: 1.6,
            tension: 0.25,
        },
        {
            label: 'Min Temp (°F)',
            data: pick('tmin'),
            borderColor: withOpacity(PALETTE.sky, rawOpacity),
            backgroundColor: PALETTE.sky,
            pointRadius: 0,
            borderWidth: 1.6,
            tension: 0.25,
        },
        {
            label: 'Feels-Like Max (°F)',
            data: pick('fmax'),
            borderColor: PALETTE.gold,
            backgroundColor: PALETTE.gold,
            pointRadius: 0,
            borderWidth: 1.2,
            borderDash: [4, 3],
            tension: 0.25,
            hidden: true,
        },
        {
            label: 'Feels-Like Min (°F)',
            data: pick('fmin'),
            borderColor: PALETTE.plum,
            backgroundColor: PALETTE.plum,
            pointRadius: 0,
            borderWidth: 1.2,
            borderDash: [4, 3],
            tension: 0.25,
            hidden: true,
        },
    ];

    if (state.avg) {
        const maxAvg = movingAverage(rows, 'tmax');
        const minAvg = movingAverage(rows, 'tmin');
        datasets.push({
            label: 'Max Temp (7-day avg)',
            data: labels.map((d) => maxAvg.get(d) ?? null),
            borderColor: PALETTE.terracotta,
            backgroundColor: PALETTE.terracotta,
            pointRadius: 0,
            borderWidth: 2.6,
            tension: 0.35,
        });
        datasets.push({
            label: 'Min Temp (7-day avg)',
            data: labels.map((d) => minAvg.get(d) ?? null),
            borderColor: PALETTE.sky,
            backgroundColor: PALETTE.sky,
            pointRadius: 0,
            borderWidth: 2.6,
            tension: 0.35,
        });
    }

    if (state.compare) {
        const pickCompare = (field: 'tmax' | 'tmin') =>
            labels.map((date) => {
                const r = fullByDate.get(shiftYearStr(date, -1));

                return r ? r[field] : null;
            });
        datasets.push({
            label: 'Max Temp (Last Year)',
            data: pickCompare('tmax'),
            borderColor: NEUTRAL_COMPARE,
            backgroundColor: NEUTRAL_COMPARE,
            pointRadius: 0,
            borderWidth: 1.4,
            borderDash: [2, 2],
            tension: 0.25,
        });
        datasets.push({
            label: 'Min Temp (Last Year)',
            data: pickCompare('tmin'),
            borderColor: NEUTRAL_COMPARE,
            backgroundColor: NEUTRAL_COMPARE,
            pointRadius: 0,
            borderWidth: 1.4,
            borderDash: [2, 2],
            tension: 0.25,
        });
    }

    const config: Cfg = {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { maxTicksLimit: 12 }, grid: { display: false } },
                y: { title: { display: true, text: '°F' } },
            },
            plugins: { legend: { position: 'top' } },
        },
    };

    let yoy: DailyChart['yoy'] = null;
    if (state.compare) {
        const delta = computeYoyDelta(labels, fullByDate);
        yoy = delta ? { kind: 'delta', delta } : { kind: 'none' };
    }

    return { config, gapNote: state.mode === 'range' ? '' : describeGap(labels, (d) => byDate.has(d)), yoy };
}

// ---- Year-over-Year Comparison ---------------------------------------------------------------------------------------------

export function annualTrendChart(rows: DayRow[]): { config: Cfg; note: string } {
    const yearly = computeYearlyAverages(rows);
    const partialYears = yearly.filter((y) => y.dayCount < 300).map((y) => y.year);

    return {
        config: {
            type: 'line',
            data: {
                labels: yearly.map((y) => y.year),
                datasets: [
                    {
                        label: 'Avg High',
                        data: yearly.map((y) => y.avgTmax),
                        borderColor: PALETTE.terracotta,
                        backgroundColor: PALETTE.terracotta,
                        tension: 0.25,
                        borderWidth: 2,
                    },
                    {
                        label: 'Avg Low',
                        data: yearly.map((y) => y.avgTmin),
                        borderColor: PALETTE.sky,
                        backgroundColor: PALETTE.sky,
                        tension: 0.25,
                        borderWidth: 2,
                    },
                ],
            },
            options: {
                responsive: true,
                scales: { y: { title: { display: true, text: '°F' } }, x: { grid: { display: false } } },
                plugins: { legend: { position: 'top' } },
            },
        },
        note: partialYears.length
            ? `Avg. daily high & low by year. ${partialYears.join(' and ')} ${partialYears.length > 1 ? 'are partial years' : 'is a partial year'} in the data.`
            : 'Avg. daily high & low by year.',
    };
}

function buildYearSeries(rows: DayRow[], year: number, field: 'tmax' | 'tmin'): Array<number | null> {
    const byMMDD = new Map<string, number | null>();
    for (const r of rows) {
        if (dateParts(r.date).year !== year) continue;
        byMMDD.set(r.date.slice(5), r[field]);
    }

    return MMDD_LABELS.map((mmdd) => (byMMDD.has(mmdd) ? (byMMDD.get(mmdd) as number | null) : null));
}

export function overlayChart(rows: DayRow[], state: CityState): { config: Cfg; gapNote: string } {
    const years = [...state.overlayYears].sort((a, b) => a - b);
    const slice = state.zoom ? MONTH_RANGES[state.zoom - 1] : null;
    const labels = slice ? MMDD_LABELS.slice(slice[0], slice[1]) : MMDD_LABELS;
    const sliced = <T>(series: T[]) => (slice ? series.slice(slice[0], slice[1]) : series);
    const datasets: Array<Record<string, unknown>> = [];
    years.forEach((y, i) => {
        const color = CATEGORICAL[i % CATEGORICAL.length];
        if (state.metric === 'high' || state.metric === 'both') {
            datasets.push({
                label: `${y} High`,
                data: sliced(buildYearSeries(rows, y, 'tmax')),
                borderColor: color,
                backgroundColor: color,
                borderWidth: 1.8,
                pointRadius: 0,
                tension: 0.2,
            });
        }
        if (state.metric === 'low' || state.metric === 'both') {
            datasets.push({
                label: `${y} Low`,
                data: sliced(buildYearSeries(rows, y, 'tmin')),
                borderColor: color,
                backgroundColor: color,
                borderWidth: 1.8,
                borderDash: [4, 3],
                pointRadius: 0,
                tension: 0.2,
            });
        }
    });

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

    return {
        config: {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { ticks: { maxTicksLimit: 12 }, grid: { display: false } },
                    y: { title: { display: true, text: '°F' } },
                },
                plugins: { legend: { position: 'top' } },
            },
        },
        gapNote: partialYears.length ? `Partial data in this view for: ${partialYears.join(', ')}.` : '',
    };
}

// ---- Climate Normals & Patterns --------------------------------------------------------------------------------------------

export function normalsChart(rows: DayRow[]): Cfg {
    const normals = computeMonthlyNormals(rows);

    return {
        type: 'line',
        data: {
            labels: normals.map((n) => n.monthName),
            datasets: [
                {
                    label: 'Avg High',
                    data: normals.map((n) => n.avgTmax),
                    borderColor: PALETTE.terracotta,
                    backgroundColor: PALETTE.terracotta,
                    tension: 0.3,
                    borderWidth: 2,
                },
                {
                    label: 'Avg Low',
                    data: normals.map((n) => n.avgTmin),
                    borderColor: PALETTE.sky,
                    backgroundColor: PALETTE.sky,
                    tension: 0.3,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: '°F' } }, x: { grid: { display: false } } },
            plugins: { legend: { position: 'top' } },
        },
    };
}

export function precipChart(rows: DayRow[]): Cfg {
    const normals = computeMonthlyNormals(rows);

    return {
        type: 'bar',
        data: {
            labels: normals.map((n) => n.monthName),
            datasets: [{ label: 'Avg Precip (in)', data: normals.map((n) => n.avgPrecip), backgroundColor: PALETTE.sky, borderRadius: 4 }],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'inches' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: { legend: { display: false } },
        },
    };
}

export function conditionsChart(rows: DayRow[]): Cfg {
    const freq = computeConditionFrequency(rows);

    return {
        type: 'bar',
        data: {
            labels: freq.map((f) => f.wx),
            datasets: [
                {
                    label: '% of Days',
                    data: freq.map((f) => f.pct),
                    backgroundColor: freq.map((_, i) => CATEGORICAL[i % CATEGORICAL.length]),
                    borderRadius: 4,
                },
            ],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            // Fixed 0 to 100 scale (rather than the data's own max) so the longest bar cannot read as "100% of the time".
            scales: { x: { title: { display: true, text: '% of days' }, min: 0, max: 100 } },
            plugins: { legend: { display: false } },
        },
    };
}

export function skyGroupsChart(rows: DayRow[]): Cfg {
    const groups = computeSkyGroups(rows);
    const groupColors: Record<string, string> = {
        'Full Sun': PALETTE.gold,
        'Mostly Sunny': PALETTE.terracotta,
        'Partly Cloudy': PALETTE.teal,
        Overcast: PALETTE.dusk,
        Precipitation: PALETTE.sky,
    };

    return {
        type: 'bar',
        data: {
            labels: groups.map((g) => g.group),
            datasets: [
                { label: '% of Days', data: groups.map((g) => g.pct), backgroundColor: groups.map((g) => groupColors[g.group]), borderRadius: 4 },
            ],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: { x: { min: 0, max: 100, ticks: { callback: (v: unknown) => `${v}%` } } },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx: { parsed: { x: number } }) => `${ctx.parsed.x}% of days` } },
            },
        },
    };
}

export function windChart(rows: DayRow[]): Cfg {
    const normals = computeMonthlyNormals(rows);

    return {
        type: 'bar',
        data: {
            labels: normals.map((n) => n.monthName),
            datasets: [{ label: 'Avg Max Gust (mph)', data: normals.map((n) => n.avgGust), backgroundColor: PALETTE.teal, borderRadius: 4 }],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'mph' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: { legend: { display: false } },
        },
    };
}

// ---- Sun & Solar, Wind & Snow (the enrichment columns) ---------------------------------------------------------------------

export type SolarNormals = ReturnType<typeof computeSolarNormals>;
export type WindRose = ReturnType<typeof computeWindRose>;
export type SnowNormals = ReturnType<typeof computeSnowNormals>;

export function daylightChart(solar: SolarNormals): Cfg {
    return {
        type: 'line',
        data: {
            labels: solar.map((n) => n.monthName),
            datasets: [
                {
                    label: 'Daylight',
                    data: solar.map((n) => n.avgDaylightHours),
                    borderColor: PALETTE.gold,
                    backgroundColor: PALETTE.gold,
                    tension: 0.3,
                    borderWidth: 2,
                },
                {
                    label: 'Sunshine',
                    data: solar.map((n) => n.avgSunshineHours),
                    borderColor: PALETTE.terracotta,
                    backgroundColor: PALETTE.terracotta,
                    tension: 0.3,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'hours/day' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: { legend: { position: 'top' } },
        },
    };
}

export function solarRadiationChart(solar: SolarNormals): Cfg {
    return {
        type: 'line',
        data: {
            labels: solar.map((n) => n.monthName),
            datasets: [
                {
                    label: 'Total',
                    data: solar.map((n) => n.avgSolarRadiation),
                    borderColor: PALETTE.gold,
                    backgroundColor: PALETTE.gold,
                    tension: 0.3,
                    borderWidth: 2,
                },
                {
                    label: 'Direct',
                    data: solar.map((n) => n.avgDirectRadiation),
                    borderColor: PALETTE.terracotta,
                    backgroundColor: PALETTE.terracotta,
                    tension: 0.3,
                    borderWidth: 2,
                },
                {
                    label: 'Diffuse',
                    data: solar.map((n) => n.avgDiffuseRadiation),
                    borderColor: PALETTE.sky,
                    backgroundColor: PALETTE.sky,
                    tension: 0.3,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'MJ/m²' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: { legend: { position: 'top' } },
        },
    };
}

export function windRoseChart(windRose: WindRose): Cfg {
    // Sequential single hue (frequency is a magnitude, not an identity): a darker slice is a more common wind direction.
    // One series, so it is labeled in the tooltip rather than a legend.
    const maxPct = Math.max(...windRose.map((b) => b.pct as number), 1);
    const colors = windRose.map((b) => {
        const t = (b.pct as number) / maxPct;
        const l = Math.round(85 - t * 45); // lightness 85% (low) to 40% (high)

        return `hsl(203, 45%, ${l}%)`; // sky hue
    });

    return {
        type: 'polarArea',
        data: {
            labels: windRose.map((b) => b.label),
            datasets: [{ data: windRose.map((b) => b.pct), backgroundColor: colors, borderColor: '#2E2A26', borderWidth: 1 }],
        },
        options: {
            responsive: true,
            scales: { r: { ticks: { backdropColor: 'transparent', callback: (v: unknown) => `${v}%` } } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx: { dataIndex: number }) => {
                            const b = windRose[ctx.dataIndex];

                            return `${b.label}: ${b.pct}% of days${b.avgSpeed != null ? `, avg ${b.avgSpeed} mph` : ''}`;
                        },
                    },
                },
            },
        },
    };
}

// ---- observed snow (Big Bear City) -----------------------------------------------------------------------------------------

export const OBSERVED_SNOW_CITY = 'Big Bear City';
export const OBSERVED_SNOW_URL = `${MRV_API_BASE}/snow_summary.php?from_year=2017`;
export const MODELED_SNOW_NOTE = 'Average modeled snow depth by month — meaningful mainly for higher-elevation communities like Big Bear City.';
export const OBSERVED_SNOW_NOTE =
    'Average snow depth by month, modeled (Open-Meteo) beside what the Big Bear Lake station actually measured (average over days with a reading), 2017 onward.';

export interface ObservedSnow {
    seasons: Array<{
        season: string;
        snowfall_in: number;
        complete: boolean;
        in_progress?: boolean;
        days_reported: number;
        days_expected: number;
    }>;
    monthly_depth: Array<{ avg_depth_in: number | null }>;
    records: { snowfall_in_a_day: { inches: number; date: string }; snow_depth_in: { inches: number; date: string } };
}

export function snowChart(snowNormals: SnowNormals, observed: ObservedSnow | null = null): Cfg {
    const datasets: Array<Record<string, unknown>> = [
        {
            label: observed ? 'Modeled (Open-Meteo)' : 'Avg Snow Depth (in)',
            data: snowNormals.map((n) => n.avgSnowDepth),
            backgroundColor: PALETTE.sky,
            borderRadius: 4,
        },
    ];
    if (observed) {
        // Both arrays run January to December, matching the normals' own month order.
        datasets.push({
            label: 'Observed (Big Bear Lake station)',
            data: observed.monthly_depth.map((m) => m.avg_depth_in),
            backgroundColor: PALETTE.terracotta,
            borderRadius: 4,
        });
    }

    return {
        type: 'bar',
        data: { labels: snowNormals.map((n) => n.monthName), datasets },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'inches' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: { legend: { display: !!observed } },
        },
    };
}

const fmtDay = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

export function observedSnowCards(observed: ObservedSnow): StatCardData[] {
    const seasons = observed.seasons;
    const complete = seasons.filter((s) => s.complete);
    const snowiest = complete.reduce((a, b) => (b.snowfall_in > a.snowfall_in ? b : a));
    const avg = complete.reduce((sum, s) => sum + s.snowfall_in, 0) / complete.length;
    const day = observed.records.snowfall_in_a_day;
    const depth = observed.records.snow_depth_in;

    return [
        {
            label: 'Snowiest Winter',
            value: `${snowiest.snowfall_in.toFixed(0)} in`,
            sub: `${snowiest.season}, of ${complete.length} winters with a full record`,
        },
        { label: 'Average Winter', value: `${avg.toFixed(0)} in`, sub: `of snowfall, across those ${complete.length} winters` },
        { label: 'Most in One Day', value: `${day.inches.toFixed(1)} in`, sub: fmtDay(day.date) },
        { label: 'Deepest Snow on the Ground', value: `${depth.inches.toFixed(0)} in`, sub: fmtDay(depth.date) },
    ];
}

export function snowSeasonsChart(observed: ObservedSnow): Cfg {
    const seasons = observed.seasons;
    const solid = PALETTE.sky;
    const light = `${PALETTE.sky}66`; // the same blue at 40% opacity (an 8-digit hex color)

    return {
        type: 'bar',
        data: {
            labels: seasons.map((s) => s.season),
            datasets: [
                {
                    label: 'Snowfall (in)',
                    data: seasons.map((s) => s.snowfall_in),
                    backgroundColor: seasons.map((s) => (s.complete ? solid : light)),
                    borderRadius: 2,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { title: { display: true, text: 'inches of snowfall' }, beginAtZero: true },
                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items: Array<{ label: string }>) => `Winter ${items[0].label}`,
                        label: (ctx: { parsed: { y: number } }) => `${ctx.parsed.y} in of snowfall`,
                        afterLabel: (ctx: { dataIndex: number }) => {
                            const s = seasons[ctx.dataIndex];
                            const reported = `${s.days_reported} of ${s.days_expected} days reported`;

                            return s.in_progress ? `Still in progress; ${reported}` : s.complete ? reported : `Incomplete record; ${reported}`;
                        },
                    },
                },
            },
        },
    };
}

export const ENRICHMENT_LOADING = { sun: 'Loading additional sun/solar/wind data…', wind: 'Loading additional wind/snow data…' };
export const ENRICHMENT_FAILED = "Couldn't load this section's data right now.";

/** The geolocation note under the selector. */
export const geoNote = (name: string, distanceMiles: number): string =>
    `Showing ${name} based on your location (~${Math.round(distanceMiles)} mi away).`;

export type { ApiRow, DayRow };

let observedSnowPromise: Promise<ObservedSnow> | null = null;

/** The observed snow data, fetched once per visit; a failure is forgotten so a later visit to the city retries. */
export function loadObservedSnow(fetchImpl?: FetchLike): Promise<ObservedSnow> {
    if (!observedSnowPromise) {
        observedSnowPromise = fetchJson<ObservedSnow>(OBSERVED_SNOW_URL, fetchImpl, (status) => `HTTP ${status}`)
            .then((data) => {
                if (!data.seasons || !data.seasons.length) throw new Error('no observed snow data');

                return data;
            })
            .catch((err) => {
                observedSnowPromise = null;
                throw err;
            });
    }

    return observedSnowPromise;
}

/** For tests: forget the fetched observed snow data. */
export function resetObservedSnow(): void {
    observedSnowPromise = null;
}
