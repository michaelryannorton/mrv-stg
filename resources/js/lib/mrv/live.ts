/**
 * The "Right Now" card on the City Explorer (public_html/js/live.js), ported in port phase 6. It reads the latest row of
 * `live_conditions_log` (written by the data-collection cron), never api.weather.gov directly, so it scales with the
 * number of visitors. The card's text is built here; the page shows it escaped (production writes API text into the
 * page as HTML; for ordinary text the two look the same).
 */
import { fetchJson, MRV_API_BASE, type FetchLike } from './api';

const COMPASS = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];

export interface LiveRow {
    observed_at: string;
    // The API sends MySQL decimals as strings ("69.8"); Number() reads both.
    temp_f: number | string | null;
    humidity_pct: number | string | null;
    wind_speed_mph: number | string | null;
    wind_dir_deg: number | null;
    condition_text: string | null;
    active_alerts: string | null;
}

export function compassLabel(deg: number | null | undefined): string | null {
    if (deg === null || deg === undefined) return null;

    return COMPASS[Math.round(deg / 22.5) % 16];
}

/** "2026-07-23 22:55:00" (already Pacific local time) as "10:55 PM". */
export function fmtObservedAt(observedAt: string): string {
    const d = new Date(observedAt.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return observedAt;

    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

export function alertSeverity(text: string): 'severe' | 'moderate' | 'mild' {
    if (/warning/i.test(text)) return 'severe';
    if (/watch/i.test(text)) return 'moderate';

    return 'mild';
}

/**
 * The latest observation for a city, from the last two days only: the table grows forever by design, so an unbounded
 * request would get slower (and eventually wrong, once the rows passed the limit). The API answers in ascending order.
 */
export async function fetchLatest(cityId: number | string, now: number = Date.now(), fetchImpl?: FetchLike): Promise<LiveRow | null> {
    const since = new Date(now - 2 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    const url = `${MRV_API_BASE}/query.php?table=live_conditions_log&city_id=${cityId}&from=${since}&limit=500`;
    const rows = await fetchJson<LiveRow[]>(url, fetchImpl, (status) => `Live conditions fetch failed: HTTP ${status}`);

    return rows.length ? rows[rows.length - 1] : null;
}

export interface LiveCard {
    time: string;
    temp: string;
    condition: string | null;
    humidity: string;
    wind: string;
    alert: { text: string; severity: 'severe' | 'moderate' | 'mild' } | null;
}

/** What the card shows, or null when there is no recent observation (the card is then left empty, not shown broken). */
export function liveCard(latest: LiveRow | null): LiveCard | null {
    if (!latest || latest.temp_f === null) return null;
    const wind =
        latest.wind_speed_mph !== null
            ? `${Math.round(Number(latest.wind_speed_mph))} mph${compassLabel(latest.wind_dir_deg) ? ' ' + compassLabel(latest.wind_dir_deg) : ''}`
            : '—';

    return {
        time: fmtObservedAt(latest.observed_at),
        temp: `${Math.round(Number(latest.temp_f))}°F`,
        condition: latest.condition_text ? latest.condition_text : null,
        humidity: latest.humidity_pct !== null ? `${Math.round(Number(latest.humidity_pct))}%` : '—',
        wind,
        alert: latest.active_alerts ? { text: latest.active_alerts, severity: alertSeverity(latest.active_alerts) } : null,
    };
}

/** The card's three states on the page: nothing yet, "Checking current conditions…", or the card. */
export type LiveCardState = { status: 'idle' } | { status: 'loading' } | { status: 'card'; card: LiveCard };
