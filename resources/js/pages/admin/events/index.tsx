import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { matchesQuery } from '@/lib/queue-search';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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

interface Counts {
    pending: number;
    published: number;
    rejected: number;
}

type StatusTab = 'pending' | 'published' | 'rejected';
type SortKey = 'start_at' | 'title' | 'venue' | 'source';
type SortDir = 'asc' | 'desc';
type BulkAction = 'approve' | 'reject' | 'revert';

interface AdminEventsIndexProps {
    events: ModerationEvent[];
    status: StatusTab;
    counts: Counts;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Review queue', href: '/admin/events' }];

const TABS: { value: StatusTab; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'published', label: 'Published' },
    { value: 'rejected', label: 'Rejected' },
];

function sourceName(event: ModerationEvent): string {
    return event.event_sources[0]?.source?.name ?? event.organizer?.name ?? '';
}

function searchableText(event: ModerationEvent): string {
    return [
        event.title,
        event.short_description,
        event.description,
        event.venue?.name,
        event.venue?.city,
        event.organizer?.name,
        sourceName(event),
        ...event.categories.map((c) => c.name),
        ...event.tags.map((t) => t.name),
    ]
        .filter(Boolean)
        .join(' ');
}

function compareEvents(a: ModerationEvent, b: ModerationEvent, key: SortKey): number {
    switch (key) {
        case 'start_at':
            return a.start_at.localeCompare(b.start_at);
        case 'title':
            return a.title.toLowerCase().localeCompare(b.title.toLowerCase());
        case 'venue':
            return (a.venue?.name ?? '').toLowerCase().localeCompare((b.venue?.name ?? '').toLowerCase());
        case 'source':
            return sourceName(a).toLowerCase().localeCompare(sourceName(b).toLowerCase());
    }
}

export default function AdminEventsIndex({ events, status, counts }: AdminEventsIndexProps) {
    const { flash } = usePage<SharedData>().props;

    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('all');
    const [sourceFilter, setSourceFilter] = useState('all');
    const [sortKey, setSortKey] = useState<SortKey>('start_at');
    const [sortDir, setSortDir] = useState<SortDir>('asc');
    const [selected, setSelected] = useState<Set<number>>(new Set());

    useEffect(() => setSelected(new Set()), [status]);

    const categoryOptions = useMemo(() => {
        const seen = new Map<string, string>();
        events.forEach((e) => e.categories.forEach((c) => seen.set(c.slug, c.name)));
        return Array.from(seen, ([slug, name]) => ({ slug, name })).sort((a, b) => a.name.localeCompare(b.name));
    }, [events]);

    const sourceOptions = useMemo(() => {
        const names = new Set<string>();
        events.forEach((e) => {
            const name = sourceName(e);
            if (name) names.add(name);
        });
        return Array.from(names).sort();
    }, [events]);

    const rows = useMemo(() => {
        const filtered = events.filter((e) => {
            if (categoryFilter !== 'all' && !e.categories.some((c) => c.slug === categoryFilter)) return false;
            if (sourceFilter !== 'all' && sourceName(e) !== sourceFilter) return false;
            return matchesQuery(searchableText(e), search);
        });

        return filtered.sort((a, b) => {
            const cmp = compareEvents(a, b, sortKey);
            return sortDir === 'asc' ? cmp : -cmp;
        });
    }, [events, search, categoryFilter, sourceFilter, sortKey, sortDir]);

    const visibleIds = useMemo(() => rows.map((r) => r.id), [rows]);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));

    function toggleSort(key: SortKey) {
        if (sortKey === key) {
            setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortKey(key);
            setSortDir('asc');
        }
    }

    function toggleRow(id: number) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function toggleSelectAllVisible() {
        setSelected((prev) => {
            if (allVisibleSelected) {
                const next = new Set(prev);
                visibleIds.forEach((id) => next.delete(id));
                return next;
            }
            return new Set([...prev, ...visibleIds]);
        });
    }

    function act(action: BulkAction, ids: number[]) {
        if (ids.length === 0) return;
        router.post(route('admin.events.bulk'), { action, ids }, { preserveScroll: true, onSuccess: () => setSelected(new Set()) });
    }

    function SortHeader({ label, sortKeyValue }: { label: string; sortKeyValue: SortKey }) {
        const Icon = sortKey !== sortKeyValue ? ArrowUpDown : sortDir === 'asc' ? ArrowUp : ArrowDown;
        return (
            <button type="button" onClick={() => toggleSort(sortKeyValue)} className="hover:text-foreground flex items-center gap-1 font-medium">
                {label}
                <Icon className={`size-3.5 ${sortKey === sortKeyValue ? '' : 'opacity-40'}`} />
            </button>
        );
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

                <div className="flex flex-wrap items-center gap-2">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={'Search… try coffee OR mixer, -cancelled, "open coffee" (typos ok)'}
                        className="border-input bg-background placeholder:text-muted-foreground focus:ring-ring h-10 min-w-[320px] flex-1 rounded-md border px-3 py-2 text-sm focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                    />

                    <Select value={categoryFilter} onValueChange={setCategoryFilter}>
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            {categoryOptions.map((c) => (
                                <SelectItem key={c.slug} value={c.slug}>
                                    {c.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={sourceFilter} onValueChange={setSourceFilter}>
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="All sources" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All sources</SelectItem>
                            {sourceOptions.map((name) => (
                                <SelectItem key={name} value={name}>
                                    {name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {selected.size > 0 && (
                    <div className="border-sidebar-border bg-accent/50 flex items-center gap-3 rounded-md border px-4 py-2">
                        <span className="text-sm font-medium">{selected.size} selected</span>
                        {status !== 'published' && (
                            <Button size="sm" onClick={() => act('approve', Array.from(selected))}>
                                Approve
                            </Button>
                        )}
                        {status !== 'rejected' && (
                            <Button size="sm" variant="destructive" onClick={() => act('reject', Array.from(selected))}>
                                Reject
                            </Button>
                        )}
                        {status !== 'pending' && (
                            <Button size="sm" variant="outline" onClick={() => act('revert', Array.from(selected))}>
                                Back to pending
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
                            Clear
                        </Button>
                    </div>
                )}

                {rows.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center">
                        {events.length === 0
                            ? status === 'pending'
                                ? 'Nothing waiting for review right now.'
                                : `No ${status} events.`
                            : 'No events match your search or filters.'}
                    </p>
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="w-10 px-3 py-2">
                                        <Checkbox checked={allVisibleSelected} onCheckedChange={toggleSelectAllVisible} />
                                    </th>
                                    <th className="px-3 py-2">
                                        <SortHeader label="Title" sortKeyValue="title" />
                                    </th>
                                    <th className="px-3 py-2">
                                        <SortHeader label="When" sortKeyValue="start_at" />
                                    </th>
                                    <th className="px-3 py-2">
                                        <SortHeader label="Venue" sortKeyValue="venue" />
                                    </th>
                                    <th className="px-3 py-2">
                                        <SortHeader label="Source" sortKeyValue="source" />
                                    </th>
                                    <th className="px-3 py-2">Categories</th>
                                    <th className="px-3 py-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((event) => (
                                    <tr
                                        key={event.id}
                                        className="border-sidebar-border/70 dark:border-sidebar-border hover:bg-accent/30 border-b last:border-b-0"
                                    >
                                        <td className="px-3 py-2 align-top">
                                            <Checkbox checked={selected.has(event.id)} onCheckedChange={() => toggleRow(event.id)} />
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <div className="font-medium">{event.title}</div>
                                            {event.canonical_url && (
                                                <a
                                                    href={event.canonical_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-muted-foreground text-xs underline underline-offset-2"
                                                >
                                                    original listing
                                                </a>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground px-3 py-2 align-top whitespace-nowrap">{formatWhen(event)}</td>
                                        <td className="text-muted-foreground px-3 py-2 align-top">
                                            {event.venue ? `${event.venue.name}${event.venue.city ? `, ${event.venue.city}` : ''}` : '—'}
                                        </td>
                                        <td className="text-muted-foreground px-3 py-2 align-top">{sourceName(event) || '—'}</td>
                                        <td className="px-3 py-2 align-top">
                                            <div className="flex flex-wrap gap-1">
                                                {event.categories.map((c) => (
                                                    <Badge key={c.id} variant="secondary">
                                                        {c.name}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <div className="flex gap-1.5">
                                                <Button size="sm" variant="outline" asChild>
                                                    <Link href={route('admin.events.edit', event.id)}>Edit</Link>
                                                </Button>
                                                {status !== 'published' && (
                                                    <Button size="sm" onClick={() => act('approve', [event.id])}>
                                                        Approve
                                                    </Button>
                                                )}
                                                {status !== 'rejected' && (
                                                    <Button size="sm" variant="destructive" onClick={() => act('reject', [event.id])}>
                                                        Reject
                                                    </Button>
                                                )}
                                                {status !== 'pending' && (
                                                    <Button size="sm" variant="outline" onClick={() => act('revert', [event.id])}>
                                                        Back
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {rows.length > 0 && (
                    <p className="text-muted-foreground text-xs">
                        Showing {rows.length} of {events.length} {events.length === 1 ? 'event' : 'events'} in this tab.
                    </p>
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
