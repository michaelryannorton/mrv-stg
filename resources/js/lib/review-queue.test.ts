import { describe, expect, it } from 'vitest';
import {
    compareRows,
    flaggedByTitle,
    freeNote,
    mapNote,
    NOT_MAPPED,
    ONLINE,
    rowIds,
    rowInCity,
    rowScope,
    rowText,
    type QueueDate,
    type QueueRow,
} from './review-queue';

const date = (id: number, over: Partial<QueueDate> = {}): QueueDate => ({
    id,
    start_at: `2026-12-0${id}T17:00:00Z`,
    timezone: 'America/Los_Angeles',
    all_day: false,
    place: 'Library',
    city: null,
    online: false,
    mapped: false,
    is_free: null,
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
    audiences: [],
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

describe('place and cost notes', () => {
    const date = (over: Record<string, unknown>) => ({ city: 'Hesperia', online: false, mapped: true, is_free: null, ...over });
    const row = (...dates: Record<string, unknown>[]) => ({ dates: dates.map(date) }) as unknown as QueueRow;

    it('says nothing when every date can be mapped', () => {
        expect(mapNote(row({}, {}))).toBeNull();
    });

    it('counts the dates without a map point', () => {
        expect(mapNote(row({ mapped: false }, {}))).toBe('1 without a map point');
        expect(mapNote(row({ mapped: false }))).toBe('No map point');
        expect(mapNote(row({ online: true, mapped: false }))).toBe('Online');
    });

    it('filters by city, online and no map point', () => {
        const mixed = row({ city: 'Victorville' }, { city: null, online: true, mapped: false });

        expect(rowInCity(mixed, 'Victorville')).toBe(true);
        expect(rowInCity(mixed, 'Hesperia')).toBe(false);
        expect(rowInCity(mixed, ONLINE)).toBe(true);
        expect(rowInCity(mixed, NOT_MAPPED)).toBe(false);
    });

    it('says when only some dates are free', () => {
        expect(freeNote(row({ is_free: true }, {}))).toBe('Free on some dates');
        expect(freeNote(row({ is_free: true }))).toBe('Free');
        expect(freeNote(row({}))).toBeNull();
    });
});
