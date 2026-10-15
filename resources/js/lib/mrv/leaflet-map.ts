/**
 * The Leaflet calls of public_html/js/map.js (RegionMap), ported: the region map (value-colored circle markers with
 * popups and tooltips, fitted to the points) and the single-city locator. Same view, zoom caps, marker styles and
 * tile layer as production. The legacy module kept the marker layer in a module variable; here it is returned.
 */
import * as L from 'leaflet';
import { colorForValue, radiusForValue, TILE_ATTRIBUTION, TILE_URL } from './region-map';

export interface MapPoint {
    name: string;
    lat: number | null;
    lon: number | null;
}

export type MarkerValues = Record<string, number | null | undefined>;

export interface RegionMapHandle {
    map: L.Map;
    markers: L.LayerGroup;
}

export function initRegionMap(container: string | HTMLElement, cityPoints: MapPoint[]): RegionMapHandle {
    const map = L.map(container, { scrollWheelZoom: false }).setView([34.55, -117.0], 8);
    L.tileLayer(TILE_URL, { attribution: TILE_ATTRIBUTION, maxZoom: 14 }).addTo(map);
    const markers = L.layerGroup().addTo(map);

    const valid = cityPoints.filter((p) => p.lat != null && p.lon != null);
    if (valid.length) {
        const bounds = L.latLngBounds(valid.map((p) => [p.lat as number, p.lon as number] as L.LatLngTuple));
        // Cap the fit zoom: with only 11 points spread ~150km apart, an uncapped fitBounds can over-zoom past what's useful for a region view.
        map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
    }
    return { map, markers };
}

export function renderMarkers(
    layer: L.LayerGroup,
    cityPoints: MapPoint[],
    values: MarkerValues,
    { formatPopup }: { formatPopup?: (p: MapPoint, value: number | null | undefined) => string } = {},
): { min: number | null; max: number | null } {
    layer.clearLayers();
    const nums = cityPoints.map((p) => values[p.name]).filter((v): v is number => v != null);
    const min = nums.length ? Math.min(...nums) : null;
    const max = nums.length ? Math.max(...nums) : null;

    for (const p of cityPoints) {
        if (p.lat == null || p.lon == null) continue;
        const value = values[p.name];
        const color = colorForValue(value, min, max);
        const radius = radiusForValue(value, min, max);
        const marker = L.circleMarker([p.lat, p.lon], {
            radius,
            color: '#2E2A26',
            weight: 1,
            fillColor: color,
            fillOpacity: 0.85,
        });
        marker.bindPopup(formatPopup ? formatPopup(p, value) : `<strong>${p.name}</strong>`);
        marker.bindTooltip(p.name, { direction: 'top', offset: [0, -radius] });
        marker.addTo(layer);
    }
    return { min, max };
}

export function initMiniMap(container: string | HTMLElement, lat: number | null, lon: number | null, label: string): L.Map | null {
    if (lat == null || lon == null) return null;
    const map = L.map(container, {
        scrollWheelZoom: false,
        zoomControl: false,
        dragging: false,
        doubleClickZoom: false,
    }).setView([lat, lon], 10);
    L.tileLayer(TILE_URL, { attribution: TILE_ATTRIBUTION, maxZoom: 14 }).addTo(map);
    L.circleMarker([lat, lon], { radius: 10, color: '#2E2A26', weight: 1, fillColor: '#C1592F', fillOpacity: 0.9 })
        .bindTooltip(label, { permanent: true, direction: 'top', offset: [0, -10] })
        .addTo(map);
    return map;
}

export function destroyMap(map: L.Map | null | undefined): void {
    if (map) map.remove();
}
