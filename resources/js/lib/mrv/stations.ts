/**
 * The Data Explorer's rules (public_html/js/stations.js), ported in port phase 3: station networks with fixed
 * colors, legend order and counts, which stations can be drawn, and the popup text. Golden tests run the legacy
 * script over the saved production response and variants (stations.test.tsx).
 */
import { fetchJson, MRV_API_BASE, type FetchLike } from './api';
import { escapeHtml } from './html';
import { type PointMarker } from './leaflet-map';

export interface Station {
    id?: number;
    external_id: string;
    name: string;
    network: string;
    latitude: string | number | null;
    longitude: string | number | null;
    elevation_ft?: string | number | null;
}

export interface NetworkMeta {
    label: string;
    color: string;
}

// Fixed order, from the site's validated categorical sequence; never reassigned as networks are added or filtered.
export const NETWORK_META: Record<string, NetworkMeta> = {
    airport: { label: 'Airport (ASOS/AWOS)', color: '#C1592F' },
    raws: { label: 'RAWS (Fire Weather Station)', color: '#4E7C9B' },
    utility: { label: 'Utility Weather Station', color: '#7C8A6E' },
    volunteer: { label: 'Volunteer / Community Station', color: '#C79A3D' },
};
export const FALLBACK_META: NetworkMeta = { label: 'Other', color: '#7A4B6B' };

export const networkMeta = (network: string): NetworkMeta => NETWORK_META[network] || FALLBACK_META;

export const fetchStations = (fetchImpl?: FetchLike): Promise<Station[]> =>
    fetchJson<Station[]>(`${MRV_API_BASE}/query.php?table=stations&limit=500`, fetchImpl, (status) => `Station fetch failed: HTTP ${status}`);

/** The networks present with their station counts, in the fixed order (unknown networks last, in first-seen order). */
export function networkLegend(stations: Station[]): Array<{ network: string; count: number }> {
    const counts: Record<string, number> = {};
    for (const s of stations) counts[s.network] = (counts[s.network] || 0) + 1;
    const order = Object.keys(NETWORK_META);
    const rank = (n: string) => {
        const i = order.indexOf(n);
        return i === -1 ? order.length : i;
    };
    return Object.keys(counts)
        .sort((a, b) => rank(a) - rank(b))
        .map((network) => ({ network, count: counts[network] }));
}

/** Every network switched on at the start: the known ones, then any others in the data. */
export function initialNetworks(stations: Station[]): Set<string> {
    return new Set(Object.keys(NETWORK_META).concat([...new Set(stations.map((s) => s.network))].filter((n) => !(n in NETWORK_META))));
}

export interface DrawableStation {
    station: Station;
    lat: number;
    lon: number;
}

/** The stations to draw: switched-on networks with finite coordinates (production's draw loop). */
export function drawableStations(stations: Station[], active: Set<string>): DrawableStation[] {
    const out: DrawableStation[] = [];
    for (const s of stations) {
        if (!active.has(s.network)) continue;
        const lat = Number(s.latitude);
        const lon = Number(s.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) continue;
        out.push({ station: s, lat, lon });
    }
    return out;
}

/** The points the map is fitted to: every station with finite coordinates, whatever its switch. */
export function allPoints(stations: Station[]): Array<[number, number]> {
    return stations
        .map((s) => [Number(s.latitude), Number(s.longitude)] as [number, number])
        .filter(([lat, lon]) => Number.isFinite(lat) && Number.isFinite(lon));
}

export const stationCountNote = (shown: number, total: number): string => `Showing ${shown} of ${total} stations.`;

export function stationPopupHtml(s: Station): string {
    const meta = networkMeta(s.network);
    const elev = s.elevation_ft !== null && s.elevation_ft !== undefined ? `${Math.round(Number(s.elevation_ft)).toLocaleString()} ft` : '—';
    return `
      <strong>${escapeHtml(s.name)}</strong><br>
      ${escapeHtml(meta.label)}<br>
      Elevation: ${elev}<br>
      <span class="card-note">ID: ${escapeHtml(s.external_id)} · ${Number(s.latitude).toFixed(4)}, ${Number(s.longitude).toFixed(4)}</span>
    `;
}

export function stationPins(drawn: DrawableStation[]): PointMarker[] {
    return drawn.map(({ station, lat, lon }) => ({
        lat,
        lon,
        radius: 8,
        fillColor: networkMeta(station.network).color,
        fillOpacity: 0.85,
        tooltip: escapeHtml(station.name),
        popup: stationPopupHtml(station),
    }));
}
