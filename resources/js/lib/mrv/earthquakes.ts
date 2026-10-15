/**
 * The Earthquake Activity page's rules (public_html/js/earthquakes.js), ported in port phase 4: the time windows, which
 * events are shown (not deleted, not quarry blasts and the like, within 60 miles of the region's center), the magnitude
 * color and size scales, the statistics cards, the list, and the time text. Golden tests run the legacy script over the
 * saved production response and variants (earthquakes.test.tsx).
 */
import { fetchJson, MRV_API_BASE, type FetchLike } from './api';
import { escapeHtml } from './html';
import { type PointMarker } from './leaflet-map';

export interface QuakeEvent {
    event_time: string;
    latitude: string | number | null;
    longitude: string | number | null;
    magnitude: string | number | null;
    depth_km: string | number | null;
    place: string | null;
    status?: string | null;
    event_type?: string | null;
}

export const WINDOWS = [
    { key: '7', label: '7 Days', days: 7 },
    { key: '30', label: '30 Days', days: 30 },
    { key: '90', label: '90 Days', days: 90 },
    { key: '365', label: '1 Year', days: 365 },
];
export const DEFAULT_WINDOW_KEY = '30';
export const MIN_MAGNITUDE_DEFAULT = 1.5;
export const LIST_CAP = 300;

// The stored catalog covers a rectangle around the project's footprint, whose corners reach areas that have nothing to do
// with this region (LA Basin events, found 2026-09-19); anything farther than MAX_DISTANCE_MI from Victorville is dropped.
export const CENTER_LAT = 34.53611111;
export const CENTER_LON = -117.2911111;
export const MAX_DISTANCE_MI = 60;
const EARTH_RADIUS_MI = 3958.8;

export function distanceMi(lat: number, lon: number): number {
    const p1 = (CENTER_LAT * Math.PI) / 180;
    const p2 = (lat * Math.PI) / 180;
    const dp = ((lat - CENTER_LAT) * Math.PI) / 180;
    const dl = ((lon - CENTER_LON) * Math.PI) / 180;
    const a = Math.sin(dp / 2) ** 2 + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) ** 2;
    return 2 * EARTH_RADIUS_MI * Math.asin(Math.sqrt(a));
}

/** Shown unless USGS marked it deleted or typed it as something other than an earthquake; a missing type counts as an earthquake. */
export function isShownEvent(e: QuakeEvent): boolean {
    if (e.status === 'deleted') return false;
    return e.event_type == null || e.event_type === 'earthquake';
}

export function withinRegion(e: QuakeEvent): boolean {
    if (e.latitude == null || e.longitude == null) return false;
    return distanceMi(Number(e.latitude), Number(e.longitude)) <= MAX_DISTANCE_MI;
}

// A sequential single hue: magnitude is a measured quantity here, not a category.
const MAG_HUE = 9;
const MAG_SAT = 55;

const PACIFIC_FORMAT = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Los_Angeles', dateStyle: 'medium', timeStyle: 'short' });

export function magColor(mag: number | null, minMag: number | null, maxMag: number | null): string {
    if (mag == null) return `hsl(${MAG_HUE}, ${MAG_SAT}%, 72%)`;
    if (minMag == null || maxMag == null || minMag === maxMag) return `hsl(${MAG_HUE}, ${MAG_SAT}%, 45%)`;
    const t = Math.min(1, Math.max(0, (mag - minMag) / (maxMag - minMag)));
    const l = Math.round(80 - t * 45); // 80% (lowest) to 35% (highest): lighter and smaller reads as less severe
    return `hsl(${MAG_HUE}, ${MAG_SAT}%, ${l}%)`;
}

/** A visible minimum even for zero and negative magnitudes (very small events do report them). */
export function magRadius(mag: number | null): number {
    const m = mag == null ? 0 : Math.max(0, mag);
    return Math.max(4, 4 + m * 2.6);
}

/** event_time is 'YYYY-MM-DD HH:MM:SS' in UTC (no 'T', no 'Z'), so it is read as UTC explicitly. */
export const parseEventTime = (raw: string): Date => new Date(raw.replace(' ', 'T') + 'Z');

export const formatEventTime = (raw: string): string => PACIFIC_FORMAT.format(parseEventTime(raw));

export function timeAgo(raw: string, now: number = Date.now()): string {
    const diffMs = now - parseEventTime(raw).getTime();
    const mins = Math.floor(diffMs / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}

const fmtDate = (d: Date): string => d.toISOString().slice(0, 10);

/** The request for a window: `to` is tomorrow's date because a bare date means midnight and would drop today's events. */
export function windowQuery(days: number, now: Date = new Date()): string {
    const to = new Date(now.getTime() + 86400000);
    const from = new Date(now.getTime() - days * 86400000);
    return `${MRV_API_BASE}/query.php?table=earthquakes&from=${fmtDate(from)}&to=${fmtDate(to)}&limit=20000`;
}

export async function fetchEvents(days: number, fetchImpl?: FetchLike, now: Date = new Date()): Promise<QuakeEvent[]> {
    const rows = await fetchJson<QuakeEvent[]>(windowQuery(days, now), fetchImpl, (status) => `Earthquake fetch failed: HTTP ${status}`);
    return rows.filter((e) => isShownEvent(e) && withinRegion(e));
}

export function filteredEvents(events: QuakeEvent[], minMagnitude: number | null): QuakeEvent[] {
    if (minMagnitude == null) return events;
    return events.filter((e) => e.magnitude != null && Number(e.magnitude) >= minMagnitude);
}

export function magRange(events: QuakeEvent[]): { min: number | null; max: number | null } {
    const mags = events
        .map((e) => e.magnitude)
        .filter((m) => m != null)
        .map(Number);
    if (!mags.length) return { min: null, max: null };
    return { min: Math.min(...mags), max: Math.max(...mags) };
}

export interface StatCardData {
    label: string;
    value: string;
    sub: string;
}

export const LOADING_STAT: StatCardData = { label: 'Events Shown', value: '…', sub: 'Loading' };

const magText = (m: string | number | null) => (m != null ? Number(m).toFixed(1) : '?');

export function quakeStats(events: QuakeEvent[], now: number = Date.now()): StatCardData[] {
    if (!events.length) {
        return [{ label: 'Events in Window', value: '0', sub: 'No matching activity — try widening the window or magnitude filter' }];
    }
    const withMag = events.filter((e) => e.magnitude != null);
    const largest = withMag.length ? withMag.reduce((a, b) => (Number(b.magnitude) > Number(a.magnitude) ? b : a)) : null;
    const mostRecent = events[events.length - 1]; // the API returns oldest first, so the last one is the newest

    return [
        { label: 'Events Shown', value: events.length.toLocaleString(), sub: '' },
        largest
            ? {
                  label: 'Largest',
                  value: `M${Number(largest.magnitude).toFixed(1)}`,
                  sub: `${largest.place || 'Unknown location'} · ${formatEventTime(largest.event_time)}`,
              }
            : { label: 'Largest', value: '—', sub: '' },
        {
            label: 'Most Recent',
            value: timeAgo(mostRecent.event_time, now),
            sub: `M${magText(mostRecent.magnitude)} · ${mostRecent.place || 'Unknown location'}`,
        },
    ];
}

/** The six colors of the legend's gradient bar. */
export function legendStops(minMag: number, maxMag: number): string[] {
    const stops = 6;
    return Array.from({ length: stops }, (_, i) => magColor(minMag + (i / (stops - 1)) * (maxMag - minMag), minMag, maxMag));
}

export function quakePopupHtml(e: QuakeEvent): string {
    const mag = e.magnitude != null ? `M${Number(e.magnitude).toFixed(1)}` : 'M?';
    const depth = e.depth_km != null ? `${Number(e.depth_km).toFixed(1)} km deep` : null;
    return `
      <strong>${mag} — ${escapeHtml(e.place || 'Unknown location')}</strong><br>
      <span class="card-note">${formatEventTime(e.event_time)}${depth ? ' · ' + depth : ''}</span>
    `;
}

/** One marker per event that has coordinates. */
export function quakePins(events: QuakeEvent[], minMag: number | null, maxMag: number | null): PointMarker[] {
    return events
        .filter((e) => e.latitude != null && e.longitude != null)
        .map((e) => {
            const mag = e.magnitude != null ? Number(e.magnitude) : null;
            return {
                lat: Number(e.latitude),
                lon: Number(e.longitude),
                radius: magRadius(mag),
                fillColor: magColor(mag, minMag, maxMag),
                fillOpacity: 0.75,
                popup: quakePopupHtml(e),
            };
        });
}

/** The rows of the list (newest first, at most LIST_CAP) and the sentence above them. */
export function quakeList(events: QuakeEvent[]): { shown: QuakeEvent[]; note: string } {
    const shown = [...events].reverse().slice(0, LIST_CAP);
    const note =
        events.length > LIST_CAP
            ? `Showing the ${LIST_CAP} most recent of ${events.length.toLocaleString()} matching events — narrow the window or raise the magnitude filter to see fewer, larger events.`
            : `Showing all ${events.length.toLocaleString()} matching events, newest first.`;
    return { shown, note };
}

export const MAG_OPTIONS = [
    { key: 'felt', label: `M${MIN_MAGNITUDE_DEFAULT}+ (likely felt)`, value: MIN_MAGNITUDE_DEFAULT as number | null },
    { key: 'all', label: 'Show All (incl. microquakes)', value: null as number | null },
];
