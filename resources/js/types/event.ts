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
    start_at: string;
    end_at: string | null;
    timezone: string;
    all_day: boolean;
    is_free: boolean | null;
    /** The venue and its city, otherwise the feed's own location text. */
    place: string | null;
    /** The start of the description, on one line. */
    excerpt: string | null;
    /** The whole description as plain text with paragraphs; show it as text, never as HTML. Null in week and month views. */
    description: string | null;
    /** True when a visitor should check with the organizer that they may attend. */
    check_eligibility: boolean;
    /** The event's own page, or the web page of the calendar it came from. Checked by the server; safe to use as an href. */
    link: { url: string; kind: 'event' | 'source' } | null;
    venue: Venue | null;
    organizer: Organization | null;
    categories: Taxonomy[];
    tags: Taxonomy[];
    audiences: Taxonomy[];
    /** Set on channel pages: the channel's curator featured this event. */
    is_pick?: boolean;
}
