import { describe, expect, it } from 'vitest';
import { compareRows, flaggedByTitle, rowIds, rowScope, rowText, type QueueDate, type QueueRow } from './review-queue';

const date = (id: number, over: Partial<QueueDate> = {}): QueueDate => ({
    id,
    start_at: `2026-12-0${id}T17:00:00Z`,
    timezone: 'America/Los_Angeles',
    all_day: false,
    place: 'Library',
    description: 0,
    access_scope: 'public',
    link: null,
    original_url: null,
    stale: false,
    ...over,
});

const row = (title: string, dates: QueueDate[], over: Partial<QueueRow> = {}): QueueRow => ({
    key: title,
    title,
    source: 'Feed',
    organizer: null,
    categories: [],
    tags: [],
    descriptions: ['Bring a mug'],
    places_differ: false,
    descriptions_differ: false,
    approved_before: false,
    dates,
    ...over,
});

describe('review queue rows', () => {
    it('acts on the listed dates', () => {
        expect(rowIds(row('Coffee', [date(1), date(2)]))).toEqual([1, 2]);
    });

    it('is labelled with a scope that needs confirming when any date has it', () => {
        expect(rowScope(row('A', [date(1), date(2, { access_scope: 'mixed' })]))).toBe('mixed');
        expect(rowScope(row('B', [date(1, { access_scope: 'unknown' })]))).toBe('unknown');
    });

    it("is searched by title, description, any date's place and source", () => {
        const text = rowText(row('Coffee', [date(1), date(2, { place: 'Park' })]));

        for (const word of ['Coffee', 'mug', 'Library', 'Park', 'Feed']) expect(text).toContain(word);
    });

    it('sorts by the first date, title, place or source', () => {
        const a = row('b', [date(1)], { source: 'z' });
        const b = row('A', [date(2, { place: null })], { source: 'a' });

        expect(compareRows(a, b, 'start_at')).toBeLessThan(0);
        expect(compareRows(a, b, 'title')).toBeGreaterThan(0);
        expect(compareRows(a, b, 'place')).toBeGreaterThan(0);
        expect(compareRows(a, b, 'source')).toBeGreaterThan(0);
    });

    it('counts the dates to confirm under each title', () => {
        const rows = [row('Coffee', [date(1, { access_scope: 'mixed' }), date(2, { access_scope: 'mixed' })]), row('Other', [date(3)])];

        expect(flaggedByTitle(rows, new Set([1, 2]))).toEqual([{ key: 'Coffee', title: 'Coffee', count: 2, access_scope: 'mixed' }]);
    });
});
