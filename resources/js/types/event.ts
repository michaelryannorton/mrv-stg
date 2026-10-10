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
    /** Absolute http(s) address of the event's own page, or null. Set by the server; safe to use as an href. */
    details_url: string | null;
    venue: Venue | null;
    organizer: Organization | null;
    categories: Taxonomy[];
    tags: Taxonomy[];
    audiences: Taxonomy[];
    /** Set on channel pages: the channel's curator featured this event. */
    is_pick?: boolean;
}
