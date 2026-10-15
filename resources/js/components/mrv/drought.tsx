import MrvChart from '@/components/mrv/chart';
import { StatCards } from '@/components/mrv/stat-card';
import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import {
    CATEGORIES,
    cumulative,
    DEFAULT_RANGE,
    droughtChart,
    droughtStats,
    fmtDate,
    RANGES,
    tableHead,
    tableRows,
    visibleRows,
    type DroughtRow,
} from '@/lib/mrv/drought';
import { useMemo, useState } from 'react';

/** The table of the latest twelve weeks, in the cumulative form the Drought Monitor publishes. */
export function DroughtTable({ rows }: { rows: DroughtRow[] }) {
    return (
        <table className="data-table">
            <thead>
                <tr>
                    {tableHead().map((h) => (
                        <th key={h}>{h}</th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {tableRows(rows).map((r) => (
                    <tr key={r.date}>
                        <td>{fmtDate(r.date)}</td>
                        {CATEGORIES.map((c, i) => (
                            <td key={c.key}>{cumulative(r, i)}</td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** The body of the drought section (production: drought.js): statistic cards, time-span buttons, the chart, the latest weeks as a table. */
export function DroughtBody({ rows }: { rows: DroughtRow[] | null }) {
    const [rangeKey, setRangeKey] = useState(DEFAULT_RANGE);
    // Production's page holds this whole body, hidden, from the start; without rows it is the same empty frame.
    const stats = useMemo(() => (rows ? droughtStats(rows) : []), [rows]);
    const config = useMemo(() => (rows ? droughtChart(visibleRows(rows, rangeKey)) : null), [rows, rangeKey]);

    return (
        <>
            <div className="grid-stats grid" id="drought-stats">
                <StatCards cards={stats} />
            </div>
            <div className="control-row" style={{ marginTop: '20px' }}>
                <span className="control-row-label">Time span:</span>
                <div id="drought-range-controls" className="map-controls">
                    {rows ? <ToggleButtons options={RANGES} active={rangeKey} attribute="range" onChange={setRangeKey} /> : null}
                </div>
            </div>
            <div className="card chart-card" style={{ marginTop: '12px' }}>
                <div className="chart-tall">
                    <MrvChart id="chart-drought" config={config} style="stock" />
                </div>
            </div>
            <details style={{ marginTop: '14px' }}>
                <summary>Show the latest 12 weeks as a table</summary>
                <div id="drought-table" className="chart-scroll" style={{ marginTop: '10px' }}>
                    {rows ? <DroughtTable rows={rows} /> : null}
                </div>
            </details>
            <p className="card-note" style={{ maxWidth: '720px', marginTop: '14px' }}>
                This is one figure for the whole county, which covers roughly 20,000 square miles from the San Bernardino Mountains to the Colorado
                River, so it describes the region around the Mojave River Valley, not the valley alone. Each shaded band is the share of the county in
                that category and no worse; the table shows the cumulative form the Drought Monitor publishes ("D1 or worse"). The{' '}
                <a href="https://droughtmonitor.unl.edu/">U.S. Drought Monitor</a> is jointly produced by the National Drought Mitigation Center at
                the University of Nebraska-Lincoln, the United States Department of Agriculture, the National Oceanic and Atmospheric Administration
                and the National Aeronautics and Space Administration. Updated weekly.
            </p>
        </>
    );
}
