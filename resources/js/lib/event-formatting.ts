interface TimedEvent {
    start_at: string;
    timezone: string;
    all_day: boolean;
}

/**
 * Always renders in the event's own local timezone, not the viewer's browser timezone — a concert
 * at 7pm Pacific should read as 7pm to every visitor, wherever they're browsing from.
 */
export function formatWhen(event: TimedEvent): string {
    const date = new Date(event.start_at);
    const timeZone = event.timezone || 'America/Los_Angeles';

    if (event.all_day) {
        return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', timeZone });
    }

    return date.toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}

/** Just the time-of-day portion, for calendar-grid rows that already show the date via their cell/column. */
export function formatEventTime(event: TimedEvent): string {
    if (event.all_day) return 'All day';

    const timeZone = event.timezone || 'America/Los_Angeles';
    return new Date(event.start_at).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', timeZone });
}
