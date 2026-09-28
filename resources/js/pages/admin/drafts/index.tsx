import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface DraftEvent {
    id: number;
    title: string;
    start_at: string;
    timezone: string;
    created_at: string;
}

interface DraftsIndexProps {
    drafts: DraftEvent[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My drafts', href: '/admin/drafts' }];

function formatWhen(event: DraftEvent): string {
    return new Date(event.start_at).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: event.timezone || 'America/Los_Angeles',
    });
}

export default function DraftsIndex({ drafts }: DraftsIndexProps) {
    const { flash } = usePage<SharedData>().props;

    function publish(id: number) {
        router.post(route('admin.drafts.publish', id), {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My drafts" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">My drafts</h1>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('admin.clip.create')}>Clip another event</Link>
                    </Button>
                </div>

                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                {drafts.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center">
                        No parked drafts. Clips saved with "Publish now" unchecked will show up here.
                    </p>
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="px-3 py-2">Title</th>
                                    <th className="px-3 py-2">When</th>
                                    <th className="px-3 py-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {drafts.map((draft) => (
                                    <tr
                                        key={draft.id}
                                        className="border-sidebar-border/70 dark:border-sidebar-border hover:bg-accent/30 border-b last:border-b-0"
                                    >
                                        <td className="px-3 py-2 align-top font-medium">{draft.title}</td>
                                        <td className="text-muted-foreground px-3 py-2 align-top whitespace-nowrap">{formatWhen(draft)}</td>
                                        <td className="px-3 py-2 align-top">
                                            <div className="flex gap-1.5">
                                                <Button size="sm" variant="outline" asChild>
                                                    <Link href={route('admin.events.edit', draft.id)}>Edit</Link>
                                                </Button>
                                                <Button size="sm" onClick={() => publish(draft.id)}>
                                                    Publish
                                                </Button>
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
