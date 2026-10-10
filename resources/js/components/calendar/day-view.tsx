import EventCard from '@/components/event-card';
import { type EventItem } from '@/types/event';

interface DayViewProps {
    events: EventItem[];
}

export default function DayView({ events }: DayViewProps) {
    if (events.length === 0) {
        return (
            <p className="rounded-md border border-dashed border-[#e3e3e0] p-8 text-center text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                No events on this day.
            </p>
        );
    }

    return (
        <div className="grid gap-4">
            {events.map((event) => (
                <EventCard key={event.id} event={event} layout="day" />
            ))}
        </div>
    );
}
