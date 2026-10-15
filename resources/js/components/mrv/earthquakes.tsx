import { formatEventTime, legendStops, magColor, type QuakeEvent } from '@/lib/mrv/earthquakes';

// The legend and the list of production's earthquakes.js, as React (class names and structure are production's).

export function QuakeLegend({ min, max }: { min: number | null; max: number | null }) {
    if (min == null || max == null) return null;

    return (
        <div className="magnitude-legend">
            <span className="magnitude-legend-label">M{min.toFixed(1)}</span>
            <div className="magnitude-legend-gradient" style={{ background: `linear-gradient(to right, ${legendStops(min, max).join(', ')})` }}></div>
            <span className="magnitude-legend-label">M{max.toFixed(1)}</span>
        </div>
    );
}

export function QuakeRows({ events, min, max }: { events: QuakeEvent[]; min: number | null; max: number | null }) {
    return (
        <>
            {events.map((e, i) => {
                const mag = e.magnitude != null ? Number(e.magnitude).toFixed(1) : '?';
                const depth = e.depth_km != null ? `${Number(e.depth_km).toFixed(1)} km` : '—';

                return (
                    <div key={i} className="quake-row">
                        <span className="quake-mag" style={{ background: magColor(e.magnitude != null ? Number(e.magnitude) : null, min, max) }}>
                            M{mag}
                        </span>
                        <span className="quake-place">{e.place || 'Unknown location'}</span>
                        <span className="quake-time card-note">{formatEventTime(e.event_time)}</span>
                        <span className="quake-depth card-note">{depth}</span>
                    </div>
                );
            })}
        </>
    );
}
