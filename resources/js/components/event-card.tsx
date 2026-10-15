import { formatEventTime, formatWhen } from '@/lib/event-formatting';
import { type EventItem } from '@/types/event';

interface EventCardProps {
    event: EventItem;
    /** 'list' puts the date and time under the title; 'day' leads with the time, since the date is already the page heading. */
    layout?: 'list' | 'day';
}

/** One event in a list, built from the public site's card (resources/css/mrv.css `.card`) with the few additions in mrv-port.css. */
export default function EventCard({ event, layout = 'list' }: EventCardProps) {
    const place = event.venue && `${event.venue.name}${event.venue.city ? `, ${event.venue.city}` : ''}`;

    return (
        <article className="card event-card">
            <div className="event-card-head">
                <div>
                    {layout === 'day' && <p className="event-time">{formatEventTime(event)}</p>}
                    <h3>{event.title}</h3>
                    {layout === 'list' ? (
                        <p className="card-note">
                            {formatWhen(event)}
                            {place && ` · ${place}`}
                        </p>
                    ) : (
                        place && <p className="card-note">{place}</p>
                    )}
                </div>
                <div className="event-badges">
                    {event.is_pick && <span className="event-badge pick">Curator's pick</span>}
                    {event.is_free && <span className="event-badge free">Free</span>}
                </div>
            </div>
            {(event.short_description || event.categories.length > 0 || event.details_url) && (
                <>
                    {event.short_description && <p className="event-body">{event.short_description}</p>}
                    <div className="event-meta">
                        {event.categories.map((c) => (
                            <span key={c.id} className="market-tag">
                                {c.name}
                            </span>
                        ))}
                        {event.tags.map((t) => (
                            <span key={t.id} className="market-tag">
                                {t.name}
                            </span>
                        ))}
                        {event.details_url && (
                            <a
                                href={event.details_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="event-details-link"
                                aria-label={`Details for ${event.title} (opens the organizer's page in a new tab)`}
                            >
                                Details
                            </a>
                        )}
                    </div>
                </>
            )}
        </article>
    );
}
