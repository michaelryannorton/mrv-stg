/**
 * Mojave River Valley: pure statistics over a city's rows. A typed port of public_html/js/stats.js (port
 * phase 2); the logic is copied, not rewritten, and tests/golden files prove the outputs are identical to the
 * legacy code's for real saved inputs (see stats.test.ts and port/phase-2/tools/make_golden.mjs).
 *
 * Base rows (from WeatherData.getCity): { date: 'YYYY-MM-DD', tmax, tmin, fmax, fmin, precip, gust, wx }.
 * Enrichment and air quality rows use the API's own column names and arrive as strings (MySQL DECIMAL columns
 * come back as strings over JSON), so every value is coerced through num() before use.
 */

export interface DayRow {
    date: string;
    tmax: number | null;
    tmin: number | null;
    fmax: number | null;
    fmin: number | null;
    precip: number | null;
    gust: number | null;
    wx: string | null;
}

/** A row exactly as the API returns it (all columns by name, values mostly strings). */
export type ApiRow = { date: string } & Record<string, unknown>;

const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function dateParts(dateStr: string): { year: number; month: number; day: number } {
    const [y, m, d] = dateStr.split('-').map(Number);
    return { year: y, month: m, day: d };
}

export function round(n: number | null | undefined, decimals = 1): number | null {
    if (n == null || Number.isNaN(n)) return null;
    const f = 10 ** decimals;
    return Math.round(n * f) / f;
}

// `decimals` defaults to 1, which suits °F/mph/percent fields. Small-magnitude fields must pass their own: ET0
// (inches, ~0.01-0.55) and soil moisture (m³/m³, ~0.05-0.35) are destroyed by 1dp, collapsing a whole year into
// two or three distinct values.
export function mean(arr: Array<number | null | undefined>, decimals = 1): number | null {
    const vals = arr.filter((x): x is number => x != null && !Number.isNaN(x));
    if (!vals.length) return null;
    return round(vals.reduce((a, b) => a + b, 0) / vals.length, decimals);
}

export function getDateRange(rows: Array<{ date: string }>): { first: string; last: string } | null {
    if (!rows.length) return null;
    return { first: rows[0].date, last: rows[rows.length - 1].date };
}

export function getYearsPresent(rows: Array<{ date: string }>): number[] {
    const years = new Set(rows.map((r) => dateParts(r.date).year));
    return Array.from(years).sort((a, b) => a - b);
}

export function computeRecords(rows: DayRow[]) {
    let hottest: DayRow | null = null;
    let coldest: DayRow | null = null;
    let wettest: DayRow | null = null;
    let windiest: DayRow | null = null;
    for (const r of rows) {
        if (r.tmax != null && (!hottest || r.tmax > (hottest.tmax as number))) hottest = r;
        if (r.tmin != null && (!coldest || r.tmin < (coldest.tmin as number))) coldest = r;
        if (r.precip != null && (!wettest || r.precip > (wettest.precip as number))) wettest = r;
        if (r.gust != null && (!windiest || r.gust > (windiest.gust as number))) windiest = r;
    }
    return { hottest, coldest, wettest, windiest };
}

export function computeMonthlyNormals(rows: DayRow[]) {
    const buckets = Array.from({ length: 12 }, () => ({
        tmax: [] as number[],
        tmin: [] as number[],
        fmax: [] as number[],
        fmin: [] as number[],
        gust: [] as number[],
        precipByYear: {} as Record<string, number>,
    }));
    for (const r of rows) {
        const { year, month } = dateParts(r.date);
        const b = buckets[month - 1];
        if (r.tmax != null) b.tmax.push(r.tmax);
        if (r.tmin != null) b.tmin.push(r.tmin);
        if (r.fmax != null) b.fmax.push(r.fmax);
        if (r.fmin != null) b.fmin.push(r.fmin);
        if (r.gust != null) b.gust.push(r.gust);
        if (r.precip != null) b.precipByYear[year] = (b.precipByYear[year] || 0) + r.precip;
    }
    return buckets.map((b, i) => {
        const years = Object.keys(b.precipByYear);
        const avgPrecip = years.length ? round(years.reduce((a, y) => a + b.precipByYear[y], 0) / years.length, 2) : null;
        return {
            month: i + 1,
            monthName: MONTH_NAMES[i],
            avgTmax: mean(b.tmax),
            avgTmin: mean(b.tmin),
            avgFmax: mean(b.fmax),
            avgFmin: mean(b.fmin),
            avgGust: mean(b.gust),
            avgPrecip,
        };
    });
}

// Coarse sky-character buckets, useful as an "at a glance" comparison against the much noisier 13-category raw
// breakdown.
export const SKY_GROUP_ORDER = ['Full Sun', 'Mostly Sunny', 'Partly Cloudy', 'Overcast', 'Precipitation'];

function normalizeCondition(wx: unknown): string {
    return String(wx).toLowerCase().replace(/\s+/g, ' ').trim();
}

// Keys are normalized (lowercase, whitespace collapsed) rather than written in the source's exact casing: the first
// version hand-retyped the WMO strings in Title Case while the ingest script stores sentence case, so 61% of days
// fell through to no group at all. Covers all 27 strings in daily_update.php's WMO_CODES table. Fog and rime fog
// group under Overcast: the question this chart answers is "could you see the sun," and an obscured sky is the
// same answer as a covered one.
const SKY_GROUP_MAP: Map<string, string> = (() => {
    const byGroup: Record<string, string[]> = {
        'Full Sun': ['Clear sky'],
        'Mostly Sunny': ['Mainly clear'],
        'Partly Cloudy': ['Partly cloudy'],
        Overcast: ['Overcast', 'Fog', 'Depositing rime fog'],
        Precipitation: [
            'Light drizzle',
            'Moderate drizzle',
            'Dense drizzle',
            'Light freezing drizzle',
            'Dense freezing drizzle',
            'Slight rain',
            'Moderate rain',
            'Heavy rain',
            'Light freezing rain',
            'Heavy freezing rain',
            'Slight snow fall',
            'Moderate snow fall',
            'Heavy snow fall',
            'Snow grains',
            'Slight rain showers',
            'Moderate rain showers',
            'Violent rain showers',
            'Slight snow showers',
            'Heavy snow showers',
            'Thunderstorm',
            'Thunderstorm with slight hail',
            'Thunderstorm with heavy hail',
        ],
    };
    const map = new Map<string, string>();
    for (const [group, conditions] of Object.entries(byGroup)) {
        for (const c of conditions) map.set(normalizeCondition(c), group);
    }
    return map;
})();

// Report each unrecognized condition once rather than per row, so a future WMO string change is visible in the
// console instead of quietly zeroing a category.
const warnedConditions = new Set<string>();

export function computeSkyGroups(rows: DayRow[]) {
    const counts = new Map<string, number>(SKY_GROUP_ORDER.map((g) => [g, 0]));
    let total = 0;
    for (const r of rows) {
        if (!r.wx) continue;
        const group = SKY_GROUP_MAP.get(normalizeCondition(r.wx));
        if (group) {
            counts.set(group, (counts.get(group) as number) + 1);
        } else if (!warnedConditions.has(r.wx)) {
            warnedConditions.add(r.wx);
            console.warn(`computeSkyGroups: unmapped weather condition "${r.wx}" — excluded from sky groups. Add it to SKY_GROUP_MAP.`);
        }
        total += 1;
    }
    return SKY_GROUP_ORDER.map((group) => ({
        group,
        count: counts.get(group) as number,
        pct: total ? round(((counts.get(group) as number) / total) * 100, 1) : 0,
    }));
}

export function computeConditionFrequency(rows: DayRow[]) {
    const counts = new Map<string, number>();
    let total = 0;
    for (const r of rows) {
        if (!r.wx) continue;
        counts.set(r.wx, (counts.get(r.wx) || 0) + 1);
        total += 1;
    }
    return Array.from(counts.entries())
        .map(([wx, count]) => ({ wx, count, pct: total ? round((count / total) * 100, 1) : 0 }))
        .sort((a, b) => b.count - a.count);
}

export function computeYearlyAverages(rows: DayRow[]) {
    const buckets = new Map<number, { tmax: number[]; tmin: number[]; precip: number; count: number }>();
    for (const r of rows) {
        const { year } = dateParts(r.date);
        if (!buckets.has(year)) buckets.set(year, { tmax: [], tmin: [], precip: 0, count: 0 });
        const b = buckets.get(year)!;
        if (r.tmax != null) b.tmax.push(r.tmax);
        if (r.tmin != null) b.tmin.push(r.tmin);
        if (r.precip != null) b.precip += r.precip;
        b.count += 1;
    }
    return Array.from(buckets.entries())
        .sort((a, b) => a[0] - b[0])
        .map(([year, b]) => ({
            year,
            avgTmax: mean(b.tmax),
            avgTmin: mean(b.tmin),
            totalPrecip: round(b.precip, 2),
            dayCount: b.count,
        }));
}

export function filterByYear<T extends { date: string }>(rows: T[], year: number): T[] {
    return rows.filter((r) => dateParts(r.date).year === year);
}

export function excludeYear<T extends { date: string }>(rows: T[], year: number): T[] {
    return rows.filter((r) => dateParts(r.date).year !== year);
}

export function computeAnnualSummary(rows: DayRow[]) {
    const records = computeRecords(rows);
    const yearly = computeYearlyAverages(rows);
    // Data starts mid-July, so the first and last calendar years are partial; exclude them from "typical year"
    // averaging to avoid skew.
    const completeYears = yearly.filter((y) => y.dayCount >= 300);
    const freq = computeConditionFrequency(rows);
    return {
        records,
        yearly,
        avgAnnualHigh: mean(completeYears.map((y) => y.avgTmax)),
        avgAnnualLow: mean(completeYears.map((y) => y.avgTmin)),
        avgAnnualPrecip: mean(completeYears.map((y) => y.totalPrecip)),
        conditionFrequency: freq,
        mostCommonCondition: freq[0] ? freq[0].wx : null,
        totalDays: rows.length,
        dateRange: getDateRange(rows),
    };
}

// --- Enrichment-row helpers (S2/S3/S4) ------------------------------------------------------------------------

export function num(v: unknown): number | null {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return Number.isNaN(n) ? null : n;
}

function monthBucketsOf(rows: ApiRow[], fields: string[]): Array<Record<string, number[]>> {
    const buckets = Array.from({ length: 12 }, () => {
        const b: Record<string, number[]> = {};
        for (const f of fields) b[f] = [];
        return b;
    });
    for (const r of rows) {
        const { month } = dateParts(r.date);
        const b = buckets[month - 1];
        for (const f of fields) {
            const v = num(r[f]);
            if (v != null) b[f].push(v);
        }
    }
    return buckets;
}

export function computeSolarNormals(rows: ApiRow[]) {
    const fields = ['daylight_hours', 'sunshine_hours', 'solar_radiation_mj_m2', 'direct_radiation_mj_m2', 'diffuse_radiation_mj_m2', 'et0_mm'];
    const buckets = monthBucketsOf(rows, fields);
    return buckets.map((b, i) => ({
        month: i + 1,
        monthName: MONTH_NAMES[i],
        avgDaylightHours: mean(b.daylight_hours),
        avgSunshineHours: mean(b.sunshine_hours),
        avgSolarRadiation: mean(b.solar_radiation_mj_m2),
        avgDirectRadiation: mean(b.direct_radiation_mj_m2),
        avgDiffuseRadiation: mean(b.diffuse_radiation_mj_m2),
        // `et0_mm` is a misnomer: the column holds INCHES per day (daily_update.php requests precipitation_unit=inch
        // and Open-Meteo applies that to every water-depth variable, et0 included). Everything that displays this
        // value must label it in/day. Renaming the column is tracked separately in PROJECT_PLAN.md.
        avgEt0: mean(b.et0_mm, 3),
    }));
}

export const COMPASS_16 = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];

export function computeWindRose(rows: ApiRow[]) {
    const bins = COMPASS_16.map((label) => ({ label, speeds: [] as Array<number | null> }));
    let total = 0;
    for (const r of rows) {
        const dir = num(r.wind_dir_dominant_deg);
        const speed = num(r.wind_speed_avg_mph);
        if (dir == null) continue;
        const idx = Math.round(dir / 22.5) % 16;
        bins[idx].speeds.push(speed);
        total += 1;
    }
    return bins.map((b) => ({
        label: b.label,
        pct: total ? round((b.speeds.length / total) * 100, 1) : 0,
        avgSpeed: mean(b.speeds.filter((s) => s != null)),
    }));
}

export function computeSnowNormals(rows: ApiRow[]) {
    const buckets = monthBucketsOf(rows, ['snow_depth_in']);
    return buckets.map((b, i) => ({
        month: i + 1,
        monthName: MONTH_NAMES[i],
        avgSnowDepth: mean(b.snow_depth_in),
        maxSnowDepth: b.snow_depth_in.length ? round(Math.max(...b.snow_depth_in), 2) : null,
    }));
}

export const SOIL_DEPTHS = [
    { key: '0_7cm', label: '0-7cm', tempField: 'soil_temp_0_7cm_f', moistField: 'soil_moisture_0_7cm_m3m3' },
    { key: '7_28cm', label: '7-28cm', tempField: 'soil_temp_7_28cm_f', moistField: 'soil_moisture_7_28cm_m3m3' },
    { key: '28_100cm', label: '28-100cm', tempField: 'soil_temp_28_100cm_f', moistField: 'soil_moisture_28_100cm_m3m3' },
    { key: '100_255cm', label: '100-255cm', tempField: 'soil_temp_100_255cm_f', moistField: 'soil_moisture_100_255cm_m3m3' },
];

export function computeSoilNormals(rows: ApiRow[]) {
    const fields = SOIL_DEPTHS.flatMap((d) => [d.tempField, d.moistField]);
    const buckets = monthBucketsOf(rows, fields);
    return buckets.map((b, i) => {
        const entry: Record<string, number | string | null> = { month: i + 1, monthName: MONTH_NAMES[i] };
        for (const d of SOIL_DEPTHS) {
            entry[`temp_${d.key}`] = mean(b[d.tempField]);
            entry[`moist_${d.key}`] = mean(b[d.moistField], 3);
        }
        return entry;
    });
}

export function computeVpdNormals(rows: ApiRow[]) {
    const buckets = monthBucketsOf(rows, ['vpd_avg_kpa']);
    return buckets.map((b, i) => ({
        month: i + 1,
        monthName: MONTH_NAMES[i],
        avgVpd: mean(b.vpd_avg_kpa, 2),
    }));
}

function dayOfYear(dateStr: string): number {
    const { year, month, day } = dateParts(dateStr);
    return Math.round((Date.UTC(year, month - 1, day) - Date.UTC(year, 0, 1)) / 86400000) + 1;
}

// Typical last-spring-frost / first-fall-frost dates, averaged across years (frost = temp_min_f < 32). July 1 splits
// "spring side" from "fall side" of each year so a mid-winter cold snap doesn't get miscounted.
export function computeFrostWindow(rows: ApiRow[]) {
    const byYear = new Map<number, Array<{ month: number; day: number; t: number; dayOfYear: number }>>();
    for (const r of rows) {
        const t = num(r.temp_min_f);
        if (t == null) continue;
        const { year, month, day } = dateParts(r.date);
        if (!byYear.has(year)) byYear.set(year, []);
        byYear.get(year)!.push({ month, day, t, dayOfYear: dayOfYear(r.date) });
    }
    const lastSpringFrosts: number[] = [];
    const firstFallFrosts: number[] = [];
    for (const [, days] of byYear) {
        const spring = days.filter((d) => d.month <= 6 && d.t < 32);
        const fall = days.filter((d) => d.month >= 7 && d.t < 32);
        if (spring.length) lastSpringFrosts.push(Math.max(...spring.map((d) => d.dayOfYear)));
        if (fall.length) firstFallFrosts.push(Math.min(...fall.map((d) => d.dayOfYear)));
    }
    return {
        lastSpringFrostDay: lastSpringFrosts.length ? Math.round(mean(lastSpringFrosts) as number) : null,
        firstFallFrostDay: firstFallFrosts.length ? Math.round(mean(firstFallFrosts) as number) : null,
        yearsWithSpringFrost: lastSpringFrosts.length,
        yearsWithFallFrost: firstFallFrosts.length,
        totalYears: byYear.size,
    };
}

export function dayOfYearToLabel(doy: number | null | undefined): string {
    if (doy == null) return '—';
    const d = new Date(Date.UTC(2021, 0, doy));
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
}

// air_quality_daily rows use the API's own column names directly (aqi_us, pm2_5, pm10, ozone, nitrogen_dioxide,
// sulphur_dioxide, carbon_monoxide, dust, ammonia, uv_index_max), same string-from-DECIMAL caveat as the
// enrichment helpers above: every value coerced through num().
export function computeAqiNormals(rows: ApiRow[]) {
    const fields = ['aqi_us', 'pm2_5', 'pm10', 'ozone', 'uv_index_max'];
    const buckets = monthBucketsOf(rows, fields);
    return buckets.map((b, i) => ({
        month: i + 1,
        monthName: MONTH_NAMES[i],
        avgAqi: mean(b.aqi_us),
        avgPm25: round(mean(b.pm2_5), 1),
        avgPm10: round(mean(b.pm10), 1),
        avgOzone: round(mean(b.ozone), 1),
        avgUvMax: round(mean(b.uv_index_max), 1),
    }));
}
