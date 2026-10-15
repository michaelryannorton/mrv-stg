import {
    destroyMap,
    drawMarkers,
    initMiniMap,
    initRegionMap,
    invalidateSoon,
    mountMarkerMap,
    renderMarkers,
    reshowMarkerMap,
    type MapPoint,
    type MarkerMapSpec,
    type MarkerValues,
    type PointMarker,
    type RegionMapHandle,
} from '@/lib/mrv/leaflet-map';
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef, type CSSProperties } from 'react';

interface RegionMapViewProps {
    /** The element id production's CSS styles (`map` for the region map). */
    id?: string;
    className?: string;
    points: MapPoint[];
    /** The value shown at each point, by point name (colors and sizes the markers). */
    values: MarkerValues;
    formatPopup?: (point: MapPoint, value: number | null | undefined) => string;
    /** Called after the markers are drawn with the value range, for the legend. */
    onScale?: (scale: { min: number | null; max: number | null }) => void;
}

/**
 * The region map (production: RegionMap.initRegionMap and renderMarkers). The map is created once and fitted to the
 * points at that moment, as on production; markers are redrawn whenever the points or values change.
 */
export function RegionMapView({ id, className, points, values, formatPopup, onScale }: RegionMapViewProps) {
    const element = useRef<HTMLDivElement>(null);
    const handle = useRef<RegionMapHandle | null>(null);
    const latest = useRef({ points, values, formatPopup, onScale });
    latest.current = { points, values, formatPopup, onScale };

    useEffect(() => {
        if (!element.current) return;
        handle.current = initRegionMap(element.current, latest.current.points);
        const scale = renderMarkers(handle.current.markers, latest.current.points, latest.current.values, {
            formatPopup: latest.current.formatPopup,
        });
        latest.current.onScale?.(scale);

        return () => {
            destroyMap(handle.current?.map);
            handle.current = null;
        };
    }, []);

    useEffect(() => {
        if (!handle.current) return;
        const scale = renderMarkers(handle.current.markers, points, values, { formatPopup });
        onScale?.(scale);
        // `onScale` is deliberately not a dependency: it only reports the range after a redraw.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [points, values, formatPopup]);

    return <div ref={element} id={id} className={className} />;
}

interface MiniMapViewProps {
    /** The element id production's CSS styles (`city-mini-map`). */
    id?: string;
    className?: string;
    lat: number | null;
    lon: number | null;
    label: string;
}

/** The single-city locator (production: RegionMap.initMiniMap): no controls, no dragging, one labelled marker. */
export function MiniMapView({ id, className, lat, lon, label }: MiniMapViewProps) {
    const element = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!element.current) return;
        const map = initMiniMap(element.current, lat, lon, label);

        return () => destroyMap(map);
    }, [lat, lon, label]);

    return <div ref={element} id={id} className={className} />;
}

interface MarkerMapViewProps extends MarkerMapSpec {
    id?: string;
    className?: string;
    style?: CSSProperties;
    /** Redraw, refit and `invalidateSize` each time `active` turns true (Public Art hides its map with display:none). */
    refitOnShow?: boolean;
    active?: boolean;
}

/**
 * The map of a directory page (production: stations.js, markets.js, art.js). Created once on mount; the markers are
 * redrawn when `markers` changes (the Data Explorer's legend switches), and with `refitOnShow` the map is redrawn
 * and refitted whenever it becomes visible again. Pages should memoize `markers` and `fitPoints`.
 */
export function MarkerMapView({ id, className, style, refitOnShow = false, active = true, ...spec }: MarkerMapViewProps) {
    const element = useRef<HTMLDivElement>(null);
    const handle = useRef<RegionMapHandle | null>(null);
    const latest = useRef<MarkerMapSpec>(spec);
    latest.current = spec;
    const drawn = useRef<PointMarker[] | null>(null);
    const wasActive = useRef(active);

    useEffect(() => {
        if (!element.current) return;
        handle.current = mountMarkerMap(element.current, latest.current);
        drawn.current = latest.current.markers;
        const map = handle.current.map;
        const timer = refitOnShow ? invalidateSoon(map) : undefined;

        return () => {
            if (timer) clearTimeout(timer);
            destroyMap(map);
            handle.current = null;
            drawn.current = null;
        };
        // The map is made once; later changes arrive through the effects below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // New markers (the Data Explorer's legend switches): redraw them; the view does not move.
    useEffect(() => {
        if (handle.current && !refitOnShow && drawn.current !== spec.markers) {
            drawMarkers(handle.current.markers, spec.markers, { clear: true });
            drawn.current = spec.markers;
        }
    }, [spec.markers, refitOnShow]);

    // Public Art: the map is shown again after being hidden.
    useEffect(() => {
        if (refitOnShow && active && !wasActive.current && handle.current) {
            reshowMarkerMap(handle.current, latest.current);
            drawn.current = latest.current.markers;
        }
        wasActive.current = active;
    }, [active, refitOnShow]);

    return <div ref={element} id={id} className={className} style={style} />;
}
