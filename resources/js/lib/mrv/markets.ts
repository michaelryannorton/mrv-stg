/**
 * The Farmers Market Directory's rules (public_html/js/markets.js), ported in port phase 3: the JSON columns that
 * arrive as strings, region colors, which markets are flagged, the EBT check, and the popup and count text.
 * Golden tests run the legacy script over the saved production response and variants (markets.test.tsx).
 */
import { fetchJson, MRV_API_BASE, type FetchLike } from './api';
import { escapeHtml } from './html';
import { type PointMarker } from './leaflet-map';

export interface MarketRow {
    id?: number;
    name: string;
    city: string;
    region: string;
    latitude?: string | number | null;
    longitude?: string | number | null;
    schedule_summary?: string | null;
    venue_name?: string | null;
    address?: string | null;
    description_short?: string | null;
    official_url?: string | null;
    status_note?: string | null;
    verification_status?: string | null;
    best_for?: unknown;
    schedule_periods?: unknown;
    product_categories?: string[] | string | null;
    assistance_programs?: Array<{ program?: string; status?: string }> | string | null;
    payment_methods?: unknown;
    features?: unknown;
    social_accounts?: Array<{ platform: string; url: string }> | string | null;
    sources?: unknown;
    data_notes?: string[] | string | null;
}

/** A market after its JSON columns are parsed. */
export interface Market extends Omit<MarketRow, 'product_categories' | 'assistance_programs' | 'social_accounts' | 'data_notes'> {
    product_categories: string[] | null;
    assistance_programs: Array<{ program?: string; status?: string }> | null;
    social_accounts: Array<{ platform: string; url: string }> | null;
    data_notes: string[] | null;
}

export const JSON_COLS = [
    'best_for',
    'schedule_periods',
    'product_categories',
    'assistance_programs',
    'payment_methods',
    'features',
    'social_accounts',
    'sources',
    'data_notes',
] as const;

// Fixed order, colors by region identity (never by count or rank, never reassigned).
export const REGION_META: Record<string, string> = {
    'Victor Valley': '#C1592F',
    'Victor Valley / West High Desert': '#4E7C9B',
    'San Gabriel Mountains / West High Desert': '#7C8A6E',
    'Barstow Area': '#C79A3D',
    'Big Bear Valley / San Bernardino Mountains': '#7A4B6B',
    'San Bernardino Mountains': '#375A73',
    'Antelope Valley': '#9E4322',
};
export const REGION_FALLBACK = '#55504A';

export const regionColor = (region: string): string => REGION_META[region] || REGION_FALLBACK;

/** query.php returns the JSON columns as strings; a string that does not parse becomes null (production's parseMarket). */
export function parseMarket(row: MarketRow): Market {
    const m: Record<string, unknown> = { ...row };
    for (const col of JSON_COLS) {
        if (typeof m[col] === 'string') {
            try {
                m[col] = JSON.parse(m[col] as string);
            } catch {
                m[col] = null;
            }
        }
    }
    return m as unknown as Market;
}

export async function fetchMarkets(fetchImpl?: FetchLike): Promise<Market[]> {
    const rows = await fetchJson<MarketRow[]>(
        `${MRV_API_BASE}/query.php?table=farmers_markets&limit=500`,
        fetchImpl,
        (status) => `Market fetch failed: HTTP ${status}`,
    );
    return rows.map(parseMarket);
}

export const needsReview = (m: Market): boolean => !!m.verification_status && m.verification_status !== 'verified';

export function acceptsEbt(m: Market): boolean {
    return (m.assistance_programs || []).some((p) => /ebt|calfresh|snap/i.test(p.program || '') && /accept|particip/i.test(String(p.status || '')));
}

/** City then name, as production sorts them (locale compare). */
export function sortMarkets(markets: Market[]): Market[] {
    return markets.sort((a, b) => a.city.localeCompare(b.city) || a.name.localeCompare(b.name));
}

/** "Showing 14 markets · 5 with a schedule detail still being confirmed." */
export function marketCountNote(markets: Market[]): string {
    const flagged = markets.filter(needsReview).length;
    return `Showing ${markets.length} markets` + (flagged ? ` · ${flagged} with a schedule detail still being confirmed` : '') + '.';
}

/** Regions present, in the fixed order (unknown regions last, in first-seen order). */
export function regionsPresent(markets: Market[]): string[] {
    const order = Object.keys(REGION_META);
    const rank = (r: string) => {
        const i = order.indexOf(r);
        return i === -1 ? order.length : i;
    };
    return [...new Set(markets.map((m) => m.region))].sort((a, b) => rank(a) - rank(b));
}

export function hasLocation(m: Market): boolean {
    return m.latitude != null && m.longitude != null;
}

/** The text of the verify-before-visiting flag on a card. */
export function reviewNote(m: Market): string {
    return `Verify before visiting — ${m.status_note || (m.data_notes && m.data_notes[0]) || 'sources disagree on schedule details'}.`;
}

export const SOCIAL_LABELS: Record<string, string> = { facebook: 'Facebook', instagram: 'Instagram', x: 'X', tiktok: 'TikTok', youtube: 'YouTube' };

/** The map popup (a Leaflet popup takes an HTML string; every value from the data is escaped). */
export function marketPopupHtml(m: Market): string {
    const badge = needsReview(m) ? '<div class="market-popup-flag">Schedule needs confirmation</div>' : '';
    return `
      <strong>${escapeHtml(m.name)}</strong><br>
      <span class="card-note">${escapeHtml(m.city)} · ${escapeHtml(m.region)}</span><br>
      ${escapeHtml(m.schedule_summary || '')}
      ${badge}
    `;
}

/** One map marker per market that has a location (production's renderMap). */
export function marketPins(markets: Market[]): PointMarker[] {
    return markets.filter(hasLocation).map((m) => ({
        lat: Number(m.latitude),
        lon: Number(m.longitude),
        radius: 9,
        fillColor: regionColor(m.region),
        fillOpacity: 0.88,
        tooltip: escapeHtml(m.name),
        popup: marketPopupHtml(m),
    }));
}
