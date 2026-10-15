import { dateRangeDays, dayNumber, groupEventsByRange, isSameMonth, weekdayLabel } from '@/lib/calendar';
import { formatEventTime } from '@/lib/event-formatting';
import { classes } from '@/lib/mrv/classes';
import { type EventItem } from '@/types/event';
import { Link } from '@inertiajs/react';

interface MonthViewProps {
    anchorDate: string;
    gridStart: string;
    gridEnd: string;
    today: string;
    events: EventItem[];
    dayHref: (date: string) => string;
}

const MAX_VISIBLE_PER_DAY = 3;

export default function MonthView({ anchorDate, gridStart, gridEnd, today, events, dayHref }: MonthViewProps) {
    const days = dateRangeDays(gridStart, gridEnd);
    const byDate = groupEventsByRange(events, gridStart, gridEnd);
    const weekdayHeaders = days.slice(0, 7).map((d) => weekdayLabel(d));

    return (
        <div className="cal-month">
            <div className="cal-month-head">
                {weekdayHeaders.map((label, i) => (
                    <div key={i}>{label}</div>
                ))}
            </div>

            <div className="cal-month-grid">
                {days.map((date) => {
                    const dayEvents = byDate.get(date) ?? [];
                    const overflow = dayEvents.length - MAX_VISIBLE_PER_DAY;

                    return (
                        <div key={date} className={classes('cal-cell', !isSameMonth(date, anchorDate) && 'outside')}>
                            <Link href={dayHref(date)} className={classes('cal-date', date === today && 'today')}>
                                {dayNumber(date)}
                            </Link>

                            {dayEvents.slice(0, MAX_VISIBLE_PER_DAY).map((event) => (
                                <div key={event.id} className="cal-cell-item" title={event.title}>
                                    <span className="cal-time">{formatEventTime(event)}</span> {event.title}
                                </div>
                            ))}
                            {overflow > 0 && (
                                <Link href={dayHref(date)} className="cal-more">
                                    +{overflow} more
                                </Link>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
