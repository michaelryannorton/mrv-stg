/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
import { CardGrid, FilterChips, progressNoteText, resultCountText, SiteDetail } from '@/components/mrv/route66';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it } from 'vitest';
import { readGolden } from './golden';
import { buildCardData, ccLicenseInfo, loadRoute66Data, resetRoute66Cache, siteTitle, tierSlug, type Route66Data } from './route66';
import { canon } from './test-helpers/html-canon';

/**
 * Route 66 against the legacy scripts (route66/js/common.js, cards.js, site-detail.js): the golden file holds what they
 * wrote into the page for all 100 real sites, six hand-made ones that reach every license, hero and blank-field branch,
 * and every filter chip. The React components are rendered with production's own addresses (`site.html?id=…`, relative
 * photo paths) and must build the same DOM.
 */
const golden = readGolden<any>('phase3-golden.json.gz');
const inputs = readGolden<any>('phase3-inputs.json.gz');
// React 19's server renderer adds a <link rel="preload"> for each eager image; the browser render does not, so drop them.
const html = (node: React.ReactElement) => renderToStaticMarkup(node).replace(/<link [^>]*rel="preload"[^>]*>/g, '');
const legacyHref = (id: string) => 'site.html?id=' + encodeURIComponent(id);
const dataFor = (name: 'real' | 'synthetic'): Route66Data => ({ sites: inputs.route66[name].sitesFile.sites, media: inputs.route66[name].mediaFile });

describe('Route 66 data files', () => {
    it('are the ones the golden file was made from', () => {
        const dir = path.resolve(__dirname, '../../../../public/route66/data');
        const sha = (f: string) =>
            crypto
                .createHash('sha256')
                .update(fs.readFileSync(path.join(dir, f)))
                .digest('hex');
        // If this fails the data changed after the golden file was made: rerun port/phase-3/tools/make_golden.mjs.
        expect({ sites: sha('sites.json'), media: sha('media_candidates.json') }).toEqual(inputs.dataHashes);
    });
});

describe('cards (cards.js)', () => {
    for (const scenario of ['real', 'synthetic', 'noPhotos'] as const) {
        const data: Route66Data =
            scenario === 'noPhotos' ? { sites: dataFor('real').sites, media: { detailed_candidates_by_site: {} } } : dataFor(scenario);
        const cards = buildCardData(data.sites, data.media);

        for (const step of golden.route66.cards[scenario] as any[]) {
            const tier: string | null = step.tier === 'initial' || step.tier === '' ? null : step.tier;
            it(`${scenario}: filter ${step.tier === 'initial' ? 'as loaded' : step.tier === '' ? 'All' : step.tier}`, () => {
                expect(progressNoteText(cards, data.sites.length)).toBe(step.note);
                expect(canon(html(<FilterChips cards={cards} activeTier={tier} onSelect={() => {}} />))).toBe(canon(step.filter));
                expect(resultCountText(cards, tier)).toBe(step.count);
                expect(canon(html(<CardGrid cards={cards} activeTier={tier} detailHref={legacyHref} mediaBase="" />))).toBe(canon(step.grid));
            });
        }
    }

    it('shows 40 of the 100 real sites, as production does', () => {
        const data = dataFor('real');
        expect(buildCardData(data.sites, data.media)).toHaveLength(40);
    });
});

describe('site detail pages (site-detail.js)', () => {
    const ids = Object.keys(golden.route66.detail).filter((id) => !id.startsWith('__'));

    it('covers all 100 real sites and the six hand-made ones', () => {
        expect(ids).toHaveLength(106);
    });

    for (const id of ids) {
        it(`${id}`, () => {
            const data = id.startsWith('T-') ? dataFor('synthetic') : dataFor('real');
            const site = data.sites.find((s) => s.id === id)!;
            const legacy = golden.route66.detail[id];
            expect(siteTitle(site)).toBe(legacy.title);
            expect(canon(html(<SiteDetail site={site} media={data.media} mediaBase="" />))).toBe(canon(legacy.main));
        });
    }
});

describe('helpers', () => {
    it('license labels and deed addresses', () => {
        expect(ccLicenseInfo('CC BY 2.0')).toEqual({ label: 'CC BY 2.0', url: 'https://creativecommons.org/licenses/by/2.0/' });
        expect(ccLicenseInfo('CC BY-SA 3.0 / 2.5 / GFDL (multi-licensed)')).toEqual({
            label: 'CC BY-SA 3.0',
            url: 'https://creativecommons.org/licenses/by-sa/3.0/',
        });
        expect(ccLicenseInfo('Public domain')).toBeNull();
        expect(ccLicenseInfo(null)).toBeNull();
    });

    it("an unknown tier takes the first tier's color", () => {
        expect(tierSlug('Route Origins')).toBe('origins');
        expect(tierSlug('Mystery Tier')).toBe('historic');
    });
});

describe('loadRoute66Data', () => {
    beforeEach(resetRoute66Cache);

    it('fetches both files once and keeps them', async () => {
        const calls: string[] = [];
        const fake = async (url: string) => {
            calls.push(url);

            return { json: async () => (url.endsWith('sites.json') ? { sites: [1] } : { detailed_candidates_by_site: {} }) };
        };
        const first = await loadRoute66Data(fake);
        const second = await loadRoute66Data(fake);
        expect(calls).toEqual(['/route66/data/sites.json', '/route66/data/media_candidates.json']);
        expect(second).toBe(first);
    });

    it('does not keep a failure', async () => {
        let fail = true;
        const fake = async () => {
            if (fail) throw new Error('offline');

            return { json: async () => ({ sites: [], detailed_candidates_by_site: {} }) };
        };
        await expect(loadRoute66Data(fake)).rejects.toThrow('offline');
        await new Promise((r) => setTimeout(r, 0));
        fail = false;
        await expect(loadRoute66Data(fake)).resolves.toBeTruthy();
    });
});
