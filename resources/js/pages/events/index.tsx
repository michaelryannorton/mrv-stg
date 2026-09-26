import PublicHeader from '@/components/public-header';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, Link, router } from '@inertiajs/react';

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

interface EventItem {
    id: number;
    uuid: string;
    title: string;
    slug: string;
    short_description: string | null;
    start_at: string;
    timezone: string;
    all_day: boolean;
    is_free: boolean | null;
    venue: Venue | null;
    organizer: Organization | null;
    categories: Taxonomy[];
    tags: Taxonomy[];
    audiences: Taxonomy[];
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta?: { current_page: number; last_page: number };
}

interface Filters {
    when?: string;
    category?: string;
    audience?: string;
    free?: boolean;
    city?: string;
}

interface EventsIndexProps {
    events: Paginated<EventItem>;
    filters: Filters;
    categories: Taxonomy[];
    audiences: Taxonomy[];
}

const WHEN_OPTIONS = [
    { value: 'today', label: 'Today' },
    { value: 'tonight', label: 'Tonight' },
    { value: 'this_weekend', label: 'This weekend' },
    { value: 'next_7_days', label: 'Next 7 days' },
];

export default function EventsIndex({ events, filters, categories, audiences }: EventsIndexProps) {
    function updateFilter(key: keyof Filters, value: string | boolean | undefined) {
        router.get(
            route('events.index'),
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Community Events" />

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <PublicHeader />

                <main className="mx-auto max-w-4xl px-6 py-10">
                    <h1 className="mb-2 text-2xl font-semibold">What's happening in the Mojave River Valley</h1>
                    <p className="mb-8 text-[#706f6c] dark:text-[#A1A09A]">
                        Events curated and verified by the Mojave River Valley Community team.
                    </p>

                    <div className="mb-8 flex flex-wrap items-end gap-4 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
                        <div className="grid gap-1.5">
                            <Label>When</Label>
                            <Select value={filters.when ?? ''} onValueChange={(v) => updateFilter('when', v)}>
                                <SelectTrigger className="w-[160px]">
                                    <SelectValue placeholder="Any time" />
                                </SelectTrigger>
                                <SelectContent>
                                    {WHEN_OPTIONS.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label>Topic</Label>
                            <Select value={filters.category ?? ''} onValueChange={(v) => updateFilter('category', v)}>
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="All topics" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((c) => (
                                        <SelectItem key={c.id} value={c.slug}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label>Audience</Label>
                            <Select value={filters.audience ?? ''} onValueChange={(v) => updateFilter('audience', v)}>
                                <SelectTrigger className="w-[160px]">
                                    <SelectValue placeholder="Everyone" />
                                </SelectTrigger>
                                <SelectContent>
                                    {audiences.map((a) => (
                                        <SelectItem key={a.id} value={a.slug}>
                                            {a.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex items-center gap-2 pb-2">
                            <Checkbox
                                id="free"
                                checked={!!filters.free}
                                onCheckedChange={(checked) => updateFilter('free', checked === true ? '1' : undefined)}
                            />
                            <Label htmlFor="free">Free only</Label>
                        </div>
                    </div>

                    {events.data.length === 0 ? (
                        <p className="rounded-md border border-dashed border-[#e3e3e0] p-8 text-center text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                            No events match right now. Check back soon — new events are added as they're found and verified.
                        </p>
                    ) : (
                        <div className="grid gap-4">
                            {events.data.map((event) => (
                                <Card key={event.id}>
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <CardTitle>{event.title}</CardTitle>
                                                <p className="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                    {formatWhen(event)}
                                                    {event.venue && ` · ${event.venue.name}`}
                                                    {event.venue?.city && `, ${event.venue.city}`}
                                                </p>
                                            </div>
                                            {event.is_free && <Badge>Free</Badge>}
                                        </div>
                                    </CardHeader>
                                    {(event.short_description || event.categories.length > 0) && (
                                        <CardContent>
                                            {event.short_description && (
                                                <p className="mb-3 text-sm">{event.short_description}</p>
                                            )}
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
                        <div className="mt-8 flex flex-wrap gap-2">
                            {events.links.map((link, i) => (
                                <Link
                                    key={i}
                                    href={link.url ?? '#'}
                                    className={`rounded-sm border px-3 py-1 text-sm ${
                                        link.active
                                            ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:border-[#EDEDEC] dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                                            : 'border-[#e3e3e0] dark:border-[#3E3E3A]'
                                    } ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}

/**
 * Always renders in the event's own local timezone, not the viewer's browser timezone — a concert
 * at 7pm Pacific should read as 7pm to every visitor, wherever they're browsing from.
 */
function formatWhen(event: EventItem): string {
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
