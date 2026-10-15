import { classes } from '@/lib/mrv/classes';
import {
    areaCounts,
    AREAS,
    describeChange,
    fmtDate,
    fmtDepth,
    STATUS,
    STATUS_ORDER,
    statusCounts,
    type Well,
    type WellStatus,
} from '@/lib/mrv/groundwater';

// The controls and table of production's groundwater.js, as React (class names and structure are production's).

export function AreaButtons({ wells, area, onChange }: { wells: Well[]; area: string; onChange: (area: string) => void }) {
    const counts = areaCounts(wells);

    return (
        <>
            {Object.keys(AREAS).map((key) => (
                <button
                    key={key}
                    type="button"
                    className={classes('btn-toggle', key === area && 'active')}
                    data-area={key}
                    onClick={() => key !== area && onChange(key)}
                >
                    {AREAS[key]} ({counts[key] || 0})
                </button>
            ))}
        </>
    );
}

export function StatusLegend({
    wells,
    area,
    active,
    onToggle,
}: {
    wells: Well[];
    area: string;
    active: Set<WellStatus>;
    onToggle: (status: WellStatus) => void;
}) {
    const counts = statusCounts(wells, area);

    return (
        <>
            {STATUS_ORDER.map((key) => {
                const s = STATUS[key];

                return (
                    <button
                        key={key}
                        type="button"
                        className={classes('legend-toggle', !active.has(key) && 'off')}
                        data-status={key}
                        title={s.note}
                        onClick={() => onToggle(key)}
                    >
                        {s.hollow ? (
                            <span
                                className="swatch"
                                style={{ background: 'transparent', border: `2px solid ${s.color}`, boxSizing: 'border-box' }}
                            ></span>
                        ) : (
                            <span className="swatch" style={{ background: s.color }}></span>
                        )}
                        {s.label} ({counts[key] || 0})
                    </button>
                );
            })}
        </>
    );
}

export function ChangesTable({ wells, onSelect }: { wells: Well[]; onSelect: (siteId: string) => void }) {
    return (
        <table className="data-table">
            <thead>
                <tr>
                    <th>Well</th>
                    <th>Change</th>
                    <th>Then</th>
                    <th>Now</th>
                </tr>
            </thead>
            <tbody>
                {wells.map((w) => (
                    <tr
                        key={w.site_id}
                        data-site={w.site_id}
                        tabIndex={0}
                        style={{ cursor: 'pointer' }}
                        onClick={() => onSelect(w.site_id)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                onSelect(w.site_id);
                            }
                        }}
                    >
                        <td>{w.name}</td>
                        <td>
                            {describeChange(w.change_ft)} ({STATUS[w.status].label.toLowerCase()})
                        </td>
                        <td>
                            {fmtDepth(w.compare_depth_ft as number)} · {fmtDate(w.compare_date as string)}
                        </td>
                        <td>
                            {fmtDepth(w.last_depth_ft as number)} · {fmtDate(w.last_date as string)}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
