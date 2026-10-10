import PublicHeader from '@/components/public-header';
import { Head, Link } from '@inertiajs/react';

interface ChannelRow {
    name: string;
    slug: string;
    short_description: string | null;
    lead_curator: string | null;
    upcoming_count: number;
}

export default function ChannelsIndex({ channels }: { channels: ChannelRow[] }) {
    return (
        <>
            <Head title="Channels">
                <meta name="description" content="Community events grouped by topic, each followed by its own curators." />
            </Head>

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <PublicHeader />

                <main className="mx-auto max-w-4xl px-6 py-10">
                    <h1 className="mb-2 text-2xl font-semibold">Channels</h1>
                    <p className="mb-8 text-[#706f6c] dark:text-[#A1A09A]">
                        Each channel follows one topic across the Mojave River Valley, with events picked and kept current by its curators.
                    </p>

                    {channels.length === 0 ? (
                        <p className="rounded-md border border-dashed border-[#e3e3e0] p-8 text-center text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                            No channels yet.
                        </p>
                    ) : (
                        <ul className="grid gap-4">
                            {channels.map((channel) => (
                                <li key={channel.slug}>
                                    <Link
                                        href={route('channels.show', channel.slug)}
                                        className="block rounded-md border border-[#e3e3e0] p-5 hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:hover:bg-[#1a1a19]"
                                    >
                                        <div className="flex items-start justify-between gap-4">
                                            <h2 className="text-lg font-medium">{channel.name}</h2>
                                            <span className="shrink-0 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                {channel.upcoming_count} upcoming {channel.upcoming_count === 1 ? 'event' : 'events'}
                                            </span>
                                        </div>
                                        {channel.short_description && <p className="mt-1 text-sm">{channel.short_description}</p>}
                                        {channel.lead_curator && (
                                            <p className="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Curated by {channel.lead_curator}</p>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </main>
            </div>
        </>
    );
}
