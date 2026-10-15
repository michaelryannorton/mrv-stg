import { DirectoryHero } from '@/components/mrv/directory-hero';
import PublicLayout from '@/layouts/public-layout';
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
        <PublicLayout activeId="channels">
            <Head title="Channels">
                <meta name="description" content="Community events grouped by topic, each followed by its own curators." />
            </Head>

            <DirectoryHero eyebrow="Community" title="Channels">
                Each channel follows one topic across the Mojave River Valley, with events picked and kept current by its curators.
            </DirectoryHero>

            <section className="alt">
                <div className="mrv-container">
                    {channels.length === 0 ? (
                        <div className="card empty-card">
                            <p className="card-note">No channels yet.</p>
                        </div>
                    ) : (
                        <div className="grid-markets grid">
                            {channels.map((channel) => (
                                <Link key={channel.slug} href={route('channels.show', channel.slug)} className="card market-card">
                                    <div className="market-eyebrow" style={{ color: 'var(--color-terracotta-dark)' }}>
                                        {channel.upcoming_count} upcoming {channel.upcoming_count === 1 ? 'event' : 'events'}
                                    </div>
                                    <h3>{channel.name}</h3>
                                    {channel.short_description && <p style={{ margin: 0 }}>{channel.short_description}</p>}
                                    {channel.lead_curator && <p className="card-note">Curated by {channel.lead_curator}</p>}
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
            </section>
        </PublicLayout>
    );
}
