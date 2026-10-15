/**
 * The pure parts of public_html/js/map.js (RegionMap): the color and size scales for map markers and the tile
 * constants. Port phase 2; golden tests compare them with the legacy functions (region-map.test.ts).
 * The Leaflet calls are in leaflet-map.ts.
 */

// Sequential cool -> warm scale drawn from the site palette.
export const COLOR_STOPS = ['#4E7C9B', '#7C8A6E', '#C79A3D', '#C1592F', '#7A4B6B'];
export const TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
export const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

type Num = number | null | undefined;

export function colorForValue(value: Num, min: Num, max: Num): string {
    if (value == null || min == null || max == null || min === max) {
        return COLOR_STOPS[Math.floor(COLOR_STOPS.length / 2)];
    }
    const t = Math.min(1, Math.max(0, (value - min) / (max - min)));
    const idx = Math.min(COLOR_STOPS.length - 1, Math.floor(t * COLOR_STOPS.length));
    return COLOR_STOPS[idx];
}

export function radiusForValue(value: Num, min: Num, max: Num, minR = 9, maxR = 22): number {
    if (value == null || min == null || max == null || min === max) return (minR + maxR) / 2;
    const t = Math.min(1, Math.max(0, (value - min) / (max - min)));
    return minR + t * (maxR - minR);
}
