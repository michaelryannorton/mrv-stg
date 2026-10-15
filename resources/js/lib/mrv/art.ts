/**
 * The Public Art directory (public_html/js/art.js), ported in port phase 3. THE DATA HERE ARE PLACEHOLDERS, as in
 * the legacy script: twelve fictional entries with plausible values and real regional city coordinates, no claim
 * that any of these installations exist. The real dataset is a later work item; when it exists, `fetchArt` is the
 * one function to replace (field names are those a future `public_art` table would use). Golden tests run the
 * legacy script and require the same output (art.test.tsx).
 */
import { escapeHtml } from './html';
import { type PointMarker } from './leaflet-map';

export interface ArtEntry {
    slug: string;
    title: string;
    art_type: string;
    artist: string;
    city: string;
    venue_name: string;
    address: string;
    latitude: number | null;
    longitude: number | null;
    year_created: number | null;
    status: string;
    materials: string;
    description: string;
}

export interface TypeMeta {
    label: string;
    color: string;
}

// Fixed order: art type is an identity, not a magnitude (same palette as the Data Explorer's networks).
export const TYPE_META: Record<string, TypeMeta> = {
    mural: { label: 'Mural', color: '#C1592F' },
    sculpture: { label: 'Sculpture', color: '#4E7C9B' },
    installation: { label: 'Installation', color: '#7C8A6E' },
    mosaic: { label: 'Mosaic', color: '#C79A3D' },
};
export const FALLBACK_TYPE: TypeMeta = { label: 'Other', color: '#7A4B6B' };

export const typeMeta = (type: string): TypeMeta => TYPE_META[type] || FALLBACK_TYPE;

export const MOCK_ART: ArtEntry[] = [
    {
        slug: 'route-66-renaissance',
        title: 'Route 66 Renaissance',
        art_type: 'mural',
        artist: 'Artist TBD',
        city: 'Victorville',
        venue_name: 'Old Town Victorville',
        address: 'D Street, Victorville, CA',
        latitude: 34.5395,
        longitude: -117.2965,
        year_created: 2019,
        status: 'active',
        materials: 'Painted mural on brick',
        description: 'A colorful mural celebrating Victorville’s history along historic Route 66.',
    },
    {
        slug: 'desert-bloom',
        title: 'Desert Bloom',
        art_type: 'sculpture',
        artist: 'Artist TBD',
        city: 'Apple Valley',
        venue_name: 'Apple Valley Town Hall Plaza',
        address: 'Apple Valley Town Hall, Apple Valley, CA',
        latitude: 34.5024,
        longitude: -117.1875,
        year_created: 2015,
        status: 'active',
        materials: 'Welded steel',
        description: 'A large welded-steel sculpture depicting a blooming yucca, the desert’s signature plant.',
    },
    {
        slug: 'high-desert-skyline',
        title: 'High Desert Skyline',
        art_type: 'mural',
        artist: 'Artist TBD',
        city: 'Hesperia',
        venue_name: 'Hesperia Civic Plaza',
        address: 'Civic Plaza Dr, Hesperia, CA',
        latitude: 34.4145,
        longitude: -117.3048,
        year_created: 2021,
        status: 'active',
        materials: 'Painted mural',
        description: 'A mural depicting the mountain skyline visible from Hesperia’s civic center.',
    },
    {
        slug: 'rails-and-trails',
        title: 'Rails & Trails',
        art_type: 'installation',
        artist: 'Artist TBD',
        city: 'Barstow',
        venue_name: 'Barstow Harvey House',
        address: '681 N 1st Ave, Barstow, CA',
        latitude: 34.8975,
        longitude: -117.0225,
        year_created: 2018,
        status: 'active',
        materials: 'Welded steel',
        description: 'A metal art installation referencing the area’s railroad history at the historic Harvey House.',
    },
    {
        slug: 'alpine-reflections',
        title: 'Alpine Reflections',
        art_type: 'sculpture',
        artist: 'Artist TBD',
        city: 'Big Bear Lake',
        venue_name: 'Big Bear Village',
        address: 'Village Dr, Big Bear Lake, CA',
        latitude: 34.2445,
        longitude: -116.9115,
        year_created: 2012,
        status: 'active',
        materials: 'Bronze',
        description: 'A small sculpture walk through Big Bear Village featuring wildlife-themed bronze pieces.',
    },
    {
        slug: 'joshua-tree-guardians',
        title: 'Joshua Tree Guardians',
        art_type: 'installation',
        artist: 'Artist TBD',
        city: 'Joshua Tree',
        venue_name: 'Roadside installation, Hwy 62',
        address: 'Twentynine Palms Hwy, Joshua Tree, CA',
        latitude: 34.1365,
        longitude: -116.3145,
        year_created: 2016,
        status: 'active',
        materials: 'Welded rebar and steel',
        description: 'Large-scale sculptural figures echoing the Joshua tree’s silhouette along the highway.',
    },
    {
        slug: 'mojave-river-mosaic',
        title: 'Mojave River Mosaic',
        art_type: 'mosaic',
        artist: 'Artist TBD',
        city: 'Victorville',
        venue_name: 'Mojave Narrows Regional Park',
        address: '18000 Yates Rd, Victorville, CA',
        latitude: 34.4886,
        longitude: -117.2765,
        year_created: 2020,
        status: 'active',
        materials: 'Ceramic tile mosaic',
        description: 'A tile mosaic at the park entrance depicting the Mojave River ecosystem.',
    },
    {
        slug: 'windmills-of-the-valley',
        title: 'Windmills of the Valley',
        art_type: 'sculpture',
        artist: 'Artist TBD',
        city: 'Palmdale',
        venue_name: 'Palmdale Amphitheater grounds',
        address: '2723 Rancho Vista Blvd, Palmdale, CA',
        latitude: 34.576,
        longitude: -118.1187,
        year_created: 2014,
        status: 'active',
        materials: 'Kinetic metal',
        description: 'A kinetic wind sculpture referencing the Antelope Valley’s wind farms.',
    },
    {
        slug: 'poppy-fields-forever',
        title: 'Poppy Fields Forever',
        art_type: 'mural',
        artist: 'Artist TBD',
        city: 'Lancaster',
        venue_name: 'The BLVD',
        address: 'W Lancaster Blvd, Lancaster, CA',
        latitude: 34.6975,
        longitude: -118.139,
        year_created: 2017,
        status: 'active',
        materials: 'Painted mural',
        description: 'A mural of California poppies, a nod to the nearby Antelope Valley Poppy Reserve.',
    },
    {
        slug: 'cajon-pass-gateway',
        title: 'Cajon Pass Gateway',
        art_type: 'sculpture',
        artist: 'Artist TBD',
        city: 'Wrightwood',
        venue_name: 'Cajon Pass roadside pullout',
        address: 'Historic Route 66, Cajon Pass, CA',
        latitude: 34.358,
        longitude: -117.468,
        year_created: 2013,
        status: 'active',
        materials: 'Stone and metal',
        description: 'A stone-and-metal gateway sculpture marking the pass between the desert and the valley.',
    },
    {
        slug: 'adelanto-agricultural-heritage',
        title: 'Adelanto Agricultural Heritage',
        art_type: 'mural',
        artist: 'Artist TBD',
        city: 'Adelanto',
        venue_name: 'Adelanto City Hall',
        address: '11600 Air Expressway Blvd, Adelanto, CA',
        latitude: 34.5798,
        longitude: -117.4095,
        year_created: 2022,
        status: 'active',
        materials: 'Painted mural',
        description: 'A mural celebrating the region’s dairy and agricultural heritage.',
    },
    {
        slug: 'lucerne-valley-land-art',
        title: 'Lucerne Valley Land Art',
        art_type: 'installation',
        artist: 'Artist TBD',
        city: 'Lucerne Valley',
        venue_name: 'Open desert, off Hwy 18',
        address: 'Hwy 18, Lucerne Valley, CA',
        latitude: 34.439,
        longitude: -116.942,
        year_created: 2011,
        status: 'seasonal',
        materials: 'Earth and stone',
        description: 'A large-scale earthwork best visited outside the height of summer heat.',
    },
];

/** Placeholder source. Replace with a query.php call when a `public_art` table exists; nothing else changes. */
export async function fetchArt(): Promise<ArtEntry[]> {
    return MOCK_ART;
}

export type ArtView = 'card' | 'table' | 'map';

export const VIEW_OPTIONS: Array<{ key: ArtView; label: string }> = [
    { key: 'card', label: 'Card View' },
    { key: 'table', label: 'Table View' },
    { key: 'map', label: 'Map View' },
];

/** The note shown in the card eyebrow and table: nothing for an active piece. */
export const statusText = (status: string): string => (status === 'seasonal' ? 'Seasonal access' : status);

export const artCountNote = (count: number): string => `Showing ${count} sample entries — placeholder data, a real dataset is coming soon.`;

/** Types present, in the fixed order. */
export function typesPresent(art: ArtEntry[]): string[] {
    const order = Object.keys(TYPE_META);
    return [...new Set(art.map((a) => a.art_type))].sort((x, y) => order.indexOf(x) - order.indexOf(y));
}

export function mappableArt(art: ArtEntry[]): ArtEntry[] {
    return art.filter((a) => a.latitude != null && a.longitude != null);
}

export function artPopupHtml(a: ArtEntry): string {
    const meta = typeMeta(a.art_type);
    return `
      <strong>${escapeHtml(a.title)}</strong><br>
      <span class="card-note">${escapeHtml(meta.label)} · ${escapeHtml(a.venue_name)}, ${escapeHtml(a.city)}</span>
      ${a.year_created ? `<br><span class="card-note">${a.year_created}</span>` : ''}
    `;
}

export function artPins(art: ArtEntry[]): PointMarker[] {
    return mappableArt(art).map((a) => ({
        lat: a.latitude as number,
        lon: a.longitude as number,
        radius: 9,
        fillColor: typeMeta(a.art_type).color,
        fillOpacity: 0.85,
        tooltip: escapeHtml(a.title),
        popup: artPopupHtml(a),
    }));
}
