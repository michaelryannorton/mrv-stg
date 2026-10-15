import DayView from '@/components/calendar/day-view';
import MonthView from '@/components/calendar/month-view';
import WeekView from '@/components/calendar/week-view';
import EventCard from '@/components/event-card';
import { DirectoryHero } from '@/components/mrv/directory-hero';
import PaginationLinks from '@/components/pagination-links';
import PublicLayout from '@/layouts/public-layout';
import { formatDayLabel, formatMonthLabel, formatWeekRangeLabel } from '@/lib/calendar';
import { classes } from '@/lib/mrv/classes';
import { type EventItem, type Taxonomy } from '@/types/event';
import { Head, router } from '@inertiajs/react';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta?: { current_page: number; last_page: number };
}

interface Filters {
    when?: string;
    category?: string;
    audience?: string;
    free?: boolean;
    city?: string;
}

type ViewKey = 'list' | 'day' | 'week' | 'month';

interface CalendarMeta {
    anchorDate: string;
    gridStart: string;
    gridEnd: string;
    prevDate: string;
    nextDate: string;
    today: string;
}

interface EventsIndexProps {
    events: Paginated<EventItem> | EventItem[];
    filters: Filters;
    categories: Taxonomy[];
    audiences: Taxonomy[];
    view: ViewKey;
    calendar: CalendarMeta | null;
}

const WHEN_OPTIONS = [
    { value: 'any', label: 'Any time' },
    { value: 'today', label: 'Today' },
    { value: 'tonight', label: 'Tonight' },
    { value: 'this_weekend', label: 'This weekend' },
    { value: 'next_7_days', label: 'Next 7 days' },
];

const VIEW_OPTIONS: { value: ViewKey; label: string }[] = [
    { value: 'list', label: 'List' },
    { value: 'day', label: 'Day' },
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
];

// Each dropdown's "reset this filter" choice has its own value (not an empty string), which updateFilter turns back
// into "no filter".
const CLEAR_SENTINELS: Partial<Record<keyof Filters, string>> = { when: 'any', category: 'all', audience: 'everyone' };

export default function EventsIndex({ events, filters, categories, audiences, view, calendar }: EventsIndexProps) {
    function visit(params: Record<string, string | boolean | undefined>) {
        router.get(route('events.index'), params, { preserveState: true, preserveScroll: true, replace: true });
    }

    function updateFilter(key: keyof Filters, value: string | boolean | undefined) {
        const isClearSentinel = typeof value === 'string' && value === CLEAR_SENTINELS[key];
        visit({
            ...filters,
            [key]: isClearSentinel ? undefined : value || undefined,
            view: view === 'list' ? undefined : view,
            date: calendar?.anchorDate,
        });
    }

    function switchView(nextView: ViewKey) {
        visit({ ...filters, view: nextView === 'list' ? undefined : nextView, date: nextView === 'list' ? undefined : calendar?.anchorDate });
    }

    function goToDate(date: string) {
        visit({ ...filters, view: view === 'list' ? undefined : view, date });
    }

    function dayHref(date: string): string {
        return route('events.index', { ...filters, view: 'day', date });
    }

    const eventList = Array.isArray(events) ? events : events.data;

    const select = (label: string, value: string, onChange: (value: string) => void, options: { value: string; label: string }[]) => (
        <label className="event-filter">
            <span className="control-row-label">{label}</span>
            <select className="select" value={value} onChange={(e) => onChange(e.target.value)}>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );

    return (
        <PublicLayout activeId="events">
            <Head title="Community Events" />

            <DirectoryHero eyebrow="Community" title="What's happening in the Mojave River Valley">
                Events curated and verified by the Mojave River Valley Community team.
            </DirectoryHero>

            <section className="alt">
                <div className="mrv-container">
                    <div className="control-row view-switch">
                        <span className="control-row-label">View:</span>
                        <div className="map-controls">
                            {VIEW_OPTIONS.map((o) => (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => switchView(o.value)}
                                    className={classes('btn-toggle', view === o.value && 'active')}
                                >
                                    {o.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="control-row event-filters">
                        {view === 'list'
                            ? select('When', filters.when ?? 'any', (v) => updateFilter('when', v), WHEN_OPTIONS)
                            : calendar && (
                                  <div className="event-filter">
                                      <span className="control-row-label">Date</span>
                                      <div className="map-controls" style={{ margin: 0 }}>
                                          <button
                                              type="button"
                                              className="btn-nav-arrow"
                                              aria-label="Previous"
                                              onClick={() => goToDate(calendar.prevDate)}
                                          >
                                              ‹
                                          </button>
                                          <button type="button" className="btn-toggle" onClick={() => goToDate(calendar.today)}>
                                              Today
                                          </button>
                                          <button
                                              type="button"
                                              className="btn-nav-arrow"
                                              aria-label="Next"
                                              onClick={() => goToDate(calendar.nextDate)}
                                          >
                                              ›
                                          </button>
                                      </div>
                                  </div>
                              )}

                        {select('Topic', filters.category ?? 'all', (v) => updateFilter('category', v), [
                            { value: 'all', label: 'All topics' },
                            ...categories.map((c) => ({ value: c.slug, label: c.name })),
                        ])}

                        {select('Audience', filters.audience ?? 'everyone', (v) => updateFilter('audience', v), [
                            { value: 'everyone', label: 'Everyone' },
                            ...audiences.map((a) => ({ value: a.slug, label: a.name })),
                        ])}

                        <div className="event-filter">
                            <label className="check" htmlFor="free">
                                <input
                                    id="free"
                                    type="checkbox"
                                    checked={!!filters.free}
                                    onChange={(e) => updateFilter('free', e.target.checked ? '1' : undefined)}
                                />
                                Free only
                            </label>
                        </div>
                    </div>

                    {calendar && (
                        <h2 className="calendar-heading">
                            {view === 'day' && formatDayLabel(calendar.anchorDate)}
                            {view === 'week' && formatWeekRangeLabel(calendar.gridStart, calendar.gridEnd)}
                            {view === 'month' && formatMonthLabel(calendar.anchorDate)}
                        </h2>
                    )}

                    {view === 'list' && eventList.length === 0 && (
                        <div className="card empty-card">
                            <p className="card-note">
                                No events match right now. Check back soon — new events are added as they're found and verified.
                            </p>
                        </div>
                    )}

                    {view === 'list' && eventList.length > 0 && (
                        <div className="event-list">
                            {eventList.map((event) => (
                                <EventCard key={event.id} event={event} />
                            ))}
                        </div>
                    )}

                    {view === 'day' && <DayView events={eventList} />}

                    {view === 'week' && calendar && (
                        <WeekView
                            gridStart={calendar.gridStart}
                            gridEnd={calendar.gridEnd}
                            today={calendar.today}
                            events={eventList}
                            dayHref={dayHref}
                        />
                    )}

                    {view === 'month' && calendar && (
                        <MonthView
                            anchorDate={calendar.anchorDate}
                            gridStart={calendar.gridStart}
                            gridEnd={calendar.gridEnd}
                            today={calendar.today}
                            events={eventList}
                            dayHref={dayHref}
                        />
                    )}

                    {view === 'list' && !Array.isArray(events) && <PaginationLinks links={events.links} />}
                </div>
            </section>
        </PublicLayout>
    );
}
