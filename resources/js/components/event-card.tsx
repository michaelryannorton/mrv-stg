import EventDescription from '@/components/event-description';
import { formatEventTime, formatWhen } from '@/lib/event-formatting';
import { type EventItem } from '@/types/event';
import { useId, useState } from 'react';

interface EventCardProps {
    event: EventItem;
    /** 'list' puts the date and time under the title; 'day' leads with the time, since the date is already the page heading. */
    layout?: 'list' | 'day';
}

/** One event in a list, built from the public site's card (resources/css/mrv.css `.card`) with the few additions in mrv-port.css. */
export default function EventCard({ event, layout = 'list' }: EventCardProps) {
    const [open, setOpen] = useState(false);
    const descriptionId = useId();
    // "Show more" is offered only when the description holds more than the excerpt already shows.
    const hasMore = !!event.description && event.description.replace(/\s+/g, ' ') !== event.excerpt;

    return (
        <article className="card event-card">
            <div className="event-card-head">
                <div>
                    {layout === 'day' && <p className="event-time">{formatEventTime(event)}</p>}
                    <h3>{event.title}</h3>
                    {layout === 'list' && <p className="card-note">{formatWhen(event)}</p>}
                    {event.place && <p className="card-note">{event.place}</p>}
                </div>
                <div className="event-badges">
                    {event.is_pick && <span className="event-badge pick">Curator's pick</span>}
                    {event.is_free && <span className="event-badge free">Free</span>}
                </div>
            </div>

            {event.excerpt && !open && <p className="event-body">{event.excerpt}</p>}
            {hasMore && (
                <>
                    <div id={descriptionId} className="event-body event-description" hidden={!open}>
                        {open && <EventDescription text={event.description as string} />}
                    </div>
                    <button type="button" className="event-more" aria-expanded={open} aria-controls={descriptionId} onClick={() => setOpen(!open)}>
                        {open ? 'Show less' : 'Show more'}
                        <span className="sr-only"> about {event.title}</span>
                    </button>
                </>
            )}
            {event.check_eligibility && <p className="card-note event-eligibility">Check eligibility with the organizer.</p>}

            {(event.categories.length > 0 || event.tags.length > 0 || event.link) && (
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
                    {event.link && (
                        <a href={event.link.url} target="_blank" rel="noopener noreferrer" className="event-details-link">
                            {event.link.kind === 'event' ? 'More info' : 'Source calendar'}
                            <span className="sr-only"> for {event.title} (opens another site in a new tab)</span>
                        </a>
                    )}
                </div>
            )}
        </article>
    );
}
