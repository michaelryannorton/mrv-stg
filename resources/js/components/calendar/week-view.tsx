import { dateRangeDays, dayNumber, groupEventsByRange, weekdayLabel } from '@/lib/calendar';
import { formatEventTime } from '@/lib/event-formatting';
import { classes } from '@/lib/mrv/classes';
import { type EventItem } from '@/types/event';
import { Link } from '@inertiajs/react';

interface WeekViewProps {
    gridStart: string;
    gridEnd: string;
    today: string;
    events: EventItem[];
    dayHref: (date: string) => string;
}

export default function WeekView({ gridStart, gridEnd, today, events, dayHref }: WeekViewProps) {
    const days = dateRangeDays(gridStart, gridEnd);
    const byDate = groupEventsByRange(events, gridStart, gridEnd);

    return (
        <div className="cal-week">
            {days.map((date) => {
                const dayEvents = byDate.get(date) ?? [];

                return (
                    <div key={date} className={classes('cal-day', date === today && 'today')}>
                        <Link href={dayHref(date)} className="cal-day-link">
                            {weekdayLabel(date)} {dayNumber(date)}
                        </Link>

                        <div className="cal-items">
                            {dayEvents.length === 0 ? (
                                <p className="cal-none">—</p>
                            ) : (
                                dayEvents.map((event) => (
                                    <div key={event.id} className="cal-item">
                                        <span className="cal-time">{formatEventTime(event)}</span> <strong>{event.title}</strong>
                                        {event.is_free && <span className="event-badge">Free</span>}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
