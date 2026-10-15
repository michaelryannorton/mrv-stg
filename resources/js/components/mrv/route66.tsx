import {
    ROUTE66_BASE,
    TIER_ORDER,
    describeImage,
    getDownloaded,
    isBlank,
    siteFacts,
    splitHeroAndGallery,
    tierCounts,
    tierSlug,
    type Route66Card,
    type Route66Image,
    type Route66MediaFile,
    type Route66Site,
} from '@/lib/mrv/route66';
import { Link } from '@inertiajs/react';

// The markup of production's route66/js/cards.js, common.js and site-detail.js, as React. Class names and structure
// are production's (resources/css/mrv-route66.css styles them). The parts that depend on routes take their addresses
// as props, so the golden tests can render them with production's own addresses and compare the result.

const DOT = (tier: string) => <span className="dot" style={{ background: `var(--tier-${tierSlug(tier)})` }}></span>;

export function LicenseBadge({ img }: { img: Route66Image }) {
    if (img.isPD) {
        return img.sourceUrl ? (
            <a className="pd-badge" href={img.sourceUrl} target="_blank" rel="noopener">
                Public domain
            </a>
        ) : (
            <span className="pd-badge">Public domain</span>
        );
    }
    if (img.cc) {
        return (
            <a className="pd-badge" href={img.cc.url} target="_blank" rel="license noopener">
                {img.cc.label}
            </a>
        );
    }
    return <span className="pd-badge">License unclear</span>;
}

export function CreditLine({ img }: { img: Route66Image }) {
    if (!img.creator) return null;
    const text = 'Photo: ' + img.creator;
    return (
        <div className="card-credit">
            {img.sourceUrl ? (
                <a href={img.sourceUrl} target="_blank" rel="noopener">
                    {text}
                </a>
            ) : (
                text
            )}
            {img.cc ? (
                <>
                    {' · '}
                    <a href={img.cc.url} target="_blank" rel="license noopener">
                        {img.cc.label}
                    </a>
                </>
            ) : img.isPD ? (
                ' · public domain'
            ) : null}
        </div>
    );
}

const TierBadge = ({ tier }: { tier: string }) => (
    <span className="tier-badge">
        {DOT(tier)}
        {tier}
    </span>
);

/** The row of filter chips: "All" and each tier that has a card. */
export function FilterChips({
    cards,
    activeTier,
    onSelect,
}: {
    cards: Route66Card[];
    activeTier: string | null;
    onSelect: (tier: string | null) => void;
}) {
    const counts = tierCounts(cards);
    return (
        <>
            <span className="filter-label">Filter by relevance:</span>
            <button className={'chip' + (activeTier === null ? ' active' : '')} data-tier="" onClick={() => onSelect(null)}>
                All ({cards.length})
            </button>
            {TIER_ORDER.map((tier) =>
                counts[tier] ? (
                    <button key={tier} className={'chip' + (activeTier === tier ? ' active' : '')} data-tier={tier} onClick={() => onSelect(tier)}>
                        <span className="dot"></span>
                        {tier} ({counts[tier]})
                    </button>
                ) : null,
            )}
        </>
    );
}

export function SiteCard({ card, detailHref, mediaBase = ROUTE66_BASE }: { card: Route66Card; detailHref: string; mediaBase?: string }) {
    // The license badge and credit-line links go off-site and must not sit inside the card's own link to the detail
    // page (nested links are invalid): the detail link wraps only the image and the body, the badge is a sibling.
    return (
        <article className="site-card">
            <div className="card-image">
                <Link href={detailHref} aria-label={`View details for ${card.name}`}>
                    <img src={mediaBase + card.image.path} alt={card.name} loading="lazy" />
                </Link>
                <TierBadge tier={card.tier} />
                <LicenseBadge img={card.image} />
            </div>
            <CreditLine img={card.image} />
            <Link className="card-body-link" href={detailHref}>
                <div className="card-body">
                    <h3>{card.name}</h3>
                    <div className="card-meta">
                        {card.community}
                        {card.era ? (
                            <>
                                <span className="sep">·</span>
                                {card.era}
                            </>
                        ) : null}
                    </div>
                    <p className="card-story">{card.story}</p>
                    <div className="card-themes">
                        {card.themes.map((t) => (
                            <span key={t} className="theme-tag">
                                {t}
                            </span>
                        ))}
                    </div>
                </div>
            </Link>
        </article>
    );
}

export function CardGrid({
    cards,
    activeTier,
    detailHref,
    mediaBase,
}: {
    cards: Route66Card[];
    activeTier: string | null;
    detailHref: (id: string) => string;
    mediaBase?: string;
}) {
    const visible = activeTier ? cards.filter((c) => c.tier === activeTier) : cards;
    if (visible.length === 0) return <div className="empty-state">No sites match this filter yet.</div>;
    return (
        <>
            {visible.map((card) => (
                <SiteCard key={card.id} card={card} detailHref={detailHref(card.id)} mediaBase={mediaBase} />
            ))}
        </>
    );
}

export const resultCountText = (cards: Route66Card[], activeTier: string | null): string => {
    const visible = activeTier ? cards.filter((c) => c.tier === activeTier).length : cards.length;
    return 'Showing ' + visible + ' of ' + cards.length + ' sites with a photo';
};

export const progressNoteText = (cards: Route66Card[], siteCount: number): string =>
    cards.length + ' of ' + siteCount + ' top-100 sites have a downloaded, license-checked photo so far';

// ---- the detail page ----------------------------------------------------------------------------------------------

const ThemeTags = ({ themes }: { themes: string[] | null | undefined }) =>
    themes && themes.length ? (
        <>
            {themes.map((t) => (
                <span key={t} className="theme-tag">
                    {t}
                </span>
            ))}
        </>
    ) : null;

function Gallery({ images, mediaBase }: { images: Route66Image[]; mediaBase: string }) {
    if (images.length === 0) return null;
    return (
        <section className="detail-section">
            <h2>Photos</h2>
            <div className="gallery-grid">
                {images.map((img, i) => (
                    <figure key={img.path} className="gallery-item">
                        <img src={mediaBase + img.path} alt={img.title} loading={i === 0 ? 'eager' : 'lazy'} />
                        <figcaption>
                            <LicenseBadge img={img} />
                            <CreditLine img={img} />
                        </figcaption>
                    </figure>
                ))}
            </div>
        </section>
    );
}

function Sources({ sources }: { sources: Route66Site['sources'] }) {
    if (!sources || sources.length === 0) {
        return (
            <section className="detail-section">
                <h2>Sources</h2>
                <p className="card-note">No sources resolved for this record yet.</p>
            </section>
        );
    }
    return (
        <section className="detail-section">
            <h2>Sources ({sources.length})</h2>
            <ul className="source-list">
                {sources.map((s, i) => {
                    const meta = [s.publisher_or_organization, s.authority_level].filter((x) => !isBlank(x)).join(' · ');
                    return (
                        <li key={i}>
                            <span className="source-title">
                                {s.url ? (
                                    <a href={s.url} target="_blank" rel="noopener">
                                        {s.title}
                                    </a>
                                ) : (
                                    s.title
                                )}
                            </span>
                            {meta ? <span className="source-meta">{meta}</span> : null}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** The body of a site's detail page: hero, title block, about, photos, sources, and the "at a glance" sidebar. */
export function SiteDetail({ site, media, mediaBase = ROUTE66_BASE }: { site: Route66Site; media: Route66MediaFile; mediaBase?: string }) {
    const images = getDownloaded(media, site.id).map(describeImage);
    const { hero, gallery } = splitHeroAndGallery(images);
    const altNames = site.alt_names.filter((n) => n !== site.name);
    const facts = siteFacts(site);

    return (
        <>
            <div className="detail-hero">
                {hero ? (
                    <>
                        <div className="detail-hero-image">
                            <img src={mediaBase + hero.path} alt={site.name} />
                            <TierBadge tier={site.relevance_tier} />
                            <LicenseBadge img={hero} />
                        </div>
                        <CreditLine img={hero} />
                    </>
                ) : (
                    <div className="detail-hero-image detail-hero-noimage">
                        <TierBadge tier={site.relevance_tier} />
                        <span className="no-image-note">No photo on file yet</span>
                    </div>
                )}
            </div>

            <div className="detail-title-block">
                <h1>{site.name}</h1>
                {altNames.length ? <p className="alt-names">Also known as: {altNames.join('; ')}</p> : null}
                <div className="card-meta">
                    {site.community}
                    {site.date_or_era ? (
                        <>
                            <span className="sep">·</span>
                            {site.date_or_era}
                        </>
                    ) : null}
                    <span className="sep">·</span>
                    {site.site_type}
                </div>
                <div className="card-themes" style={{ marginTop: '10px' }}>
                    <ThemeTags themes={site.themes} />
                </div>
            </div>

            <div className="detail-columns">
                <div className="detail-main-col">
                    <section className="detail-section">
                        <h2>About this site</h2>
                        <p>{site.route66_relationship}</p>
                        {!isBlank(site.centennial_story) && site.centennial_story !== site.route66_relationship ? (
                            <p>{site.centennial_story}</p>
                        ) : null}
                        {!isBlank(site.notes) ? (
                            <p className="detail-notes">
                                <strong>Note:</strong> {site.notes}
                            </p>
                        ) : null}
                    </section>
                    <Gallery images={gallery} mediaBase={mediaBase} />
                    <Sources sources={site.sources} />
                </div>

                <aside className="detail-sidebar">
                    <h2>At a glance</h2>
                    <dl className="fact-list">
                        {facts.map((f) => (
                            <div key={f.label} className="fact">
                                <dt>{f.label}</dt>
                                <dd>{f.value}</dd>
                            </div>
                        ))}
                    </dl>
                </aside>
            </div>
        </>
    );
}
