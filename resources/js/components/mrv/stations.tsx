import { classes } from '@/lib/mrv/classes';
import { networkLegend, networkMeta, type Station } from '@/lib/mrv/stations';

/** The legend of the Data Explorer: one toggle per network present, with its count (production: stations.js renderLegend). */
export function StationLegend({ stations, active, onToggle }: { stations: Station[]; active: Set<string>; onToggle: (network: string) => void }) {
    return (
        <>
            {networkLegend(stations).map(({ network, count }) => {
                const meta = networkMeta(network);
                return (
                    <button
                        key={network}
                        type="button"
                        className={classes('legend-toggle', !active.has(network) && 'off')}
                        data-network={network}
                        onClick={() => onToggle(network)}
                    >
                        <span className="swatch" style={{ background: meta.color }}></span>
                        {meta.label} ({count})
                    </button>
                );
            })}
        </>
    );
}
