import { Badge } from '@/components/ui/badge';
import { dateRangeDays, dayNumber, groupEventsByRange, weekdayLabel } from '@/lib/calendar';
import { formatEventTime } from '@/lib/event-formatting';
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
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-7">
            {days.map((date) => {
                const dayEvents = byDate.get(date) ?? [];
                const isToday = date === today;

                return (
                    <div
                        key={date}
                        className={`flex flex-col gap-2 rounded-md border p-2 ${
                            isToday ? 'border-[#1b1b18] dark:border-[#EDEDEC]' : 'border-[#e3e3e0] dark:border-[#3E3E3A]'
                        }`}
                    >
                        <Link href={dayHref(date)} className="text-sm font-medium hover:underline">
                            {weekdayLabel(date)} {dayNumber(date)}
                        </Link>

                        <div className="flex flex-col gap-1.5">
                            {dayEvents.length === 0 ? (
                                <p className="text-xs text-[#706f6c] dark:text-[#A1A09A]">—</p>
                            ) : (
                                dayEvents.map((event) => (
                                    <div key={event.id} className="text-xs">
                                        <span className="text-[#706f6c] dark:text-[#A1A09A]">{formatEventTime(event)}</span>{' '}
                                        <span className="font-medium">{event.title}</span>
                                        {event.is_free && (
                                            <Badge variant="secondary" className="ml-1 px-1 py-0 text-[10px]">
                                                Free
                                            </Badge>
                                        )}
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
