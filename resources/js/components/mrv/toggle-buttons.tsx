import { classes } from '@/lib/mrv/classes';

/**
 * A row of production's `.btn-toggle` buttons with a `data-*` attribute naming each (the Earthquake, Groundwater, Overview
 * and drought controls all follow this pattern). Production's Overview buttons carry no `type` attribute; `typed` is false there.
 */
export function ToggleButtons({
    options,
    active,
    attribute,
    onChange,
    typed = true,
}: {
    options: Array<{ key: string; label: string; disabled?: boolean }>;
    /** The chosen key, or several (the year comparison lets up to six be on at once). */
    active: string | string[];
    /** The data attribute's name without `data-` (`window` gives `data-window`). */
    attribute: string;
    onChange: (key: string) => void;
    typed?: boolean;
}) {
    return (
        <>
            {options.map((o) => (
                <button
                    key={o.key}
                    {...(typed ? { type: 'button' as const } : {})}
                    className={classes('btn-toggle', (Array.isArray(active) ? active.includes(o.key) : o.key === active) && 'active')}
                    {...{ [`data-${attribute}`]: o.key }}
                    {...(o.disabled !== undefined ? { disabled: o.disabled } : {})}
                    onClick={() => onChange(o.key)}
                >
                    {o.label}
                </button>
            ))}
        </>
    );
}
