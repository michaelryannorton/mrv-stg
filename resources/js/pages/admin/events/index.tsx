import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Taxonomy {
    id: number;
    name: string;
    slug: string;
}

interface Venue {
    id: number;
    name: string;
    city: string | null;
}

interface Organization {
    id: number;
    name: string;
}

interface Source {
    id: number;
    name: string;
}

interface EventSourceLink {
    id: number;
    source_url: string | null;
    source: Source | null;
}

interface ModerationEvent {
    id: number;
    title: string;
    description: string | null;
    short_description: string | null;
    start_at: string;
    timezone: string;
    all_day: boolean;
    canonical_url: string | null;
    status: string;
    editorial_status: string;
    venue: Venue | null;
    organizer: Organization | null;
    categories: Taxonomy[];
    tags: Taxonomy[];
    event_sources: EventSourceLink[];
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Counts {
    pending: number;
    published: number;
    rejected: number;
}

interface AdminEventsIndexProps {
    events: Paginated<ModerationEvent>;
    status: 'pending' | 'published' | 'rejected';
    counts: Counts;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Review queue', href: '/admin/events' }];

const TABS: { value: AdminEventsIndexProps['status']; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'published', label: 'Published' },
    { value: 'rejected', label: 'Rejected' },
];

export default function AdminEventsIndex({ events, status, counts }: AdminEventsIndexProps) {
    const { flash } = usePage<SharedData>().props;

    function act(action: 'approve' | 'reject' | 'revert', event: ModerationEvent) {
        router.post(route(`admin.events.${action}`, event.id), {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Review queue" />

            <div className="flex flex-col gap-4 p-4">
                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                <div className="flex gap-2">
                    {TABS.map((tab) => (
                        <Link
                            key={tab.value}
                            href={route('admin.events.index', { status: tab.value })}
                            className={`rounded-md border px-3 py-1.5 text-sm ${
                                status === tab.value
                                    ? 'border-sidebar-border bg-accent text-accent-foreground'
                                    : 'border-sidebar-border/70 text-muted-foreground hover:bg-accent/50'
                            }`}
                        >
                            {tab.label} ({counts[tab.value]})
                        </Link>
                    ))}
                </div>

                {events.data.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center">
                        {status === 'pending' ? 'Nothing waiting for review right now.' : `No ${status} events.`}
                    </p>
                ) : (
                    <div className="grid gap-4">
                        {events.data.map((event) => (
                            <Card key={event.id}>
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <CardTitle>{event.title}</CardTitle>
                                            <p className="text-muted-foreground mt-1 text-sm">
                                                {formatWhen(event)}
                                                {event.venue && ` · ${event.venue.name}`}
                                                {event.venue?.city && `, ${event.venue.city}`}
                                            </p>
                                            <p className="text-muted-foreground mt-1 text-sm">
                                                Source: {event.event_sources[0]?.source?.name ?? event.organizer?.name ?? 'Unknown'}
                                                {event.canonical_url && (
                                                    <>
                                                        {' · '}
                                                        <a
                                                            href={event.canonical_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="underline underline-offset-2"
                                                        >
                                                            original listing
                                                        </a>
                                                    </>
                                                )}
                                            </p>
                                        </div>

                                        <div className="flex shrink-0 gap-2">
                                            {status !== 'published' && (
                                                <Button size="sm" onClick={() => act('approve', event)}>
                                                    Approve
                                                </Button>
                                            )}
                                            {status !== 'rejected' && (
                                                <Button size="sm" variant="destructive" onClick={() => act('reject', event)}>
                                                    Reject
                                                </Button>
                                            )}
                                            {status !== 'pending' && (
                                                <Button size="sm" variant="outline" onClick={() => act('revert', event)}>
                                                    Back to pending
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                {(event.short_description || event.categories.length > 0 || event.tags.length > 0) && (
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
                )}

                {events.links.length > 3 && (
                    <div className="mt-2 flex flex-wrap gap-2">
                        {events.links.map((link, i) => (
                            <Link
                                key={i}
                                href={link.url ?? '#'}
                                className={`rounded-sm border px-3 py-1 text-sm ${
                                    link.active ? 'border-sidebar-border bg-accent' : 'border-sidebar-border/70'
                                } ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function formatWhen(event: ModerationEvent): string {
    const date = new Date(event.start_at);
    const timeZone = event.timezone || 'America/Los_Angeles';

    if (event.all_day) {
        return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', timeZone });
    }

    return date.toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}
