import { type DaysByCategory } from '@/lib/mrv/air-quality';

/** The stacked bar and legend of the share of days in each AQI category (production: plain HTML, so every label is real text). */
export function DaysBar({ days }: { days: DaysByCategory }) {
    return (
        <>
            {days.segments.map((s) => (
                <span
                    key={s.band.name}
                    className="aqi-days-seg"
                    style={{ flexBasis: `${s.pct}%`, background: s.band.color, color: s.band.ink }}
                    title={s.title}
                >
                    {s.text}
                </span>
            ))}
        </>
    );
}

export function DaysLegend({ days }: { days: DaysByCategory }) {
    return (
        <>
            {days.legend.map((l) => (
                <li key={l.band.name}>
                    <span className="swatch" style={{ background: l.band.color }}></span>
                    <span className="aqi-days-name">{l.band.name}</span>
                    <span className="aqi-days-count">{l.text}</span>
                </li>
            ))}
        </>
    );
}
