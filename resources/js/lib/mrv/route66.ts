/**
 * The Route 66 section's data rules: public_html/route66/js/common.js (license and credit text, tiers, which
 * photos count), plus the card and detail rules that sat inside cards.js and site-detail.js. Port phase 3; golden
 * tests run the legacy scripts over all 100 sites and require the same output (route66.test.tsx).
 *
 * The data are two static files, fetched as production does: /route66/data/sites.json and media_candidates.json.
 */

export const ROUTE66_BASE = '/route66/';

export interface Route66Source {
    title: string;
    url?: string | null;
    publisher_or_organization?: string | null;
    authority_level?: string | null;
}

export interface Route66Site {
    id: string;
    name: string;
    alt_names: string[];
    community: string;
    date_or_era: string;
    site_type: string;
    relevance_tier: string;
    route66_relationship: string;
    centennial_story: string;
    notes: string;
    themes: string[];
    people_or_organizations: string[] | null;
    physical_status_raw: string;
    visitability_raw: string;
    current_use: string;
    historic_use: string;
    designation: string;
    owner_or_operator: string;
    alignment_or_corridor: string;
    research_status: string;
    confidence_raw: string;
    last_verified: string;
    sources: Route66Source[] | null;
}

export interface Route66SitesFile {
    sites: Route66Site[];
}

export interface MediaCandidate {
    title: string;
    url?: string | null;
    license?: string | null;
    creator?: string | null;
    role_suggestion?: string | null;
    local_path: string;
    download_status: string;
}

export interface Route66MediaFile {
    detailed_candidates_by_site: Record<string, MediaCandidate[]>;
}

export interface CcInfo {
    label: string;
    url: string;
}

export interface Route66Image {
    /** Relative to the Route 66 folder (`media/AM-001/01-….jpg`), as production's describeImage; prefix with ROUTE66_BASE. */
    path: string;
    isPD: boolean;
    creator: string | null;
    sourceUrl: string | null;
    cc: CcInfo | null;
    title: string;
    role: string;
}

export const TIER_ORDER = ['Route 66 Historic', 'Route Origins', 'Route 66 Today', 'Regional Context', 'Regional Interest'];

const TIER_SLUGS: Record<string, string> = {
    'Route 66 Historic': 'historic',
    'Route Origins': 'origins',
    'Route 66 Today': 'today',
    'Regional Context': 'context',
    'Regional Interest': 'interest',
};

export const tierSlug = (tier: string): string => TIER_SLUGS[tier] || 'historic';

/** local_path is project-root-relative ("route66/media/..."); the pages are in the Route 66 folder, so strip it. */
export const stripPrefix = (localPath: string): string => localPath.replace(/^route66\//, '');

export function isPublicDomain(license: string | null | undefined): boolean {
    const text = license || '';
    return text.indexOf('Public domain') !== -1 || text.indexOf('CC0') !== -1;
}

/** "CC BY 2.0", "CC BY-SA 4.0" and the like: a short label and the matching deed address; null for anything else. */
export function ccLicenseInfo(license: string | null | undefined): CcInfo | null {
    const m = (license || '').match(/CC BY(-SA)?\s+(\d\.\d)/);
    if (!m) return null;
    const isSA = !!m[1];
    const slug = isSA ? 'by-sa' : 'by';
    return {
        label: 'CC ' + (isSA ? 'BY-SA' : 'BY') + ' ' + m[2],
        url: 'https://creativecommons.org/licenses/' + slug + '/' + m[2] + '/',
    };
}

export function describeImage(candidate: MediaCandidate): Route66Image {
    const license = candidate.license || '';
    return {
        path: stripPrefix(candidate.local_path),
        isPD: isPublicDomain(license),
        creator: candidate.creator && candidate.creator !== 'unknown' ? candidate.creator : null,
        sourceUrl: candidate.url || null,
        cc: ccLicenseInfo(license),
        title: candidate.title,
        role: candidate.role_suggestion || '',
    };
}

export function getDownloaded(media: Route66MediaFile, siteId: string): MediaCandidate[] {
    const candidates = media.detailed_candidates_by_site[siteId];
    if (!candidates) return [];
    return candidates.filter((c) => c.download_status === 'downloaded');
}

export function pickHero(downloaded: MediaCandidate[]): MediaCandidate {
    return downloaded.find((c) => (c.role_suggestion || '').toLowerCase().indexOf('hero') !== -1) || downloaded[0];
}

export interface Route66Card {
    id: string;
    name: string;
    community: string;
    tier: string;
    era: string;
    story: string;
    themes: string[];
    image: Route66Image;
    galleryCount: number;
}

/** The sites that have at least one downloaded photo, in file order, as the card view shows them. */
export function buildCardData(sites: Route66Site[], media: Route66MediaFile): Route66Card[] {
    const cards: Route66Card[] = [];
    for (const site of sites) {
        const downloaded = getDownloaded(media, site.id);
        if (downloaded.length === 0) continue;
        cards.push({
            id: site.id,
            name: site.name,
            community: site.community,
            tier: site.relevance_tier,
            era: site.date_or_era,
            story: site.centennial_story,
            themes: site.themes.slice(0, 4),
            image: describeImage(pickHero(downloaded)),
            galleryCount: downloaded.length - 1,
        });
    }
    return cards;
}

/** How many cards each tier has (every tier in TIER_ORDER is present, zero when empty; others are added as found). */
export function tierCounts(cards: Route66Card[]): Record<string, number> {
    const counts: Record<string, number> = {};
    for (const t of TIER_ORDER) counts[t] = 0;
    for (const c of cards) counts[c.tier] = (counts[c.tier] || 0) + 1;
    return counts;
}

/** Production: values like "", "none", "none recorded here", "unknown" are not shown. */
export function isBlank(value: string | null | undefined): boolean {
    if (!value) return true;
    const v = value.trim().toLowerCase();
    return v === '' || v === 'none recorded here' || v === 'none' || v === 'unknown';
}

/** The detail page's photos: the hero (the first photo tagged "hero", else the first) and the rest in order. */
export function splitHeroAndGallery(images: Route66Image[]): { hero: Route66Image | null; gallery: Route66Image[] } {
    let hero = images.length ? images[0] : null;
    let gallery = images.slice(1);
    if (images.length) {
        const heroIdx = images.findIndex((img) => img.role.toLowerCase().indexOf('hero') !== -1);
        if (heroIdx > 0) {
            hero = images[heroIdx];
            gallery = images.slice(0, heroIdx).concat(images.slice(heroIdx + 1));
        }
    }
    return { hero, gallery };
}

/** The "At a glance" facts shown for a site, in production's order, blank ones left out. */
export function siteFacts(site: Route66Site): Array<{ label: string; value: string }> {
    const facts: Array<[string, string | null | undefined]> = [
        ['Physical status', site.physical_status_raw],
        ['Can I visit?', site.visitability_raw],
        ['Current use', site.current_use],
        ['Historic use', site.historic_use],
        ['Designation', site.designation],
        ['Owner / operator', site.owner_or_operator],
        ['Route 66 alignment', site.alignment_or_corridor],
    ];
    if (site.people_or_organizations && site.people_or_organizations.length) {
        facts.push(['People & organizations', site.people_or_organizations.join('; ')]);
    }
    facts.push(['Research status', site.research_status], ['Confidence', site.confidence_raw], ['Last verified', site.last_verified]);
    return facts.filter(([, value]) => !isBlank(value)).map(([label, value]) => ({ label, value: value as string }));
}

/** The page title of a site's detail page. */
export const siteTitle = (site: Route66Site): string => site.name + ' — Route 66 — Mojave River Valley';

// ---- loading ----------------------------------------------------------------------------------------------------------

export interface Route66Data {
    sites: Route66Site[];
    media: Route66MediaFile;
}

let cached: Promise<Route66Data> | null = null;

/**
 * Both files, fetched together as production does. The result is kept for the life of the page so going from the
 * cards to a site (and back) does not fetch them again; a failure is not kept, so the next visit tries again.
 */
export function loadRoute66Data(fetchImpl: (url: string) => Promise<{ json(): Promise<unknown> }> = (u) => fetch(u)): Promise<Route66Data> {
    if (!cached) {
        cached = Promise.all([
            fetchImpl(ROUTE66_BASE + 'data/sites.json').then((r) => r.json() as Promise<Route66SitesFile>),
            fetchImpl(ROUTE66_BASE + 'data/media_candidates.json').then((r) => r.json() as Promise<Route66MediaFile>),
        ]).then(([sitesFile, media]) => ({ sites: sitesFile.sites, media }));
        cached.catch(() => {
            cached = null;
        });
    }
    return cached;
}

/** Test hook: forget the kept data. */
export function resetRoute66Cache(): void {
    cached = null;
}
