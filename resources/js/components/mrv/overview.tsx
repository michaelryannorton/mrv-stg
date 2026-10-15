import { type legendSteps } from '@/lib/mrv/overview';

/** The legend under the Overview's map: the metric's name, then each color step with the lower bound it starts at. */
export function MetricLegend({ legend }: { legend: NonNullable<ReturnType<typeof legendSteps>> }) {
    return (
        <>
            <span>{legend.label}</span>{' '}
            {legend.steps.map((s) => (
                <span key={s.color}>
                    <span className="swatch" style={{ background: s.color }}></span>
                    {s.text}
                </span>
            ))}
        </>
    );
}
