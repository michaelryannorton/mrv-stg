import { AccessScopeBadge } from '@/components/access-scope-badge';
import EventDescription from '@/components/event-description';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatWhen } from '@/lib/event-formatting';
import { matchesQuery } from '@/lib/queue-search';
import { CONFIRM_ACCESS_SCOPES, compareRows, flaggedByTitle, rowIds, rowScope, rowText, type QueueRow, type SortKey } from '@/lib/review-queue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowUp, ArrowUpDown, ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';

type StatusTab = 'pending' | 'past' | 'published' | 'rejected';
type SortDir = 'asc' | 'desc';
type BulkAction = 'approve' | 'reject' | 'revert';

interface AdminEventsIndexProps {
    rows: QueueRow[];
    status: StatusTab;
    counts: Record<StatusTab, number>;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Review queue', href: '/admin/events' }];

const TABS: { value: StatusTab; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'past', label: 'Past' },
    { value: 'published', label: 'Published' },
    { value: 'rejected', label: 'Rejected' },
];

const EMPTY: Record<StatusTab, string> = {
    pending: 'Nothing waiting for review right now.',
    past: 'No pending events have passed.',
    published: 'No published events.',
    rejected: 'No rejected events.',
};

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

export default function AdminEventsIndex({ rows, status, counts }: AdminEventsIndexProps) {
    const { flash } = usePage<SharedData>().props;
    // "Past" holds events still marked pending, so it offers the same actions as "Pending".
    const awaiting = status === 'pending' || status === 'past';

    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('all');
    const [sourceFilter, setSourceFilter] = useState('all');
    const [accessScopeFilter, setAccessScopeFilter] = useState('all');
    const [sortKey, setSortKey] = useState<SortKey>('start_at');
    const [sortDir, setSortDir] = useState<SortDir>('asc');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [open, setOpen] = useState<Set<string>>(new Set());
    const [pendingApproval, setPendingApproval] = useState<{ ids: number[]; flagged: number[] } | null>(null);

    useEffect(() => {
        setSelected(new Set());
        setOpen(new Set());
        setSortKey('start_at');
        setSortDir(status === 'past' ? 'desc' : 'asc');
    }, [status]);

    const categoryOptions = useMemo(() => {
        const seen = new Map<string, string>();
        rows.forEach((r) => r.categories.forEach((c) => seen.set(c.slug, c.name)));
        return Array.from(seen, ([slug, name]) => ({ slug, name })).sort((a, b) => a.name.localeCompare(b.name));
    }, [rows]);

    const sourceOptions = useMemo(() => Array.from(new Set(rows.flatMap((r) => (r.source ? [r.source] : [])))).sort(), [rows]);

    const shown = useMemo(() => {
        const filtered = rows.filter((r) => {
            if (categoryFilter !== 'all' && !r.categories.some((c) => c.slug === categoryFilter)) return false;
            if (sourceFilter !== 'all' && r.source !== sourceFilter) return false;
            if (accessScopeFilter !== 'all' && !r.dates.some((d) => d.access_scope === accessScopeFilter)) return false;
            return matchesQuery(rowText(r), search);
        });

        return filtered.sort((a, b) => (sortDir === 'asc' ? 1 : -1) * compareRows(a, b, sortKey));
    }, [rows, search, categoryFilter, sourceFilter, accessScopeFilter, sortKey, sortDir]);

    const visibleIds = useMemo(() => shown.flatMap(rowIds), [shown]);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));
    const selectedRows = rows.filter((r) => r.dates.some((d) => selected.has(d.id))).length;
    const totalDates = rows.reduce((n, r) => n + r.dates.length, 0);

    function toggleSort(key: SortKey) {
        if (sortKey === key) {
            setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortKey(key);
            setSortDir('asc');
        }
    }

    function select(ids: number[], on: boolean) {
        setSelected((prev) => {
            const next = new Set(prev);
            ids.forEach((id) => (on ? next.add(id) : next.delete(id)));
            return next;
        });
    }

    function toggleOpen(key: string) {
        setOpen((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    }

    function act(action: BulkAction, ids: number[]) {
        if (ids.length === 0) return;
        router.post(
            route('admin.events.bulk'),
            { action, ids },
            { preserveScroll: true, preserveState: true, onSuccess: () => setSelected(new Set()) },
        );
    }

    // One gate for the bulk bar, a row and a single date: approves straight away unless some of the dates are not
    // fully public, in which case it asks first.
    function requestApprove(ids: number[]) {
        const idSet = new Set(ids);
        const flagged = rows.flatMap((r) => r.dates.filter((d) => idSet.has(d.id) && CONFIRM_ACCESS_SCOPES.has(d.access_scope)).map((d) => d.id));
        if (flagged.length === 0) {
            act('approve', ids);
            return;
        }
        setPendingApproval({ ids, flagged });
    }

    function confirmApproval(ids: number[]) {
        setPendingApproval(null);
        act('approve', ids);
    }

    function actions(ids: number[], withCount: boolean) {
        const suffix = withCount && ids.length > 1 ? ` ${ids.length}` : '';

        return (
            <>
                {status !== 'published' && (
                    <Button size="sm" onClick={() => requestApprove(ids)}>
                        Approve{suffix}
                    </Button>
                )}
                {status !== 'rejected' && (
                    <Button size="sm" variant="destructive" onClick={() => act('reject', ids)}>
                        Reject{suffix}
                    </Button>
                )}
                {!awaiting && (
                    <Button size="sm" variant="outline" onClick={() => act('revert', ids)}>
                        Back{suffix}
                    </Button>
                )}
            </>
        );
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

            <div className="flex min-w-0 flex-col gap-4 p-4">
                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-wrap gap-2">
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
                        aria-label="Search the queue"
                        className="border-input bg-background placeholder:text-muted-foreground focus:ring-ring h-10 min-w-0 flex-1 basis-80 rounded-md border px-3 py-2 text-sm focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
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

                    <Select value={accessScopeFilter} onValueChange={setAccessScopeFilter}>
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All access scopes" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All access scopes</SelectItem>
                            <SelectItem value="public">Public</SelectItem>
                            <SelectItem value="mixed">Mixed access</SelectItem>
                            <SelectItem value="public_with_eligibility">Eligibility-gated</SelectItem>
                            <SelectItem value="public_with_registration">Registration required</SelectItem>
                            <SelectItem value="unknown">Unclassified</SelectItem>
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
                    <div className="border-sidebar-border bg-accent/50 flex flex-wrap items-center gap-3 rounded-md border px-4 py-2">
                        <span className="text-sm font-medium">
                            {plural(selected.size, 'date')} in {plural(selectedRows, 'row')} selected
                        </span>
                        {actions(Array.from(selected), false)}
                        <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
                            Clear
                        </Button>
                    </div>
                )}

                {shown.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center">
                        {rows.length === 0 ? EMPTY[status] : 'No events match your search or filters.'}
                    </p>
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="w-10 px-3 py-2">
                                        <Checkbox
                                            checked={allVisibleSelected}
                                            onCheckedChange={() => select(visibleIds, !allVisibleSelected)}
                                            aria-label="Select everything shown"
                                        />
                                    </th>
                                    <th className="px-3 py-2">
                                        <SortHeader label="Title" sortKeyValue="title" />
                                    </th>
                                    <th className="px-3 py-2">
                                        <SortHeader label="When" sortKeyValue="start_at" />
                                    </th>
                                    <th className="px-3 py-2">
                                        <SortHeader label="Place" sortKeyValue="place" />
                                    </th>
                                    <th className="px-3 py-2">
                                        <SortHeader label="Source" sortKeyValue="source" />
                                    </th>
                                    <th className="hidden px-3 py-2 2xl:table-cell">Categories</th>
                                    <th className="px-3 py-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((row) => {
                                    const ids = rowIds(row);
                                    const first = row.dates[0];
                                    const expanded = open.has(row.key);
                                    const checked = ids.every((id) => selected.has(id))
                                        ? true
                                        : ids.some((id) => selected.has(id))
                                          ? 'indeterminate'
                                          : false;

                                    return (
                                        <Fragment key={row.key}>
                                            <tr className="border-sidebar-border/70 dark:border-sidebar-border hover:bg-accent/30 border-b">
                                                <td className="px-3 py-2 align-top">
                                                    <Checkbox
                                                        checked={checked}
                                                        onCheckedChange={() => select(ids, checked !== true)}
                                                        aria-label={`Select ${row.title}`}
                                                    />
                                                </td>
                                                <td className="min-w-48 px-3 py-2 align-top">
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleOpen(row.key)}
                                                        aria-expanded={expanded}
                                                        className="flex items-start gap-1 text-left font-medium"
                                                    >
                                                        {expanded ? (
                                                            <ChevronDown className="mt-0.5 size-4 shrink-0" />
                                                        ) : (
                                                            <ChevronRight className="mt-0.5 size-4 shrink-0" />
                                                        )}
                                                        {row.title}
                                                    </button>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1">
                                                        {row.approved_before && (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-green-600/40 text-green-700 dark:text-green-400"
                                                            >
                                                                Approved before
                                                            </Badge>
                                                        )}
                                                        {row.places_differ && <Badge variant="outline">Places differ</Badge>}
                                                        {row.descriptions_differ && <Badge variant="outline">Descriptions differ</Badge>}
                                                        <AccessScopeBadge accessScope={rowScope(row)} />
                                                        {row.dates.some((d) => d.stale) && (
                                                            <AlertTriangle
                                                                className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400"
                                                                aria-label="Source has changed since a manual edit was locked"
                                                            />
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="text-muted-foreground px-3 py-2 align-top whitespace-nowrap">
                                                    {formatWhen(first)}
                                                    {row.dates.length > 1 && <div className="text-xs">{row.dates.length} dates</div>}
                                                </td>
                                                <td className="text-muted-foreground px-3 py-2 align-top">
                                                    {row.places_differ ? 'Several places' : (first.place ?? '—')}
                                                </td>
                                                <td className="text-muted-foreground px-3 py-2 align-top">{row.source ?? row.organizer ?? '—'}</td>
                                                <td className="hidden px-3 py-2 align-top 2xl:table-cell">
                                                    <div className="flex flex-wrap gap-1">
                                                        {row.categories.map((c) => (
                                                            <Badge key={c.slug} variant="secondary">
                                                                {c.name}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 align-top">
                                                    <div className="flex gap-1.5">
                                                        {row.dates.length === 1 && (
                                                            <Button size="sm" variant="outline" asChild>
                                                                <Link href={route('admin.events.edit', first.id)}>Edit</Link>
                                                            </Button>
                                                        )}
                                                        {actions(ids, true)}
                                                    </div>
                                                </td>
                                            </tr>
                                            {expanded && (
                                                <tr className="border-sidebar-border/70 dark:border-sidebar-border bg-accent/20 border-b">
                                                    <td />
                                                    <td colSpan={6} className="px-3 py-3">
                                                        <RowDetail row={row} actions={(id) => actions([id], false)} />
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {shown.length > 0 && (
                    <p className="text-muted-foreground text-xs">
                        Showing {shown.length} of {plural(rows.length, 'row')} ({plural(totalDates, 'date')} in this tab).
                    </p>
                )}
            </div>

            <Dialog open={pendingApproval !== null} onOpenChange={(isOpen) => !isOpen && setPendingApproval(null)}>
                <DialogContent>
                    {pendingApproval && (
                        <ApprovalConfirmBody
                            rows={rows}
                            pending={pendingApproval}
                            onConfirm={confirmApproval}
                            onCancel={() => setPendingApproval(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Description({ text }: { text: string | null }) {
    return text ? (
        <div className="max-w-prose space-y-2 break-words whitespace-pre-line [&_a]:underline">
            <EventDescription text={text} />
        </div>
    ) : (
        <p className="text-muted-foreground">No description.</p>
    );
}

/** What a curator reads before deciding: the description as the public will see it, and each date with its link. */
function RowDetail({ row, actions }: { row: QueueRow; actions: (id: number) => React.ReactNode }) {
    return (
        <div className="flex flex-col gap-3">
            {!row.descriptions_differ && <Description text={row.descriptions[0]} />}
            <ul className="flex flex-col gap-3">
                {row.dates.map((date) => (
                    <li key={date.id} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-1 border-t pt-2">
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="font-medium">{formatWhen(date)}</span>
                            <span className="text-muted-foreground">{date.place ?? 'No place'}</span>
                            <AccessScopeBadge accessScope={date.access_scope} />
                            {date.link ? (
                                <a href={date.link.url} target="_blank" rel="noopener noreferrer" className="underline underline-offset-2">
                                    {date.link.kind === 'event' ? 'More info' : 'Source calendar'}
                                </a>
                            ) : (
                                <span className="text-amber-700 dark:text-amber-400">No link</span>
                            )}
                            <span className="ml-auto flex gap-1.5">
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={route('admin.events.edit', date.id)}>Edit</Link>
                                </Button>
                                {row.dates.length > 1 && actions(date.id)}
                            </span>
                        </div>
                        {row.descriptions_differ && <Description text={row.descriptions[date.description]} />}
                    </li>
                ))}
            </ul>
        </div>
    );
}

const FLAGGED_LIST_LIMIT = 8;

function ApprovalConfirmBody({
    rows,
    pending,
    onConfirm,
    onCancel,
}: {
    rows: QueueRow[];
    pending: { ids: number[]; flagged: number[] };
    onConfirm: (ids: number[]) => void;
    onCancel: () => void;
}) {
    const { ids, flagged } = pending;
    const flaggedIds = new Set(flagged);
    const unflaggedIds = ids.filter((id) => !flaggedIds.has(id));
    const titles = flaggedByTitle(rows, flaggedIds);
    const shown = titles.slice(0, FLAGGED_LIST_LIMIT);
    const hidden = titles.length - shown.length;

    return (
        <>
            <DialogHeader>
                <DialogTitle>
                    {flagged.length} of {ids.length} {ids.length === 1 ? 'date is' : 'dates are'} not fully public
                </DialogTitle>
                <DialogDescription>
                    Approving publishes to the public calendar. These come from sources classified as mixed-access or eligibility-gated, so check that
                    each one is open to the general public.
                </DialogDescription>
            </DialogHeader>

            <ul className="flex max-h-64 flex-col gap-2 overflow-y-auto text-sm">
                {shown.map((t) => (
                    <li key={t.key} className="flex items-start justify-between gap-3">
                        <span>
                            {t.title}
                            {t.count > 1 && ` (${t.count} dates)`}
                        </span>
                        <AccessScopeBadge accessScope={t.access_scope} />
                    </li>
                ))}
                {hidden > 0 && <li className="text-muted-foreground">…and {hidden} more</li>}
            </ul>

            <DialogFooter className="gap-2 sm:gap-2">
                <Button variant="ghost" onClick={onCancel}>
                    Cancel
                </Button>
                {unflaggedIds.length > 0 && (
                    <Button variant="outline" onClick={() => onConfirm(unflaggedIds)}>
                        Approve only the {unflaggedIds.length} fully public
                    </Button>
                )}
                <Button onClick={() => onConfirm(ids)}>Approve all {ids.length}</Button>
            </DialogFooter>
        </>
    );
}
