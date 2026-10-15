import EventCard from '@/components/event-card';
import { DirectoryHero } from '@/components/mrv/directory-hero';
import PaginationLinks from '@/components/pagination-links';
import PublicLayout from '@/layouts/public-layout';
import { type EventItem } from '@/types/event';
import { Head, Link } from '@inertiajs/react';

interface CuratorCard {
    display_name: string;
    role: string;
    blurb: string | null;
    since: string | null;
}

interface ChannelShowProps {
    channel: { name: string; slug: string; description: string | null; meta_description: string | null };
    preview: string | null;
    curators: CuratorCard[];
    picks: EventItem[];
    events: { data: EventItem[]; links: { url: string | null; label: string; active: boolean }[] };
}

export default function ChannelShow({ channel, preview, curators, picks, events }: ChannelShowProps) {
    return (
        <PublicLayout activeId="channels">
            <Head title={channel.name}>{channel.meta_description && <meta name="description" content={channel.meta_description} />}</Head>

            {preview && (
                <div role="status" className="notice preview">
                    Preview: this channel is {preview} and not public.
                </div>
            )}

            <DirectoryHero eyebrow="Channel" title={channel.name}>
                {channel.description && <span style={{ whiteSpace: 'pre-line' }}>{channel.description}</span>}
                {channel.description && <br />}
                <Link href={route('events.index')} style={{ textDecoration: 'underline', textUnderlineOffset: '3px' }}>
                    See the full calendar
                </Link>
            </DirectoryHero>

            <section className="alt">
                <div className="mrv-container">
                    <p className="back-link-row">
                        <Link href={route('channels.index')}>&larr; All channels</Link>
                    </p>

                    {curators.length > 0 && (
                        <section aria-label="Curators" style={{ marginBottom: '36px' }}>
                            <div className="grid-markets grid">
                                {curators.map((curator) => (
                                    <div key={curator.display_name + curator.role} className="card curator-card">
                                        <h3>{curator.display_name}</h3>
                                        <p className="card-note">
                                            {curator.role}
                                            {curator.since && ` · Curating since ${curator.since}`}
                                        </p>
                                        {curator.blurb && <p className="blurb whitespace-pre-line">{curator.blurb}</p>}
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}

                    {picks.length > 0 && (
                        <section aria-labelledby="picks-heading" style={{ marginBottom: '36px' }}>
                            <div className="section-heading">
                                <h2 id="picks-heading">Curator's picks</h2>
                            </div>
                            <div className="event-list">
                                {picks.map((event) => (
                                    <EventCard key={event.id} event={event} />
                                ))}
                            </div>
                        </section>
                    )}

                    <section aria-labelledby="feed-heading">
                        <div className="section-heading">
                            <h2 id="feed-heading">All upcoming events</h2>
                        </div>

                        {events.data.length === 0 ? (
                            <div className="card empty-card">
                                <p className="card-note">
                                    Nothing is scheduled in this channel yet. Check back soon, or{' '}
                                    <Link href={route('events.index')} style={{ textDecoration: 'underline', textUnderlineOffset: '3px' }}>
                                        browse the full calendar
                                    </Link>
                                    .
                                </p>
                            </div>
                        ) : (
                            <div className="event-list">
                                {events.data.map((event) => (
                                    <EventCard key={event.id} event={event} />
                                ))}
                            </div>
                        )}

                        <PaginationLinks links={events.links} />
                    </section>
                </div>
            </section>
        </PublicLayout>
    );
}
