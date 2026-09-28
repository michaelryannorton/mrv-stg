import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatEventTime } from '@/lib/event-formatting';
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
                <Card key={event.id}>
                    <CardHeader>
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="mb-1 text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]">{formatEventTime(event)}</p>
                                <CardTitle>{event.title}</CardTitle>
                                {event.venue && (
                                    <p className="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        {event.venue.name}
                                        {event.venue.city && `, ${event.venue.city}`}
                                    </p>
                                )}
                            </div>
                            {event.is_free && <Badge>Free</Badge>}
                        </div>
                    </CardHeader>
                    {(event.short_description || event.categories.length > 0) && (
                        <CardContent>
                            {event.short_description && <p className="mb-3 text-sm">{event.short_description}</p>}
                            <div className="flex flex-wrap gap-2">
                                {event.categories.map((c) => (
                                    <Badge key={c.id} variant="secondary">
                                        {c.name}
                                    </Badge>
                                ))}
                                {event.tags.map((t) => (
                                    <Badge key={t.id} variant="outline">
                                        {t.name}
                                    </Badge>
                                ))}
                            </div>
                        </CardContent>
                    )}
                </Card>
            ))}
        </div>
    );
}
