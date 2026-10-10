import ChannelForm, { type CategoryOption } from '@/components/channel-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface EditableChannel {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    short_description: string | null;
    status: string;
    sort_order: number;
    category_ids: number[];
    events_count: number;
    published_events_count: number;
}

interface EditChannelProps {
    channel: EditableChannel;
    categories: CategoryOption[];
    statuses: string[];
}

export default function EditChannel({ channel, categories, statuses }: EditChannelProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Channels', href: '/admin/channels' },
        { title: channel.name, href: `/admin/channels/${channel.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit channel: ${channel.name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Edit channel</h1>
                    <p className="text-muted-foreground text-sm">
                        {channel.events_count} {channel.events_count === 1 ? 'event' : 'events'} in this channel, {channel.published_events_count}{' '}
                        published.
                    </p>
                </div>
                <ChannelForm
                    initial={{
                        name: channel.name,
                        slug: channel.slug,
                        short_description: channel.short_description ?? '',
                        description: channel.description ?? '',
                        status: channel.status,
                        sort_order: channel.sort_order,
                        category_ids: channel.category_ids,
                    }}
                    categories={categories}
                    statuses={statuses}
                    submitLabel="Save channel"
                    slugEditable
                    onSubmit={(form) => form.put(route('admin.channels.update', channel.id))}
                />
            </div>
        </AppLayout>
    );
}
