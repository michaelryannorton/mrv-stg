import ChannelForm, { type CategoryOption } from '@/components/channel-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface CreateChannelProps {
    categories: CategoryOption[];
    statuses: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Channels', href: '/admin/channels' },
    { title: 'New channel', href: '/admin/channels/create' },
];

export default function CreateChannel({ categories, statuses }: CreateChannelProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New channel" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold">New channel</h1>
                <ChannelForm
                    initial={{ name: '', slug: '', short_description: '', description: '', status: 'draft', sort_order: 0, category_ids: [] }}
                    categories={categories}
                    statuses={statuses}
                    submitLabel="Create channel"
                    slugEditable={false}
                    onSubmit={(form) => form.post(route('admin.channels.store'))}
                />
            </div>
        </AppLayout>
    );
}
