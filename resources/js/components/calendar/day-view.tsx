import EventCard from '@/components/event-card';
import { type EventItem } from '@/types/event';

interface DayViewProps {
    events: EventItem[];
}

export default function DayView({ events }: DayViewProps) {
    if (events.length === 0) {
        return (
            <div className="card empty-card">
                <p className="card-note">No events on this day.</p>
            </div>
        );
    }

    return (
        <div className="event-list">
            {events.map((event) => (
                <EventCard key={event.id} event={event} layout="day" />
            ))}
        </div>
    );
}
