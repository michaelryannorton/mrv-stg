import { statusText, typeMeta, typesPresent, VIEW_OPTIONS, type ArtEntry, type ArtView } from '@/lib/mrv/art';
import { classes } from '@/lib/mrv/classes';

// The three views of production's art.js, as React (class names and structure are production's).

export function ArtViewTabs({ view, onChange }: { view: ArtView; onChange: (view: ArtView) => void }) {
    return (
        <>
            {VIEW_OPTIONS.map((o) => (
                <button
                    key={o.key}
                    type="button"
                    className={classes('btn-toggle', o.key === view && 'active')}
                    data-view={o.key}
                    onClick={() => onChange(o.key)}
                >
                    {o.label}
                </button>
            ))}
        </>
    );
}

export function ArtCards({ art }: { art: ArtEntry[] }) {
    return (
        <>
            {art.map((a) => {
                const meta = typeMeta(a.art_type);
                return (
                    <div key={a.slug} className="card art-card">
                        <div className="art-eyebrow" style={{ color: meta.color }}>
                            {meta.label} · {a.city}
                        </div>
                        <h3>{a.title}</h3>
                        <p className="card-note">
                            {a.venue_name}
                            {a.year_created ? ` · ${a.year_created}` : ''}
                        </p>
                        <p>{a.description}</p>
                        <p className="card-note">{a.materials}</p>
                        {a.status === 'active' ? null : <span className="art-status-note">{statusText(a.status)}</span>}
                    </div>
                );
            })}
        </>
    );
}

export function ArtTable({ art }: { art: ArtEntry[] }) {
    return (
        <table className="art-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Title</th>
                    <th>City</th>
                    <th>Venue</th>
                    <th>Year</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                {art.map((a) => {
                    const meta = typeMeta(a.art_type);
                    return (
                        <tr key={a.slug}>
                            <td>
                                <span className="table-type-swatch" style={{ background: meta.color }}></span>
                                {meta.label}
                            </td>
                            <td>{a.title}</td>
                            <td>{a.city}</td>
                            <td>{a.venue_name}</td>
                            <td>{a.year_created || '—'}</td>
                            <td>{a.status === 'active' ? '—' : statusText(a.status)}</td>
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

export function ArtLegend({ art }: { art: ArtEntry[] }) {
    return (
        <>
            {typesPresent(art).map((t) => {
                const meta = typeMeta(t);
                return (
                    <span key={t} className="map-legend-item">
                        <span className="swatch" style={{ background: meta.color }}></span>
                        {meta.label}
                    </span>
                );
            })}
        </>
    );
}
