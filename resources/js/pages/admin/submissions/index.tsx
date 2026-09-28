import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface SubmittedData {
    title: string;
    description: string | null;
    start_at: string;
    end_at: string | null;
    timezone: string;
    all_day: boolean;
    location_name_override: string | null;
    address_override: string | null;
    canonical_url: string | null;
    is_free: boolean | null;
    price_min: string | null;
    price_max: string | null;
}

interface Submission {
    id: number;
    name: string | null;
    email: string | null;
    source_url: string | null;
    submitted_data: SubmittedData;
    status: string;
    created_at: string;
}

interface Counts {
    pending: number;
    approved: number;
    rejected: number;
}

type StatusTab = 'pending' | 'approved' | 'rejected';

interface SubmissionsIndexProps {
    submissions: Submission[];
    status: StatusTab;
    counts: Counts;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Submissions', href: '/admin/submissions' }];

const TABS: { value: StatusTab; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
];

function formatWhen(fields: SubmittedData): string {
    const date = new Date(fields.start_at);
    const timeZone = fields.timezone || 'America/Los_Angeles';

    if (Number.isNaN(date.getTime())) return fields.start_at;

    return date.toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: fields.all_day ? undefined : 'numeric',
        minute: fields.all_day ? undefined : '2-digit',
        timeZone,
    });
}

export default function SubmissionsIndex({ submissions, status, counts }: SubmissionsIndexProps) {
    const { flash } = usePage<SharedData>().props;
    const [selected, setSelected] = useState<Set<number>>(new Set());

    useEffect(() => setSelected(new Set()), [status]);

    function toggleRow(id: number) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function act(action: 'approve' | 'reject', ids: number[]) {
        if (ids.length === 0) return;
        router.post(route('admin.submissions.bulk'), { action, ids }, { preserveScroll: true, onSuccess: () => setSelected(new Set()) });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Submissions" />

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
                            href={route('admin.submissions.index', { status: tab.value })}
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

                {selected.size > 0 && (
                    <div className="border-sidebar-border bg-accent/50 flex items-center gap-3 rounded-md border px-4 py-2">
                        <span className="text-sm font-medium">{selected.size} selected</span>
                        {status !== 'approved' && (
                            <Button size="sm" onClick={() => act('approve', Array.from(selected))}>
                                Approve & publish
                            </Button>
                        )}
                        {status !== 'rejected' && (
                            <Button size="sm" variant="destructive" onClick={() => act('reject', Array.from(selected))}>
                                Reject
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
                            Clear
                        </Button>
                    </div>
                )}

                {submissions.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center">
                        {status === 'pending' ? 'Nothing waiting for review right now.' : `No ${status} submissions.`}
                    </p>
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="w-10 px-3 py-2"></th>
                                    <th className="px-3 py-2">Event</th>
                                    <th className="px-3 py-2">When</th>
                                    <th className="px-3 py-2">Where</th>
                                    <th className="px-3 py-2">Submitted by</th>
                                    <th className="px-3 py-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {submissions.map((submission) => (
                                    <tr
                                        key={submission.id}
                                        className="border-sidebar-border/70 dark:border-sidebar-border hover:bg-accent/30 border-b last:border-b-0"
                                    >
                                        <td className="px-3 py-2 align-top">
                                            <Checkbox checked={selected.has(submission.id)} onCheckedChange={() => toggleRow(submission.id)} />
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <div className="font-medium">{submission.submitted_data.title}</div>
                                            {submission.submitted_data.description && (
                                                <p className="text-muted-foreground line-clamp-2 max-w-sm text-xs">
                                                    {submission.submitted_data.description}
                                                </p>
                                            )}
                                            {submission.source_url && (
                                                <a
                                                    href={submission.source_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-muted-foreground text-xs underline underline-offset-2"
                                                >
                                                    original link
                                                </a>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground px-3 py-2 align-top whitespace-nowrap">
                                            {formatWhen(submission.submitted_data)}
                                        </td>
                                        <td className="text-muted-foreground px-3 py-2 align-top">
                                            {submission.submitted_data.location_name_override || '—'}
                                        </td>
                                        <td className="text-muted-foreground px-3 py-2 align-top">
                                            {submission.name || submission.email ? (
                                                <>
                                                    {submission.name}
                                                    {submission.name && submission.email && ' · '}
                                                    {submission.email}
                                                </>
                                            ) : (
                                                'Anonymous'
                                            )}
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <div className="flex gap-1.5">
                                                {status !== 'approved' && (
                                                    <Button size="sm" onClick={() => act('approve', [submission.id])}>
                                                        Approve
                                                    </Button>
                                                )}
                                                {status !== 'rejected' && (
                                                    <Button size="sm" variant="destructive" onClick={() => act('reject', [submission.id])}>
                                                        Reject
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
            </div>
        </AppLayout>
    );
}
