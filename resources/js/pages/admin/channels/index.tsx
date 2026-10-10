import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

interface ChannelRow {
    id: number;
    name: string;
    slug: string;
    short_description: string | null;
    status: string;
    categories: { id: number; name: string }[];
    events_count: number;
    published_events_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Channels', href: '/admin/channels' }];

export default function ChannelsIndex({ channels }: { channels: ChannelRow[] }) {
    const { flash } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Channels" />

            <div className="flex flex-col gap-4 p-4">
                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Channels</h1>
                        <p className="text-muted-foreground text-sm">
                            A channel gathers events from its linked categories. Curators add and remove individual events later.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={route('admin.channels.create')}>
                            <Plus className="size-4" />
                            New channel
                        </Link>
                    </Button>
                </div>

                {channels.length === 0 ? (
                    <div className="border-sidebar-border/70 text-muted-foreground rounded-md border p-8 text-center text-sm">
                        No channels yet. Create one once you know who will curate it, so its public page is not empty.
                    </div>
                ) : (
                    <div className="border-sidebar-border/70 overflow-x-auto rounded-md border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-sidebar-border/70 bg-muted/40 text-muted-foreground border-b text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Channel</th>
                                    <th className="px-3 py-2 font-medium">Status</th>
                                    <th className="px-3 py-2 font-medium">Categories</th>
                                    <th className="px-3 py-2 text-right font-medium">Events</th>
                                    <th className="px-3 py-2 text-right font-medium">Published</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {channels.map((channel) => (
                                    <tr key={channel.id} className="border-sidebar-border/50 border-b last:border-0">
                                        <td className="px-3 py-2">
                                            <div className="font-medium">{channel.name}</div>
                                            <div className="text-muted-foreground text-xs">/channels/{channel.slug}</div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <Badge variant={channel.status === 'active' ? 'default' : 'secondary'}>{channel.status}</Badge>
                                        </td>
                                        <td className="px-3 py-2">
                                            {channel.categories.length === 0 ? (
                                                <span className="text-muted-foreground">None (curated picks only)</span>
                                            ) : (
                                                channel.categories.map((category) => category.name).join(', ')
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">{channel.events_count}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{channel.published_events_count}</td>
                                        <td className="px-3 py-2 text-right">
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={route('admin.channels.edit', channel.id)}>Edit</Link>
                                            </Button>
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
