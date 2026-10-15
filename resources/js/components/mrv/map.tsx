import { type Well } from '@/lib/mrv/groundwater';
import {
    createMarkerMap,
    destroyMap,
    drawMarkers,
    drawWellMarkers,
    fitToPoints,
    initMiniMap,
    initRegionMap,
    invalidateSoon,
    mountMarkerMap,
    renderMarkers,
    reshowMarkerMap,
    selectWellMarker,
    type MapPoint,
    type MarkerMapSpec,
    type MarkerValues,
    type PointMarker,
    type RegionMapHandle,
    type WellMarkers,
} from '@/lib/mrv/leaflet-map';
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef, type CSSProperties, type MutableRefObject } from 'react';

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
    /** Refit the view to `fitPoints` every time the markers are redrawn (the Earthquake page does, when it has any). */
    refitOnChange?: boolean;
    /** Gives the page the map's own element (the Earthquake page writes its failure message into it, as production does). */
    elementRef?: MutableRefObject<HTMLDivElement | null>;
}

/**
 * The map of a directory page (production: stations.js, markets.js, art.js). Created once on mount; the markers are
 * redrawn when `markers` changes (the Data Explorer's legend switches), and with `refitOnShow` the map is redrawn
 * and refitted whenever it becomes visible again. Pages should memoize `markers` and `fitPoints`.
 */
export function MarkerMapView({
    id,
    className,
    style,
    refitOnShow = false,
    active = true,
    refitOnChange = false,
    elementRef,
    ...spec
}: MarkerMapViewProps) {
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
            if (refitOnChange) fitToPoints(handle.current.map, latest.current.fitPoints, latest.current.fit);
        }
    }, [spec.markers, refitOnShow, refitOnChange]);

    // Public Art: the map is shown again after being hidden.
    useEffect(() => {
        if (refitOnShow && active && !wasActive.current && handle.current) {
            reshowMarkerMap(handle.current, latest.current);
            drawn.current = latest.current.markers;
        }
        wasActive.current = active;
    }, [active, refitOnShow]);

    return (
        <div
            ref={(node) => {
                element.current = node;
                if (elementRef) elementRef.current = node;
            }}
            id={id}
            className={className}
            style={style}
        />
    );
}

interface WellMapProps {
    id?: string;
    style?: CSSProperties;
    /** Every well (to find a marker's well). */
    wells: Well[];
    /** The wells to draw: the area's wells whose status is switched on. */
    drawn: Well[];
    /** The area's wells, which the view is fitted to each time `fitKey` changes (and once at the start). */
    fitWells: Well[];
    fitKey: number;
    selectedId: string | null;
    onSelect: (siteId: string) => void;
}

/**
 * The Groundwater map (production: groundwater.js). Made once, fitted to the area's wells at the start and whenever the
 * area changes; markers are redrawn when the drawn wells change (the legend switches); choosing a well restyles the
 * existing markers and brings the chosen one to the front, as production does.
 */
export function WellMap({ id, style, wells, drawn, fitWells, fitKey, selectedId, onSelect }: WellMapProps) {
    const element = useRef<HTMLDivElement>(null);
    const handle = useRef<RegionMapHandle | null>(null);
    const markers = useRef<WellMarkers>(new Map());
    const latest = useRef({ wells, drawn, fitWells, selectedId, onSelect });
    latest.current = { wells, drawn, fitWells, selectedId, onSelect };
    const mounted = useRef({ fitKey, drawn, selectedId });

    const fit = () => {
        if (handle.current && latest.current.fitWells.length) {
            fitToPoints(
                handle.current.map,
                latest.current.fitWells.map((w) => [w.latitude, w.longitude] as [number, number]),
                { padding: 24, maxZoom: 11 },
            );
        }
    };
    const draw = () => {
        if (!handle.current) return;
        markers.current = drawWellMarkers(handle.current.markers, latest.current.drawn, latest.current.selectedId, (siteId) =>
            latest.current.onSelect(siteId),
        );
    };

    useEffect(() => {
        if (!element.current) return;
        handle.current = createMarkerMap(element.current, { center: [34.5, -117.2], zoom: 9 });
        fit();
        draw();

        return () => {
            destroyMap(handle.current?.map);
            handle.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // The area changed: refit first, then redraw (the order production uses).
    useEffect(() => {
        if (mounted.current.fitKey !== fitKey) {
            mounted.current.fitKey = fitKey;
            fit();
        }
    }, [fitKey]);

    useEffect(() => {
        if (mounted.current.drawn !== drawn) {
            mounted.current.drawn = drawn;
            draw();
        }
    }, [drawn]);

    useEffect(() => {
        if (mounted.current.selectedId !== selectedId) {
            mounted.current.selectedId = selectedId;
            if (selectedId) selectWellMarker(markers.current, latest.current.wells, selectedId);
        }
    }, [selectedId]);

    return <div ref={element} id={id} style={style} />;
}
