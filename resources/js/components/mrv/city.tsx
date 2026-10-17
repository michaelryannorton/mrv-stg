import { ToggleButtons } from '@/components/mrv/toggle-buttons';
import { type ButtonState } from '@/lib/mrv/city';
import { classes } from '@/lib/mrv/classes';
import { type LiveCard, type LiveCardState } from '@/lib/mrv/live';
import flatpickr from 'flatpickr';
import { useEffect, useRef } from 'react';

// Pieces of the City Explorer page (production: city.html and js/city.js, js/live.js).

/** One row of toggle buttons whose on/off and disabled states come from the page's state. */
export function StateButtons({
    buttons,
    attribute,
    onChoose,
    id,
}: {
    buttons: ButtonState[];
    attribute: string;
    onChoose: (key: string) => void;
    id?: string;
}) {
    return (
        <div className="map-controls" id={id}>
            <ToggleButtons
                options={buttons.map((b) => ({ key: b.key, label: b.label, disabled: b.disabled }))}
                active={buttons.filter((b) => b.active).map((b) => b.key)}
                attribute={attribute}
                onChange={onChoose}
                typed={false}
            />
        </div>
    );
}

/** The date-range picker (production: flatpickr in range mode on `#daily-range-input`, typed entry allowed). */
export function DateRangeInput({
    min,
    max,
    start,
    end,
    tick,
    onCommit,
}: {
    min: string;
    max: string;
    start: string;
    end: string;
    /** Changes whenever the page sets its range (production calls setDate each time). */
    tick: number;
    onCommit: (selectedDates: Date[]) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const instance = useRef<flatpickr.Instance | null>(null);
    const commit = useRef(onCommit);
    commit.current = onCommit;

    // Production makes the picker when it has data to draw (not before, and not at all when the data fail to load).
    const enabled = min !== '';
    useEffect(() => {
        if (!input.current || !enabled) return;
        // Wired to change and to close: typed entry does not reliably fire change until the field loses focus.
        const picker = flatpickr(input.current, {
            // Production's calendar is a child of <body>, where its page styles reach it; here the design system's rules (and the
            // inherited type) live on the .mrv-site wrapper, so the calendar goes inside it.
            appendTo: (input.current.closest('.mrv-site') as HTMLElement | null) ?? undefined,
            mode: 'range',
            dateFormat: 'Y-m-d',
            allowInput: true,
            onChange: (dates) => commit.current(dates),
            onClose: (dates) => commit.current(dates),
        });
        instance.current = picker;

        return () => {
            picker.destroy();
            instance.current = null;
        };
    }, [enabled]);

    useEffect(() => {
        instance.current?.set({ minDate: min, maxDate: max });
    }, [min, max]);

    useEffect(() => {
        instance.current?.setDate([start, end], false);
    }, [start, end, tick]);

    return <input ref={input} type="text" id="daily-range-input" className="date-range-input" placeholder="Select dates…" />;
}

/** The year-over-year sentence under the daily chart. */
export function YoyNote({
    yoy,
}: {
    yoy: { kind: 'none' } | { kind: 'delta'; delta: { avgMaxDiff: number; avgMinDiff: number; days: number } } | null;
}) {
    if (!yoy) return null;
    if (yoy.kind === 'none') return <>No overlapping data from last year for this period.</>;
    const { days, avgMaxDiff, avgMinDiff } = yoy.delta;
    const fmt = (v: number) => (v > 0 ? `+${v}` : `${v}`);
    const color = (v: number) => (v >= 0 ? 'var(--color-terracotta-dark)' : 'var(--color-sky-dark)');

    return (
        <>
            Vs. the same period last year ({days} overlapping day{days === 1 ? '' : 's'}): highs averaged{' '}
            <strong style={{ color: color(avgMaxDiff) }}>{fmt(avgMaxDiff)}°F</strong>, lows averaged{' '}
            <strong style={{ color: color(avgMinDiff) }}>{fmt(avgMinDiff)}°F</strong>.
        </>
    );
}

/** The "Right Now" card (production: js/live.js writes it into `#live-conditions`). Text from the API is shown as text. */
export function LiveConditions({ state }: { state: LiveCardState }) {
    if (state.status === 'idle') return null;
    if (state.status === 'loading') return <div className="live-conditions-loading">Checking current conditions…</div>;
    const card: LiveCard = state.card;

    return (
        <div className="card live-conditions">
            <div className="live-conditions-header">
                <span className="stat-label">Right Now</span>
                <span className="live-conditions-time">as of {card.time}</span>
            </div>
            <div className="live-conditions-main">
                <div className="live-conditions-temp">{card.temp}</div>
                <div className="live-conditions-detail">
                    {card.condition ? <div>{card.condition}</div> : null}
                    <div>
                        Humidity {card.humidity} · Wind {card.wind}
                    </div>
                </div>
            </div>
            {card.alert ? <div className={classes('live-alert', `live-alert--${card.alert.severity}`)}>⚠️ {card.alert.text}</div> : null}
        </div>
    );
}
