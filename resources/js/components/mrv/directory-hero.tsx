import { type ReactNode } from 'react';

/** The compact hero the directory pages share (Data Explorer, Farmers Markets, Public Art), with production's inline sizes. */
export function DirectoryHero({ eyebrow, title, children }: { eyebrow: string; title: string; children: ReactNode }) {
    return (
        <section className="hero" style={{ padding: '36px 0' }}>
            <div className="mrv-container">
                <div className="eyebrow">{eyebrow}</div>
                <h1 style={{ fontSize: 'clamp(1.8rem, 3.4vw, 2.6rem)' }}>{title}</h1>
                <p style={{ maxWidth: '640px' }}>{children}</p>
            </div>
        </section>
    );
}

/** Production's map box: a fixed height, rounded and clipped, a little space above. */
export const mapBoxStyle = (height: number) =>
    ({ height: `${height}px`, borderRadius: 'var(--radius)', overflow: 'hidden', marginTop: '14px' }) as const;
