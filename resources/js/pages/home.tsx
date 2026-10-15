import { pageById, resolveNav } from '@/components/mrv/navigation';
import { PageLink } from '@/components/mrv/page-links';
import PublicLayout from '@/layouts/public-layout';
import { Head } from '@inertiajs/react';

// The front page (production: public_html/index.html), with its markup and copy unchanged. Links to pages that are
// not ported yet show as muted text until the page's `ready` flag in components/mrv/navigation.ts is set.

const DESCRIPTION =
    'Weather, geography, and attractions across the Mojave River Valley — a starting point for locals to explore the place they already call home.';

function Doorway({ pages, id, label }: { pages: ReturnType<typeof resolveNav>['pages']; id: string; label?: string }) {
    const text = label ?? pageById(pages, id)?.label ?? id;

    return (
        <PageLink pages={pages} id={id} soon={<span className="doorway-soon">{text}</span>}>
            {text}
        </PageLink>
    );
}

interface SpotlightProps {
    pages: ReturnType<typeof resolveNav>['pages'];
    id: string;
    className?: string;
    tag: string;
    title: string;
    text: string;
    image?: { src: string; alt: string };
}

function Spotlight({ pages, id, className = '', tag, title, text, image }: SpotlightProps) {
    const body = (
        <>
            {image ? <img src={image.src} alt={image.alt} /> : null}
            <div className="spotlight-body">
                <div className="spotlight-tag">{tag}</div>
                <h3>{title}</h3>
                <p>{text}</p>
            </div>
        </>
    );

    return (
        <PageLink
            pages={pages}
            id={id}
            className={`spotlight-card ${className}`.trim()}
            soon={<div className={`spotlight-card spotlight-soon ${className}`.trim()}>{body}</div>}
        >
            {body}
        </PageLink>
    );
}

export default function Home({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);

    return (
        <PublicLayout activeId="home" pages={pages} account={account}>
            <Head title="Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <section className="topo-hero">
                <div className="mrv-container topo-hero-grid">
                    <div className="topo-hero-copy">
                        <div className="eyebrow">Mojave River Valley</div>
                        <h1>How Well Do You Really Know This Place?</h1>
                        <p className="lede">
                            It's easy to drive the same roads for years without noticing what's actually here — a Harvey House depot, a bottle-tree
                            forest, a volcanic crater under a dark sky, murals a block off Main Street. This site is a starting point for looking
                            closer at home.
                        </p>
                    </div>
                    <figure className="topo-hero-photo">
                        <img
                            src="/route66/media/AM-004/02-amboy-crater-below-the-night-sky-52451571479.jpg"
                            alt="Amboy Crater beneath a star-filled night sky"
                        />
                        <figcaption>Amboy Crater, off Route 66 &middot; Photo: Kyle Sullivan, BLM &middot; public domain</figcaption>
                    </figure>
                </div>
            </section>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Two Ways In</h2>
                        <p>Pick a starting point &mdash; it all connects back to the same valley.</p>
                    </div>
                    <div className="doorway-grid">
                        <div className="doorway-card">
                            <div className="doorway-kicker">The physical valley</div>
                            <h3>Geography</h3>
                            <p>
                                Ten years of daily weather by city, frost dates for your garden, air quality trends, and the fault lines running under
                                all of it.
                            </p>
                            <div className="doorway-links">
                                <Doorway pages={pages} id="overview" />
                                <Doorway pages={pages} id="city" />
                                <Doorway pages={pages} id="garden" />
                                <Doorway pages={pages} id="airquality" />
                                <Doorway pages={pages} id="earthquakes" />
                                <Doorway pages={pages} id="groundwater" />
                                <Doorway pages={pages} id="climate" />
                            </div>
                        </div>
                        <div className="doorway-card">
                            <div className="doorway-kicker">Where to actually go</div>
                            <h3>Attractions</h3>
                            <p>
                                Murals and sculpture worth a detour, the Route 66 corridor's centennial history, and the farmers markets worth
                                building a Saturday around.
                            </p>
                            <div className="doorway-links">
                                <Doorway pages={pages} id="art" />
                                <Doorway pages={pages} id="route66" />
                                <Doorway pages={pages} id="markets" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>A Few Places to Start</h2>
                        <p>Four ways into the valley, picked to surprise you.</p>
                    </div>
                    <div className="spotlight-grid">
                        <Spotlight
                            pages={pages}
                            id="route66"
                            tag="Route 66 Centennial"
                            title="Every Mile Marker Has a Story"
                            text="2026 marks 100 years of Route 66 — and the High Desert stretch is full of it, mile by mile."
                            image={{
                                src: '/route66/media/ND-011/01-route-66-mural-in-needles-california-lccn2013630406.jpg',
                                alt: 'A Route 66 mural in Needles, California',
                            }}
                        />
                        <Spotlight
                            pages={pages}
                            id="art"
                            className="spotlight-plain spotlight-art"
                            tag="Public Art"
                            title="Murals You've Probably Driven Past"
                            text="A growing directory of the valley's murals, sculpture, and installations."
                        />
                        <Spotlight
                            pages={pages}
                            id="garden"
                            className="spotlight-plain spotlight-garden"
                            tag="Garden & Grow"
                            title="When Does Your Last Frost Actually Happen?"
                            text="Ten years of frost dates and irrigation demand, city by city."
                        />
                        <Spotlight
                            pages={pages}
                            id="earthquakes"
                            className="spotlight-plain spotlight-quake"
                            tag="Earthquakes"
                            title="What's Moving Under the Valley Right Now"
                            text="Recent seismic activity across the region, mapped and tracked."
                        />
                    </div>
                </div>
            </section>

            <section>
                <div className="mrv-container topo-origin">
                    <h2>Why This Site Exists</h2>
                    <p>
                        This started as a weather project &mdash; ten years of daily records for towns across the valley. It grew from there, because
                        the same curiosity that wants to know if frost is coming also wants to know what that depot downtown used to be, or why
                        there's a crater fifteen miles past Amboy. One question tends to lead to the next. This site is built to make that easy.
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
