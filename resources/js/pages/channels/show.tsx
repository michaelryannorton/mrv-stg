import EventCard from '@/components/event-card';
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
                <div role="status" className="border-b border-amber-300 bg-amber-50 px-6 py-2 text-center text-sm text-amber-900">
                    Preview: this channel is {preview} and not public.
                </div>
            )}

            <div className="mx-auto max-w-4xl px-6 py-10">
                <p className="mb-4 text-sm">
                    <Link href={route('channels.index')} className="text-[#706f6c] hover:underline dark:text-[#A1A09A]">
                        All channels
                    </Link>
                </p>

                <h1 className="mb-2 text-2xl font-semibold">{channel.name}</h1>
                {channel.description && <p className="mb-4 whitespace-pre-line text-[#706f6c] dark:text-[#A1A09A]">{channel.description}</p>}
                <p className="mb-8 text-sm">
                    <Link href={route('events.index')} className="underline underline-offset-4">
                        See the full calendar
                    </Link>
                </p>

                {curators.length > 0 && (
                    <section aria-label="Curators" className="mb-10 grid gap-4 sm:grid-cols-2">
                        {curators.map((curator) => (
                            <div key={curator.display_name + curator.role} className="rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
                                <p className="font-medium">{curator.display_name}</p>
                                <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    {curator.role}
                                    {curator.since && ` · Curating since ${curator.since}`}
                                </p>
                                {curator.blurb && <p className="mt-2 text-sm whitespace-pre-line">{curator.blurb}</p>}
                            </div>
                        ))}
                    </section>
                )}

                {picks.length > 0 && (
                    <section aria-labelledby="picks-heading" className="mb-10">
                        <h2 id="picks-heading" className="mb-4 text-lg font-medium">
                            Curator's picks
                        </h2>
                        <div className="grid gap-4">
                            {picks.map((event) => (
                                <EventCard key={event.id} event={event} />
                            ))}
                        </div>
                    </section>
                )}

                <section aria-labelledby="feed-heading">
                    <h2 id="feed-heading" className="mb-4 text-lg font-medium">
                        All upcoming events
                    </h2>

                    {events.data.length === 0 ? (
                        <p className="rounded-md border border-dashed border-[#e3e3e0] p-8 text-center text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                            Nothing is scheduled in this channel yet. Check back soon, or{' '}
                            <Link href={route('events.index')} className="underline underline-offset-4">
                                browse the full calendar
                            </Link>
                            .
                        </p>
                    ) : (
                        <div className="grid gap-4">
                            {events.data.map((event) => (
                                <EventCard key={event.id} event={event} />
                            ))}
                        </div>
                    )}

                    <PaginationLinks links={events.links} />
                </section>
            </div>
        </PublicLayout>
    );
}
