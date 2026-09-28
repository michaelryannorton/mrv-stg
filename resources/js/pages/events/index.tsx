import DayView from '@/components/calendar/day-view';
import MonthView from '@/components/calendar/month-view';
import WeekView from '@/components/calendar/week-view';
import PublicHeader from '@/components/public-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDayLabel, formatMonthLabel, formatWeekRangeLabel } from '@/lib/calendar';
import { formatWhen } from '@/lib/event-formatting';
import { type EventItem, type Taxonomy } from '@/types/event';
import { Head, Link, router } from '@inertiajs/react';

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

// Radix Select items can't have an empty-string value (that's reserved to mean "no selection"),
// so "reset this filter" needs its own real sentinel value per dropdown rather than being able to
// reuse "" — otherwise there'd be no item in the list a user could click to get back to the
// unfiltered state once they'd picked something else.
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

    return (
        <>
            <Head title="Community Events" />

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <PublicHeader />

                <main className="mx-auto max-w-4xl px-6 py-10">
                    <h1 className="mb-2 text-2xl font-semibold">What's happening in the Mojave River Valley</h1>
                    <p className="mb-8 text-[#706f6c] dark:text-[#A1A09A]">Events curated and verified by the Mojave River Valley Community team.</p>

                    <div className="mb-4 flex gap-1.5">
                        {VIEW_OPTIONS.map((o) => (
                            <button
                                key={o.value}
                                type="button"
                                onClick={() => switchView(o.value)}
                                className={`rounded-md border px-3 py-1.5 text-sm ${
                                    view === o.value
                                        ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:border-[#EDEDEC] dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                                        : 'border-[#e3e3e0] text-[#706f6c] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#A1A09A] dark:hover:bg-[#1a1a19]'
                                }`}
                            >
                                {o.label}
                            </button>
                        ))}
                    </div>

                    <div className="mb-8 flex flex-wrap items-end gap-4 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
                        {view === 'list' ? (
                            <div className="grid gap-1.5">
                                <Label>When</Label>
                                <Select value={filters.when ?? 'any'} onValueChange={(v) => updateFilter('when', v)}>
                                    <SelectTrigger className="w-[160px]">
                                        <SelectValue placeholder="Any time" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {WHEN_OPTIONS.map((o) => (
                                            <SelectItem key={o.value} value={o.value}>
                                                {o.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            calendar && (
                                <div className="grid gap-1.5">
                                    <Label>Date</Label>
                                    <div className="flex items-center gap-2">
                                        <Button size="sm" variant="outline" onClick={() => goToDate(calendar.prevDate)}>
                                            ‹
                                        </Button>
                                        <Button size="sm" variant="outline" onClick={() => goToDate(calendar.today)}>
                                            Today
                                        </Button>
                                        <Button size="sm" variant="outline" onClick={() => goToDate(calendar.nextDate)}>
                                            ›
                                        </Button>
                                    </div>
                                </div>
                            )
                        )}

                        <div className="grid gap-1.5">
                            <Label>Topic</Label>
                            <Select value={filters.category ?? 'all'} onValueChange={(v) => updateFilter('category', v)}>
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="All topics" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All topics</SelectItem>
                                    {categories.map((c) => (
                                        <SelectItem key={c.id} value={c.slug}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label>Audience</Label>
                            <Select value={filters.audience ?? 'everyone'} onValueChange={(v) => updateFilter('audience', v)}>
                                <SelectTrigger className="w-[160px]">
                                    <SelectValue placeholder="Everyone" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="everyone">Everyone</SelectItem>
                                    {audiences.map((a) => (
                                        <SelectItem key={a.id} value={a.slug}>
                                            {a.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex items-center gap-2 pb-2">
                            <Checkbox
                                id="free"
                                checked={!!filters.free}
                                onCheckedChange={(checked) => updateFilter('free', checked === true ? '1' : undefined)}
                            />
                            <Label htmlFor="free">Free only</Label>
                        </div>
                    </div>

                    {calendar && (
                        <h2 className="mb-4 text-lg font-medium">
                            {view === 'day' && formatDayLabel(calendar.anchorDate)}
                            {view === 'week' && formatWeekRangeLabel(calendar.gridStart, calendar.gridEnd)}
                            {view === 'month' && formatMonthLabel(calendar.anchorDate)}
                        </h2>
                    )}

                    {view === 'list' && eventList.length === 0 && (
                        <p className="rounded-md border border-dashed border-[#e3e3e0] p-8 text-center text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                            No events match right now. Check back soon — new events are added as they're found and verified.
                        </p>
                    )}

                    {view === 'list' && eventList.length > 0 && (
                        <div className="grid gap-4">
                            {eventList.map((event) => (
                                <Card key={event.id}>
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <CardTitle>{event.title}</CardTitle>
                                                <p className="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                                    {formatWhen(event)}
                                                    {event.venue && ` · ${event.venue.name}`}
                                                    {event.venue?.city && `, ${event.venue.city}`}
                                                </p>
                                            </div>
                                            {event.is_free && <Badge>Free</Badge>}
                                        </div>
                                    </CardHeader>
                                    {(event.short_description || event.categories.length > 0) && (
                                        <CardContent>
                                            {event.short_description && <p className="mb-3 text-sm">{event.short_description}</p>}
                                            <div className="flex flex-wrap gap-2">
                                                {event.categories.map((c) => (
                                                    <Badge key={c.id} variant="secondary">
                                                        {c.name}
                                                    </Badge>
                                                ))}
                                                {event.tags.map((t) => (
                                                    <Badge key={t.id} variant="outline">
                                                        {t.name}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </CardContent>
                                    )}
                                </Card>
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

                    {view === 'list' && !Array.isArray(events) && events.links.length > 3 && (
                        <div className="mt-8 flex flex-wrap gap-2">
                            {events.links.map((link, i) => (
                                <Link
                                    key={i}
                                    href={link.url ?? '#'}
                                    className={`rounded-sm border px-3 py-1 text-sm ${
                                        link.active
                                            ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:border-[#EDEDEC] dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                                            : 'border-[#e3e3e0] dark:border-[#3E3E3A]'
                                    } ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}
