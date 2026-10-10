import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatEventTime, formatWhen } from '@/lib/event-formatting';
import { type EventItem } from '@/types/event';

interface EventCardProps {
    event: EventItem;
    /** 'list' puts the date and time under the title; 'day' leads with the time, since the date is already the page heading. */
    layout?: 'list' | 'day';
}

export default function EventCard({ event, layout = 'list' }: EventCardProps) {
    const place = event.venue && `${event.venue.name}${event.venue.city ? `, ${event.venue.city}` : ''}`;

    return (
        <Card>
            <CardHeader>
                <div className="flex items-start justify-between gap-4">
                    <div>
                        {layout === 'day' && <p className="mb-1 text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]">{formatEventTime(event)}</p>}
                        <CardTitle>{event.title}</CardTitle>
                        {layout === 'list' ? (
                            <p className="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                {formatWhen(event)}
                                {place && ` · ${place}`}
                            </p>
                        ) : (
                            place && <p className="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">{place}</p>
                        )}
                    </div>
                    {event.is_free && <Badge>Free</Badge>}
                </div>
            </CardHeader>
            {(event.short_description || event.categories.length > 0 || event.details_url) && (
                <CardContent>
                    {event.short_description && <p className="mb-3 text-sm">{event.short_description}</p>}
                    <div className="flex flex-wrap items-center gap-2">
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
                        {event.details_url && (
                            <a
                                href={event.details_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="ml-auto text-sm underline underline-offset-4"
                                aria-label={`Details for ${event.title} (opens the organizer's page in a new tab)`}
                            >
                                Details
                            </a>
                        )}
                    </div>
                </CardContent>
            )}
        </Card>
    );
}
