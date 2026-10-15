import { type StatCardData } from '@/lib/mrv/earthquakes';

/** One statistic tile (production: `.card.stat-card` with label, value and a line under it). */
export function StatCard({ label, value, sub }: StatCardData) {
    return (
        <div className="card stat-card">
            <div className="stat-label">{label}</div>
            <div className="stat-value">{value}</div>
            <div className="stat-sub">{sub}</div>
        </div>
    );
}

export function StatCards({ cards }: { cards: StatCardData[] }) {
    return (
        <>
            {cards.map((card, i) => (
                <StatCard key={i} {...card} />
            ))}
        </>
    );
}

/** A panel that shows statistic cards, or a loading or error message in their place (production writes either into the same box). */
export function PanelContent({ panel }: { panel: { kind: 'cards' | 'loading' | 'error'; cards?: StatCardData[]; text?: string } | null }) {
    if (!panel) return null;
    if (panel.kind === 'cards') return <StatCards cards={panel.cards ?? []} />;

    return <div className={panel.kind === 'error' ? 'error-panel' : 'loading-panel'}>{panel.text}</div>;
}
