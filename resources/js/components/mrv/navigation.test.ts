import { describe, expect, it } from 'vitest';
import { flatPages, navGroupClass, pageById, PRODUCTION_PREVIEW_NAV, resolveNav, SITE_NAV } from './navigation';

describe('navigation', () => {
    it('writes the classes of a header dropdown group with spaces between them', () => {
        expect(navGroupClass(false, false)).toBe('nav-group');
        expect(navGroupClass(true, false)).toBe('nav-group active');
        expect(navGroupClass(false, true)).toBe('nav-group open');
        expect(navGroupClass(true, true)).toBe('nav-group active open');
    });

    it('marks exactly the ported pages as ready', () => {
        const ready = flatPages(SITE_NAV)
            .filter((p) => p.ready)
            .map((p) => p.id)
            .sort();
        expect(ready).toEqual([
            'airquality',
            'art',
            'channels',
            'climate',
            'data',
            'earthquakes',
            'events',
            'garden',
            'groundwater',
            'home',
            'markets',
            'overview',
            'route66',
            'submit',
        ]);
    });

    it('every ready page names a route (previews carry a literal address instead)', () => {
        for (const page of flatPages(SITE_NAV).filter((p) => p.ready)) expect(page.route, page.id).toBeTruthy();
        for (const page of flatPages(PRODUCTION_PREVIEW_NAV).filter((p) => p.ready)) expect(page.href, page.id).toBeTruthy();
    });

    it("finds a page by id and switches to production's navigation for a preview", () => {
        expect(pageById(SITE_NAV, 'route66')?.route).toBe('route66.index');
        expect(pageById(SITE_NAV, 'nope')).toBeUndefined();
        expect(resolveNav(false)).toEqual({ pages: SITE_NAV, account: true });
        expect(resolveNav(true)).toEqual({ pages: PRODUCTION_PREVIEW_NAV, account: false });
    });
});
