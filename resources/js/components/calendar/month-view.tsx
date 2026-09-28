import { dateRangeDays, dayNumber, groupEventsByRange, isSameMonth, weekdayLabel } from '@/lib/calendar';
import { formatEventTime } from '@/lib/event-formatting';
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
        <div className="overflow-hidden rounded-md border border-[#e3e3e0] dark:border-[#3E3E3A]">
            <div className="grid grid-cols-7 border-b border-[#e3e3e0] text-center text-xs font-medium text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                {weekdayHeaders.map((label, i) => (
                    <div key={i} className="py-2">
                        {label}
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-7">
                {days.map((date) => {
                    const dayEvents = byDate.get(date) ?? [];
                    const inMonth = isSameMonth(date, anchorDate);
                    const isToday = date === today;
                    const overflow = dayEvents.length - MAX_VISIBLE_PER_DAY;

                    return (
                        <div
                            key={date}
                            className={`flex min-h-[6rem] flex-col gap-1 border-r border-b border-[#e3e3e0] p-1.5 last:border-r-0 dark:border-[#3E3E3A] ${
                                inMonth ? '' : 'bg-[#FAFAF9] dark:bg-[#111110]'
                            }`}
                        >
                            <Link
                                href={dayHref(date)}
                                className={`self-start rounded-full px-1.5 text-xs ${
                                    isToday
                                        ? 'bg-[#1b1b18] text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                                        : inMonth
                                          ? 'hover:underline'
                                          : 'text-[#706f6c] hover:underline dark:text-[#A1A09A]'
                                }`}
                            >
                                {dayNumber(date)}
                            </Link>

                            <div className="flex flex-col gap-0.5">
                                {dayEvents.slice(0, MAX_VISIBLE_PER_DAY).map((event) => (
                                    <div key={event.id} className="truncate text-[11px] leading-tight" title={event.title}>
                                        <span className="text-[#706f6c] dark:text-[#A1A09A]">{formatEventTime(event)}</span> {event.title}
                                    </div>
                                ))}
                                {overflow > 0 && (
                                    <Link href={dayHref(date)} className="text-[11px] text-[#706f6c] hover:underline dark:text-[#A1A09A]">
                                        +{overflow} more
                                    </Link>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
