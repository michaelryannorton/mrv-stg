/** A review queue row: the dates of one feed's events with the same title (App\Support\ReviewQueue). */
export interface QueueDate {
    id: number;
    start_at: string;
    timezone: string;
    all_day: boolean;
    place: string | null;
    /** Position in the row's descriptions. */
    description: number;
    access_scope: string;
    link: { url: string; kind: 'event' | 'source' } | null;
    original_url: string | null;
    stale: boolean;
}

export interface QueueRow {
    key: string;
    title: string;
    source: string | null;
    organizer: string | null;
    categories: { name: string; slug: string }[];
    tags: { name: string; slug: string }[];
    descriptions: (string | null)[];
    places_differ: boolean;
    descriptions_differ: boolean;
    /** The series already has a published date. */
    approved_before: boolean;
    dates: QueueDate[];
}

export type SortKey = 'start_at' | 'title' | 'place' | 'source';

// Approving any of these asks for a second click first. Deliberately not 'unknown' (most of the queue, so a warning
// there would fire on nearly every approval and stop meaning anything) and not 'public_with_registration' (still open
// to the public, just needs a sign-up).
export const CONFIRM_ACCESS_SCOPES = new Set(['mixed', 'public_with_eligibility']);

export const rowIds = (row: QueueRow): number[] => row.dates.map((d) => d.id);

/** The scope a row is labelled with: one that needs confirming if any date has it, otherwise the first date's. */
export function rowScope(row: QueueRow): string {
    return (row.dates.find((d) => CONFIRM_ACCESS_SCOPES.has(d.access_scope)) ?? row.dates[0]).access_scope;
}

export function rowText(row: QueueRow): string {
    return [
        row.title,
        ...row.descriptions,
        ...row.dates.map((d) => d.place),
        row.organizer,
        row.source,
        ...row.categories.map((c) => c.name),
        ...row.tags.map((t) => t.name),
    ]
        .filter(Boolean)
        .join(' ');
}

export function compareRows(a: QueueRow, b: QueueRow, key: SortKey): number {
    const text = (value: string | null) => (value ?? '').toLowerCase();

    switch (key) {
        case 'start_at':
            return a.dates[0].start_at.localeCompare(b.dates[0].start_at);
        case 'title':
            return text(a.title).localeCompare(text(b.title));
        case 'place':
            return text(a.dates[0].place).localeCompare(text(b.dates[0].place));
        case 'source':
            return text(a.source).localeCompare(text(b.source));
    }
}

/** The rows that hold any of these dates, with how many, for the confirmation dialog. */
export function flaggedByTitle(rows: QueueRow[], ids: Set<number>): { key: string; title: string; count: number; access_scope: string }[] {
    return rows.flatMap((row) => {
        const hit = row.dates.filter((d) => ids.has(d.id));

        return hit.length ? [{ key: row.key, title: row.title, count: hit.length, access_scope: hit[0].access_scope }] : [];
    });
}
