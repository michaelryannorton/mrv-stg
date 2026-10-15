import { safeHttpUrl } from '@/lib/mrv/html';
import { acceptsEbt, needsReview, regionColor, regionsPresent, reviewNote, SOCIAL_LABELS, type Market } from '@/lib/mrv/markets';
import { Fragment } from 'react';

// The card grid and legend of production's markets.js, as React (class names and structure are production's).

export function MarketLegend({ markets }: { markets: Market[] }) {
    return (
        <>
            {regionsPresent(markets).map((region) => (
                <span key={region} className="map-legend-item">
                    <span className="swatch" style={{ background: regionColor(region) }}></span>
                    {region}
                </span>
            ))}
        </>
    );
}

function TagList({ items, max = 5 }: { items: string[] | null; max?: number }) {
    if (!items || !items.length) return null;
    return (
        <div className="market-tags">
            {items.slice(0, max).map((t, i) => (
                <span key={i} className="market-tag">
                    {String(t).replace(/_/g, ' ')}
                </span>
            ))}
            {items.length > max ? <span className="market-tag market-tag-more">+{items.length - max} more</span> : null}
        </div>
    );
}

function Social({ market }: { market: Market }) {
    if (!market.social_accounts || !market.social_accounts.length) return null;
    return (
        <div className="market-social">
            {market.social_accounts.map((s, i) => (
                <Fragment key={i}>
                    {i > 0 ? ' · ' : null}
                    <a href={safeHttpUrl(s.url)} target="_blank" rel="noopener noreferrer">
                        {SOCIAL_LABELS[s.platform] || s.platform}
                    </a>
                </Fragment>
            ))}
        </div>
    );
}

export function MarketCard({ market: m }: { market: Market }) {
    const website = safeHttpUrl(m.official_url);
    return (
        <div className="card market-card" data-region={m.region}>
            <div className="market-eyebrow" style={{ color: regionColor(m.region) }}>
                {m.city} · {m.region}
            </div>
            <h3>{m.name}</h3>
            <p className="market-schedule">{m.schedule_summary || 'Schedule not yet confirmed'}</p>
            <p className="card-note">
                {m.venue_name || ''}
                {m.venue_name && m.address ? ' · ' : ''}
                {m.address || ''}
            </p>
            {needsReview(m) ? <div className="market-flag">{reviewNote(m)}</div> : null}
            {m.description_short ? <p>{m.description_short}</p> : null}
            <TagList items={m.product_categories} />
            <div className="market-features">{acceptsEbt(m) ? <span className="market-feature">✓ EBT accepted</span> : null}</div>
            {website ? (
                <a className="btn-primary market-btn" href={website} target="_blank" rel="noopener noreferrer">
                    Visit Official Website ↗
                </a>
            ) : null}
            <Social market={m} />
        </div>
    );
}
