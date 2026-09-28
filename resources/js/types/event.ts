export interface Taxonomy {
    id: number;
    name: string;
    slug: string;
}

export interface Venue {
    id: number;
    name: string;
    city: string | null;
}

export interface Organization {
    id: number;
    name: string;
}

export interface EventItem {
    id: number;
    uuid: string;
    title: string;
    slug: string;
    short_description: string | null;
    start_at: string;
    end_at: string | null;
    timezone: string;
    all_day: boolean;
    is_free: boolean | null;
    venue: Venue | null;
    organizer: Organization | null;
    categories: Taxonomy[];
    tags: Taxonomy[];
    audiences: Taxonomy[];
}
