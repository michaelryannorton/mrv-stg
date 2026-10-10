import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface CurateChannel {
    id: number;
    name: string;
    slug: string;
    short_description: string | null;
    status: string;
    assignment_role: string | null;
    upcoming_count: number;
    featured_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Curate', href: '/curate' }];

export default function CurateIndex({ channels }: { channels: CurateChannel[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curate" />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Your channels</h1>
                    <p className="text-muted-foreground text-sm">
                        Feature the events that deserve a highlight, and take out the ones that do not belong. Your picks appear on the channel&apos;s
                        page once the event is published.
                    </p>
                </div>

                {channels.length === 0 ? (
                    <div className="border-sidebar-border/70 text-muted-foreground rounded-md border p-8 text-center text-sm">
                        You are not assigned to a channel yet. The administrator assigns channels.
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {channels.map((channel) => (
                            <div key={channel.id} className="border-sidebar-border/70 flex flex-col gap-3 rounded-md border p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <h2 className="font-semibold">{channel.name}</h2>
                                        {channel.short_description && <p className="text-muted-foreground text-sm">{channel.short_description}</p>}
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        {channel.status !== 'active' && <Badge variant="secondary">{channel.status}</Badge>}
                                        {channel.assignment_role && <Badge variant="outline">{channel.assignment_role}</Badge>}
                                    </div>
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {channel.upcoming_count} upcoming {channel.upcoming_count === 1 ? 'event' : 'events'}, {channel.featured_count}{' '}
                                    featured
                                </p>
                                <div>
                                    <Button asChild size="sm">
                                        <Link href={route('curate.channels.show', channel.slug)}>Open</Link>
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
