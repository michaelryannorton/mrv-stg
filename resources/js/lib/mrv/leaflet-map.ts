/**
 * The Leaflet calls of public_html/js/map.js (RegionMap), ported: the region map (value-colored circle markers with
 * popups and tooltips, fitted to the points) and the single-city locator. Same view, zoom caps, marker styles and
 * tile layer as production. The legacy module kept the marker layer in a module variable; here it is returned.
 */
import * as L from 'leaflet';
import { markerStyle, wellTooltip, type Well } from './groundwater';
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

// ---- marker maps for the directory pages (Data Explorer, Farmers Markets, Public Art; port phase 3) --------------------
// The three pages build the same kind of map: the region view, the tile layer, a layer group, one tooltip-and-popup
// circle marker per item, fitted to the points. The pieces are separate functions because the legacy pages call them
// in different orders (the Data Explorer fits before it draws; the others draw first).

export interface PointMarker {
    lat: number;
    lon: number;
    radius: number;
    fillColor: string;
    fillOpacity: number;
    /** HTML string (Leaflet reads tooltips and popups as HTML): escape any text that came from data. */
    tooltip?: string;
    popup: string;
}

export interface FitOptions {
    padding: number;
    maxZoom: number;
}

export interface MapView {
    center: [number, number];
    zoom: number;
}

export function createMarkerMap(container: string | HTMLElement, view: MapView = { center: [34.55, -117.0], zoom: 8 }): RegionMapHandle {
    const map = L.map(container).setView(view.center, view.zoom);
    L.tileLayer(TILE_URL, { attribution: TILE_ATTRIBUTION, maxZoom: 14 }).addTo(map);
    const markers = L.layerGroup().addTo(map);
    return { map, markers };
}

export function drawMarkers(layer: L.LayerGroup, markers: PointMarker[], { clear = true }: { clear?: boolean } = {}): void {
    if (clear) layer.clearLayers();
    for (const m of markers) {
        const marker = L.circleMarker([m.lat, m.lon], {
            radius: m.radius,
            color: '#2E2A26',
            weight: 1,
            fillColor: m.fillColor,
            fillOpacity: m.fillOpacity,
        });
        if (m.tooltip !== undefined) marker.bindTooltip(m.tooltip, { direction: 'top', offset: [0, -m.radius] });
        marker.bindPopup(m.popup).addTo(layer);
    }
}

export function fitToPoints(map: L.Map, points: Array<[number, number]>, { padding, maxZoom }: FitOptions): void {
    if (!points.length) return;
    map.fitBounds(L.latLngBounds(points), { padding: [padding, padding], maxZoom });
}

export interface MarkerMapSpec {
    markers: PointMarker[];
    /** The points the map is fitted to (the Data Explorer fits to every station, drawn or not). */
    fitPoints: Array<[number, number]>;
    fit: FitOptions;
    /** The Data Explorer fits before it draws; the other pages draw first (kept so the calls match production's). */
    fitFirst?: boolean;
    /** Draw without clearing the layer first (Farmers Markets draws once). */
    appendOnly?: boolean;
}

/** Make the map and put the markers on it, in the order the page's legacy script did. */
export function mountMarkerMap(container: string | HTMLElement, spec: MarkerMapSpec): RegionMapHandle {
    const handle = createMarkerMap(container);
    if (spec.fitFirst) {
        fitToPoints(handle.map, spec.fitPoints, spec.fit);
        drawMarkers(handle.markers, spec.markers, { clear: !spec.appendOnly });
    } else {
        drawMarkers(handle.markers, spec.markers, { clear: !spec.appendOnly });
        fitToPoints(handle.map, spec.fitPoints, spec.fit);
    }
    return handle;
}

/** Public Art: the map was hidden (display: none) and is shown again. Redraw, refit, and let Leaflet measure its box again. */
export function reshowMarkerMap(handle: RegionMapHandle, spec: MarkerMapSpec): void {
    drawMarkers(handle.markers, spec.markers, { clear: true });
    fitToPoints(handle.map, spec.fitPoints, spec.fit);
    invalidateSoon(handle.map);
}

/** Leaflet measures its container once; after the container is shown (or just made) it needs this nudge, a tick later. */
export function invalidateSoon(map: L.Map): ReturnType<typeof setTimeout> {
    return setTimeout(() => map.invalidateSize(), 0);
}

// ---- the Groundwater map: wells that can be selected (port phase 4) --------------------------------------------------------

export type WellMarkers = Map<string, L.CircleMarker>;

/** Production's drawMarkers in groundwater.js: clear, then one marker per well, drawn in the selected style when it is the selected one. */
export function drawWellMarkers(layer: L.LayerGroup, wells: Well[], selectedId: string | null, onSelect: (siteId: string) => void): WellMarkers {
    layer.clearLayers();
    const markers: WellMarkers = new Map();
    for (const w of wells) {
        const marker = L.circleMarker([w.latitude, w.longitude], markerStyle(w, w.site_id === selectedId))
            .bindTooltip(wellTooltip(w), { direction: 'top', offset: [0, -6] })
            .on('click', () => onSelect(w.site_id))
            .addTo(layer);
        markers.set(w.site_id, marker);
    }
    return markers;
}

/** Production's selectWell, map part: restyle every drawn marker and bring the selected one to the front (no redraw). */
export function selectWellMarker(markers: WellMarkers, wells: Well[], selectedId: string): void {
    for (const [id, marker] of markers) {
        const well = wells.find((x) => x.site_id === id) as Well;
        marker.setStyle(markerStyle(well, id === selectedId));
        if (id === selectedId) marker.bringToFront();
    }
}
