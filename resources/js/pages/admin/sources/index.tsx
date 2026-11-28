import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';

interface Feed {
    id: number;
    name: string;
    collector_type: string;
    schedule: string | null;
    feed_host: string | null;
    web_page: string | null;
    web_page_works_as_link: boolean;
    upcoming_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Feeds', href: '/admin/sources' }];

const KINDS: Record<string, string> = {
    ics: 'Calendar feed',
    tribe_events: 'WordPress events',
    squarespace_events: 'Squarespace events',
    growthzone: 'Chamber calendar',
};

function FeedRow({ feed }: { feed: Feed }) {
    const { data, setData, setDefaults, put, processing, errors, isDirty, recentlySuccessful } = useForm({ web_page: feed.web_page ?? '' });
    const inputId = `web-page-${feed.id}`;

    function save(e: FormEvent) {
        e.preventDefault();
        put(route('admin.sources.update', feed.id), { preserveScroll: true, onSuccess: () => setDefaults() });
    }

    return (
        <li className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-2 border-b p-3 last:border-b-0">
            <div>
                <p className="font-medium">{feed.name}</p>
                <p className="text-muted-foreground text-sm">
                    {KINDS[feed.collector_type] ?? feed.collector_type}
                    {feed.feed_host && ` from ${feed.feed_host}`} · {feed.upcoming_count} upcoming ·{' '}
                    {feed.schedule ? `collected ${feed.schedule}` : 'not collected automatically'}
                </p>
                {!feed.web_page_works_as_link && (
                    <p className="text-sm text-amber-700 dark:text-amber-400">
                        No web page: this feed's events show no link unless they carry their own address.
                    </p>
                )}
            </div>
            <form onSubmit={save} className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <label htmlFor={inputId} className="text-sm whitespace-nowrap">
                    Web page
                </label>
                <Input
                    id={inputId}
                    type="url"
                    inputMode="url"
                    placeholder="https://"
                    value={data.web_page}
                    onChange={(e) => setData('web_page', e.target.value)}
                    className="sm:max-w-xl"
                />
                <Button type="submit" size="sm" disabled={processing || !isDirty}>
                    Save
                </Button>
                <span role="status" className="text-sm text-green-700 dark:text-green-400">
                    {recentlySuccessful && 'Saved'}
                </span>
                {feed.web_page_works_as_link && (
                    <a href={feed.web_page as string} target="_blank" rel="noopener noreferrer" className="text-sm underline">
                        Open
                    </a>
                )}
            </form>
            <InputError message={errors.web_page} />
        </li>
    );
}

export default function SourcesIndex({ feeds }: { feeds: Feed[] }) {
    const { flash } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Feeds" />

            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Feeds</h1>
                <p className="text-muted-foreground max-w-prose text-sm">
                    An event with its own address links to it as "More info". An event without one links to its feed's web page as "Source calendar".
                    A change here applies to the feed's events at once.
                </p>

                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                {feeds.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center">No active feeds.</p>
                ) : (
                    <ul className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border">
                        {feeds.map((feed) => (
                            <FeedRow key={feed.id} feed={feed} />
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
