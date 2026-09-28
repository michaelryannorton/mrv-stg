/** Every calendar date (Y-m-d) from `startISO` to `endISO`, inclusive. Both are UTC-parsed at midnight purely as a sequencing device — these are grid labels, not instants, so browser-local timezone shifting is irrelevant here. */
export function dateRangeDays(startISO: string, endISO: string): string[] {
    const days: string[] = [];
    let cursor = new Date(`${startISO}T00:00:00Z`);
    const end = new Date(`${endISO}T00:00:00Z`);

    while (cursor <= end) {
        days.push(cursor.toISOString().slice(0, 10));
        cursor = new Date(cursor.getTime() + 86_400_000);
    }

    return days;
}

/** The Y-m-d calendar date an event's start_at falls on, in the event's own timezone — matches formatWhen's convention so a grid cell and its event list always agree on "which day" an event is. */
export function eventLocalDate(event: { start_at: string; timezone: string }): string {
    return new Date(event.start_at).toLocaleDateString('en-CA', { timeZone: event.timezone || 'America/Los_Angeles' });
}

/**
 * Buckets each event onto every calendar day it spans within [gridStart, gridEnd] (inclusive), not
 * just its start day — a multi-day event should visibly continue across each day of a week/month
 * grid, and must still show up on a visible day even if it started before the grid's first cell.
 * Each bucket's list stays in the order events were passed in (callers pass events pre-sorted by
 * the backend's `orderBy('start_at')`).
 */
export function groupEventsByRange<T extends { start_at: string; end_at: string | null; timezone: string }>(
    events: T[],
    gridStart: string,
    gridEnd: string,
): Map<string, T[]> {
    const map = new Map<string, T[]>();

    for (const event of events) {
        const timeZone = event.timezone || 'America/Los_Angeles';
        const startDate = eventLocalDate(event);
        const endDate = event.end_at ? new Date(event.end_at).toLocaleDateString('en-CA', { timeZone }) : startDate;

        const from = startDate < gridStart ? gridStart : startDate;
        const to = endDate > gridEnd ? gridEnd : endDate;
        if (to < from) continue;

        for (const day of dateRangeDays(from, to)) {
            const bucket = map.get(day);
            if (bucket) bucket.push(event);
            else map.set(day, [event]);
        }
    }

    return map;
}

export function formatDayLabel(dateISO: string): string {
    return new Date(`${dateISO}T00:00:00Z`).toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

export function formatWeekRangeLabel(startISO: string, endISO: string): string {
    const start = new Date(`${startISO}T00:00:00Z`);
    const end = new Date(`${endISO}T00:00:00Z`);
    const sameMonth = start.getUTCMonth() === end.getUTCMonth() && start.getUTCFullYear() === end.getUTCFullYear();

    const startLabel = start.toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' });
    const endLabel = end.toLocaleDateString(
        undefined,
        sameMonth ? { day: 'numeric', year: 'numeric', timeZone: 'UTC' } : { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' },
    );

    return `${startLabel} – ${endLabel}`;
}

export function formatMonthLabel(dateISO: string): string {
    return new Date(`${dateISO}T00:00:00Z`).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
}

export function dayNumber(dateISO: string): number {
    return new Date(`${dateISO}T00:00:00Z`).getUTCDate();
}

export function weekdayLabel(dateISO: string, format: 'short' | 'long' = 'short'): string {
    return new Date(`${dateISO}T00:00:00Z`).toLocaleDateString(undefined, { weekday: format, timeZone: 'UTC' });
}

export function isSameMonth(dateISO: string, monthAnchorISO: string): boolean {
    const d = new Date(`${dateISO}T00:00:00Z`);
    const m = new Date(`${monthAnchorISO}T00:00:00Z`);
    return d.getUTCMonth() === m.getUTCMonth() && d.getUTCFullYear() === m.getUTCFullYear();
}
