import { AccessScopeBadge } from '@/components/access-scope-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface CurateEvent {
    id: number;
    title: string;
    start_at: string | null;
    end_at: string | null;
    timezone: string;
    all_day: boolean;
    venue: { name: string; city: string | null } | null;
    location: string | null;
    categories: string[];
    is_public: boolean;
    access_scope: string;
    url: string | null;
    state: 'category' | 'curated' | 'featured' | 'excluded';
}

interface Paginated<T> {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    current_page: number;
    last_page: number;
    total: number;
}

interface CurateShowProps {
    channel: { id: number; name: string; slug: string; description: string | null; status: string; categories: string[] };
    tab: 'events' | 'excluded';
    events: Paginated<CurateEvent>;
    excluded_count: number;
    find: string;
    results: CurateEvent[];
}

function formatWhen(event: CurateEvent): string {
    if (!event.start_at) return '';
    const start = new Date(event.start_at);
    const date = new Intl.DateTimeFormat('en-US', { timeZone: event.timezone, weekday: 'short', month: 'short', day: 'numeric' }).format(start);
    if (event.all_day) return `${date}, all day`;
    const time = new Intl.DateTimeFormat('en-US', { timeZone: event.timezone, hour: 'numeric', minute: '2-digit' }).format(start);
    return `${date}, ${time}`;
}

function place(event: CurateEvent): string {
    if (event.venue) return event.venue.city ? `${event.venue.name}, ${event.venue.city}` : event.venue.name;
    return event.location ?? '';
}

function safeUrl(url: string | null): string | null {
    return url && /^https?:\/\//i.test(url) ? url : null;
}

function EventCell({ event }: { event: CurateEvent }) {
    const href = safeUrl(event.url);
    return (
        <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
                {event.state === 'featured' && <Star className="size-4 fill-amber-500 text-amber-500" aria-label="Featured" />}
                {href ? (
                    <a href={href} target="_blank" rel="noopener noreferrer" className="font-medium hover:underline">
                        {event.title}
                    </a>
                ) : (
                    <span className="font-medium">{event.title}</span>
                )}
                {!event.is_public && <Badge variant="secondary">Not public yet</Badge>}
                <AccessScopeBadge accessScope={event.access_scope} />
            </div>
            <div className="text-muted-foreground text-xs">{[place(event), event.categories.join(', ')].filter(Boolean).join(' · ')}</div>
        </div>
    );
}

export default function CurateShow({ channel, tab, events, excluded_count, find, results }: CurateShowProps) {
    const { flash } = usePage<SharedData>().props;
    const [term, setTerm] = useState(find);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Curate', href: '/curate' },
        { title: channel.name, href: `/curate/channels/${channel.slug}` },
    ];

    function act(event: CurateEvent, action: 'feature' | 'unfeature' | 'exclude' | 'restore' | 'add') {
        router.post(route('curate.channels.act', [channel.slug, event.id, action]), {}, { preserveScroll: true });
    }

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('curate.channels.show', channel.slug), { tab: 'events', find: term }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Curate: ${channel.name}`} />

            <div className="flex flex-col gap-4 p-4">
                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-xl font-semibold">{channel.name}</h1>
                        {channel.status !== 'active' && <Badge variant="secondary">{channel.status}</Badge>}
                    </div>
                    {channel.description && <p className="text-muted-foreground mt-1 max-w-3xl text-sm">{channel.description}</p>}
                    <p className="text-muted-foreground mt-1 text-xs">
                        {channel.categories.length > 0
                            ? `Includes every event in: ${channel.categories.join(', ')}. Events you add by hand are included too.`
                            : 'This channel has no linked categories. It contains only the events you add by hand.'}
                    </p>
                </div>

                <div className="flex gap-2">
                    <Link
                        href={route('curate.channels.show', channel.slug)}
                        className={`rounded-md border px-3 py-1.5 text-sm ${tab === 'events' ? 'border-sidebar-border bg-accent text-accent-foreground' : 'border-sidebar-border/70 text-muted-foreground hover:bg-accent/50'}`}
                    >
                        Events
                    </Link>
                    <Link
                        href={route('curate.channels.show', { channel: channel.slug, tab: 'excluded' })}
                        className={`rounded-md border px-3 py-1.5 text-sm ${tab === 'excluded' ? 'border-sidebar-border bg-accent text-accent-foreground' : 'border-sidebar-border/70 text-muted-foreground hover:bg-accent/50'}`}
                    >
                        Excluded ({excluded_count})
                    </Link>
                </div>

                {tab === 'events' && (
                    <div className="border-sidebar-border/70 flex flex-col gap-3 rounded-md border p-4">
                        <h2 className="text-sm font-semibold">Add an event from the calendar</h2>
                        <p className="text-muted-foreground text-xs">Search upcoming events that are not in this channel yet.</p>
                        <form onSubmit={search} className="flex max-w-md gap-2">
                            <Input
                                value={term}
                                onChange={(e) => setTerm(e.target.value)}
                                placeholder="Part of an event title"
                                aria-label="Search events"
                            />
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                        {find.length >= 2 && results.length === 0 && (
                            <p className="text-muted-foreground text-sm">No matching events outside this channel.</p>
                        )}
                        {results.length > 0 && (
                            <ul className="divide-sidebar-border/50 border-sidebar-border/70 divide-y rounded-md border">
                                {results.map((event) => (
                                    <li key={event.id} className="flex items-center justify-between gap-3 px-3 py-2">
                                        <div className="min-w-0">
                                            <EventCell event={event} />
                                            <div className="text-muted-foreground text-xs">{formatWhen(event)}</div>
                                        </div>
                                        <Button size="sm" onClick={() => act(event, 'add')}>
                                            Add
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {events.data.length === 0 ? (
                    <div className="border-sidebar-border/70 text-muted-foreground rounded-md border p-8 text-center text-sm">
                        {tab === 'excluded' ? 'No excluded upcoming events.' : 'No upcoming events in this channel yet.'}
                    </div>
                ) : (
                    <div className="border-sidebar-border/70 overflow-x-auto rounded-md border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-sidebar-border/70 bg-muted/40 text-muted-foreground border-b text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">When</th>
                                    <th className="px-3 py-2 font-medium">Event</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {events.data.map((event) => (
                                    <tr key={event.id} className="border-sidebar-border/50 border-b align-top last:border-0">
                                        <td className="text-muted-foreground px-3 py-2 whitespace-nowrap">{formatWhen(event)}</td>
                                        <td className="px-3 py-2">
                                            <EventCell event={event} />
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {tab === 'excluded' ? (
                                                <Button size="sm" variant="outline" onClick={() => act(event, 'restore')}>
                                                    Restore
                                                </Button>
                                            ) : (
                                                <div className="flex justify-end gap-2">
                                                    {event.state === 'featured' ? (
                                                        <Button size="sm" variant="outline" onClick={() => act(event, 'unfeature')}>
                                                            Unfeature
                                                        </Button>
                                                    ) : (
                                                        <Button size="sm" onClick={() => act(event, 'feature')}>
                                                            Feature
                                                        </Button>
                                                    )}
                                                    <Button size="sm" variant="ghost" onClick={() => act(event, 'exclude')}>
                                                        Exclude
                                                    </Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {events.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Page {events.current_page} of {events.last_page} ({events.total} events)
                        </span>
                        <div className="flex gap-2">
                            {events.prev_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={events.prev_page_url} preserveScroll>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {events.next_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={events.next_page_url} preserveScroll>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
