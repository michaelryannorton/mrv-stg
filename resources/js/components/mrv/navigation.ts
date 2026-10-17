// The public site's navigation, one definition for the header and the footer (production kept the same
// list in public_html/js/nav.js, PAGES). Entries are either a single page or a group with a dropdown.
//
// A page is `ready` once it exists in this app. A ready page names its Ziggy route (`route`), or carries a
// literal `href` (only the component sheet in the local environment does that). A page that is not ready
// renders as greyed "coming soon" text and is never linked, so nothing calls route() with a name that does
// not exist. When a page is ported (see the phases in logs/2026 1014 1618 Scoping Full Port Of
// Production Into Laravel.md), set `ready: true` and `route` here; every route a signed-out visitor can
// reach must also be in config/ziggy.php (the `sign_in` or `public` group), and a test checks that.

export interface NavPage {
    id: string;
    label: string;
    ready: boolean;
    /** Ziggy route name; only for pages that exist. */
    route?: string;
    /** Literal address, for previews only. */
    href?: string;
    /** Production lists this page in the footer's Explore list but not in the header (Data Explorer). */
    footerOnly?: boolean;
    /** Hide the entry unless this shared prop is true (Channels shows once a channel is public). */
    requires?: 'public_channels';
}

export interface NavGroup {
    id: string;
    label: string;
    children: NavPage[];
}

export type NavItem = NavPage | NavGroup;

export const isGroup = (item: NavItem): item is NavGroup => 'children' in item;

export const SITE_NAV: NavItem[] = [
    { id: 'home', label: 'Home', ready: true, route: 'home' },
    {
        id: 'geography',
        label: 'Geography',
        children: [
            { id: 'overview', label: 'Overview', ready: true, route: 'overview.index' },
            { id: 'city', label: 'City Explorer', ready: true, route: 'city.index' },
            // Not built on production either (greyed out there): later work items.
            { id: 'compare', label: 'Compare Cities', ready: false },
            { id: 'trends', label: 'Climate Trends', ready: false },
            { id: 'garden', label: 'Garden & Grow', ready: true, route: 'garden.index' },
            { id: 'airquality', label: 'Air Quality', ready: true, route: 'airquality.index' },
            { id: 'earthquakes', label: 'Earthquakes', ready: true, route: 'earthquakes.index' },
            { id: 'groundwater', label: 'Groundwater', ready: true, route: 'groundwater.index' },
            { id: 'climate', label: 'Climate Projections', ready: true, route: 'climate.index' },
        ],
    },
    {
        id: 'attractions',
        label: 'Attractions',
        children: [
            { id: 'art', label: 'Public Art', ready: true, route: 'art.index' },
            { id: 'route66', label: 'Route 66', ready: true, route: 'route66.index' },
            { id: 'markets', label: 'Farmers Markets', ready: true, route: 'markets.index' },
        ],
    },
    // Not on production's navigation: the Community section lives here (decision to confirm with Michael).
    {
        id: 'community',
        label: 'Community',
        children: [
            { id: 'events', label: 'Events', ready: true, route: 'events.index' },
            { id: 'channels', label: 'Channels', ready: true, route: 'channels.index', requires: 'public_channels' },
            { id: 'submit', label: 'Submit an Event', ready: true, route: 'submit.create' },
        ],
    },
    { id: 'recreation', label: 'Recreation', ready: false },
    { id: 'data', label: 'Data Explorer', ready: true, route: 'data.index', footerOnly: true },
];

/**
 * Production's navigation with every page linked, for the local component sheet (`/port/components`), which
 * is compared with the production header and footer. Not used by any real page.
 */
export const PRODUCTION_PREVIEW_NAV: NavItem[] = [
    { id: 'home', label: 'Home', ready: true, href: '#home' },
    {
        id: 'geography',
        label: 'Geography',
        children: [
            { id: 'overview', label: 'Overview', ready: true, href: '#overview' },
            { id: 'city', label: 'City Explorer', ready: true, href: '#city' },
            { id: 'compare', label: 'Compare Cities', ready: false },
            { id: 'trends', label: 'Climate Trends', ready: false },
            { id: 'garden', label: 'Garden & Grow', ready: true, href: '#garden' },
            { id: 'airquality', label: 'Air Quality', ready: true, href: '#airquality' },
            { id: 'earthquakes', label: 'Earthquakes', ready: true, href: '#earthquakes' },
            { id: 'groundwater', label: 'Groundwater', ready: true, href: '#groundwater' },
            { id: 'climate', label: 'Climate Projections', ready: true, href: '#climate' },
        ],
    },
    {
        id: 'attractions',
        label: 'Attractions',
        children: [
            { id: 'art', label: 'Public Art', ready: true, href: '#art' },
            { id: 'route66', label: 'Route 66', ready: true, href: '#route66' },
            { id: 'markets', label: 'Farmers Markets', ready: true, href: '#markets' },
        ],
    },
    { id: 'recreation', label: 'Recreation', ready: false },
    { id: 'data', label: 'Data Explorer', ready: true, href: '#data', footerOnly: true },
];

/** Every page, groups expanded and group headers dropped (the footer's Explore list). */
export const flatPages = (items: NavItem[]): NavPage[] => items.flatMap((item) => (isGroup(item) ? item.children : [item]));

/** The page with this id, wherever it sits in the navigation. */
export const pageById = (items: NavItem[], id: string): NavPage | undefined => flatPages(items).find((page) => page.id === id);

/**
 * The navigation a public page uses. `preview` (only ever true in the local environment, set by the route) is
 * production's navigation with every page linked and no account link: the arrangement the port's comparison
 * with production needs (port/phase-3/tools/compare_pages.mjs).
 */
export const resolveNav = (preview: boolean): { pages: NavItem[]; account: boolean } =>
    preview ? { pages: PRODUCTION_PREVIEW_NAV, account: false } : { pages: SITE_NAV, account: true };

/** The class list of a dropdown group in the header: `nav-group`, plus `active` when a page inside is current and `open` while its menu is shown. */
export const navGroupClass = (hasActiveChild: boolean, open: boolean): string => `nav-group${hasActiveChild ? ' active' : ''}${open ? ' open' : ''}`;
