import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Lock } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Option {
    id: number;
    name: string;
}

interface VenueOption extends Option {
    city: string | null;
}

interface SourceLink {
    source_url: string | null;
    source: { name: string } | null;
}

interface EditableEvent {
    id: number;
    title: string;
    short_description: string | null;
    description: string | null;
    start_at: string;
    end_at: string | null;
    timezone: string;
    all_day: boolean;
    venue_id: number | null;
    organizer_id: number | null;
    location_name_override: string | null;
    address_override: string | null;
    canonical_url: string | null;
    ticket_url: string | null;
    price_min: string | null;
    price_max: string | null;
    currency: string | null;
    is_free: boolean | null;
    age_restriction: string | null;
    accessibility_notes: string | null;
    overridden_fields: string[] | null;
    stale_fields: string[] | null;
    categories: Option[];
    tags: Option[];
    audiences: Option[];
    event_sources: SourceLink[];
}

interface EditEventProps {
    event: EditableEvent;
    sourceValues: Record<string, unknown>;
    venues: VenueOption[];
    organizations: Option[];
    categories: Option[];
    tags: Option[];
    audiences: Option[];
}

// Datetime-local inputs need a bare wall-clock string with no offset; the event's own timezone
// (also editable on this form) supplies the offset when the server parses it back — see
// EventEditController::update()'s Carbon::parse($value, $timezone) call.
function toDatetimeLocalValue(isoUtc: string | null, timezone: string): string {
    if (!isoUtc) return '';

    const date = new Date(isoUtc);
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(date);

    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';
    const hour = get('hour') === '24' ? '00' : get('hour');

    return `${get('year')}-${get('month')}-${get('day')}T${hour}:${get('minute')}`;
}

// Read-only display formatting for a source's current value in the stale-field comparison —
// separate from toDatetimeLocalValue, which formats for an editable input specifically.
function formatSourceValue(field: string, value: unknown, timezone: string, organizations: Option[]): string {
    if (value === null || value === undefined || value === '') return '(empty)';

    if (field === 'start_at' || field === 'end_at') {
        return new Intl.DateTimeFormat('en-US', { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' }).format(new Date(String(value)));
    }

    if (field === 'all_day') {
        return value ? 'Yes' : 'No';
    }

    if (field === 'organizer_id') {
        return organizations.find((org) => org.id === Number(value))?.name ?? `#${value}`;
    }

    return String(value);
}

function FieldBadge({ field, event }: { field: string; event: EditableEvent }) {
    if (!event.overridden_fields?.includes(field)) return null;

    if (event.stale_fields?.includes(field)) {
        return (
            <Badge variant="outline" className="gap-1 border-amber-600/40 text-xs text-amber-700 dark:text-amber-400">
                <AlertTriangle className="size-3" />
                source has changed
            </Badge>
        );
    }

    return (
        <Badge variant="secondary" className="gap-1 text-xs">
            <Lock className="size-3" />
            protected from re-ingestion
        </Badge>
    );
}

function StaleFieldNotice({
    field,
    event,
    sourceValues,
    organizations,
    onSync,
}: {
    field: string;
    event: EditableEvent;
    sourceValues: Record<string, unknown>;
    organizations: Option[];
    onSync: (field: string) => void;
}) {
    if (!event.stale_fields?.includes(field)) return null;

    return (
        <div className="flex items-center justify-between gap-3 rounded-md border border-amber-600/30 bg-amber-600/10 px-3 py-2 text-sm">
            <span>
                Source now says: <span className="font-medium">{formatSourceValue(field, sourceValues[field], event.timezone, organizations)}</span>
            </span>
            <Button type="button" size="sm" variant="outline" onClick={() => onSync(field)}>
                Use this value
            </Button>
        </div>
    );
}

export default function EditEvent({ event, sourceValues, venues, organizations, categories, tags, audiences }: EditEventProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Review queue', href: '/admin/events' },
        { title: event.title, href: `/admin/events/${event.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm({
        title: event.title,
        short_description: event.short_description ?? '',
        description: event.description ?? '',
        start_at: toDatetimeLocalValue(event.start_at, event.timezone),
        end_at: toDatetimeLocalValue(event.end_at, event.timezone),
        timezone: event.timezone,
        all_day: event.all_day,
        venue_id: event.venue_id,
        organizer_id: event.organizer_id,
        location_name_override: event.location_name_override ?? '',
        address_override: event.address_override ?? '',
        canonical_url: event.canonical_url ?? '',
        ticket_url: event.ticket_url ?? '',
        price_min: event.price_min ?? '',
        price_max: event.price_max ?? '',
        currency: event.currency ?? '',
        is_free: event.is_free ?? false,
        age_restriction: event.age_restriction ?? '',
        accessibility_notes: event.accessibility_notes ?? '',
        category_ids: event.categories.map((c) => c.id),
        tag_ids: event.tags.map((t) => t.id),
        audience_ids: event.audiences.map((a) => a.id),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.events.update', event.id));
    };

    function toggleId(key: 'category_ids' | 'tag_ids' | 'audience_ids', id: number) {
        setData(key, data[key].includes(id) ? data[key].filter((existing) => existing !== id) : [...data[key], id]);
    }

    // A full page reload (rather than relying on Inertia's in-place prop swap) guarantees this
    // form's local useForm state — which was only ever initialized once, from the props as they
    // were on first render — picks up the field the sync just changed, not just the event prop.
    function syncField(field: string) {
        router.post(route('admin.events.sync-field', event.id), { field }, { preserveScroll: true, onSuccess: () => window.location.reload() });
    }

    const sourceLink = event.event_sources[0];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit — ${event.title}`} />

            <form onSubmit={submit} className="mx-auto flex max-w-3xl flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Edit event</h1>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('admin.events.index')}>Back to queue</Link>
                    </Button>
                </div>

                {sourceLink && (
                    <p className="text-muted-foreground text-sm">
                        Ingested from {sourceLink.source?.name ?? 'a source'}
                        {sourceLink.source_url && (
                            <>
                                {' — '}
                                <a href={sourceLink.source_url} target="_blank" rel="noreferrer" className="underline underline-offset-2">
                                    original listing
                                </a>
                            </>
                        )}
                        . Fields marked <Lock className="inline size-3" /> won&apos;t be overwritten by the next scheduled re-sync;{' '}
                        <AlertTriangle className="inline size-3" /> means the source has since changed and it's worth a look.
                    </p>
                )}

                <section className="flex flex-col gap-4 rounded-lg border p-4">
                    <h2 className="text-muted-foreground text-sm font-semibold uppercase">Basics</h2>

                    <div className="flex flex-col gap-1.5">
                        <div className="flex items-center gap-2">
                            <Label htmlFor="title">Title</Label>
                            <FieldBadge field="title" event={event} />
                        </div>
                        <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        {errors.title && <p className="text-destructive text-sm">{errors.title}</p>}
                        <StaleFieldNotice field="title" event={event} sourceValues={sourceValues} organizations={organizations} onSync={syncField} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="short_description">Short description</Label>
                        <Input id="short_description" value={data.short_description} onChange={(e) => setData('short_description', e.target.value)} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <div className="flex items-center gap-2">
                            <Label htmlFor="description">Description</Label>
                            <FieldBadge field="description" event={event} />
                        </div>
                        <Textarea id="description" rows={5} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        <StaleFieldNotice
                            field="description"
                            event={event}
                            sourceValues={sourceValues}
                            organizations={organizations}
                            onSync={syncField}
                        />
                    </div>
                </section>

                <section className="flex flex-col gap-4 rounded-lg border p-4">
                    <h2 className="text-muted-foreground text-sm font-semibold uppercase">When & where</h2>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-center gap-2">
                                <Label htmlFor="start_at">Starts</Label>
                                <FieldBadge field="start_at" event={event} />
                            </div>
                            <Input id="start_at" type="datetime-local" value={data.start_at} onChange={(e) => setData('start_at', e.target.value)} />
                            {errors.start_at && <p className="text-destructive text-sm">{errors.start_at}</p>}
                            <StaleFieldNotice
                                field="start_at"
                                event={event}
                                sourceValues={sourceValues}
                                organizations={organizations}
                                onSync={syncField}
                            />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-center gap-2">
                                <Label htmlFor="end_at">Ends</Label>
                                <FieldBadge field="end_at" event={event} />
                            </div>
                            <Input id="end_at" type="datetime-local" value={data.end_at} onChange={(e) => setData('end_at', e.target.value)} />
                            {errors.end_at && <p className="text-destructive text-sm">{errors.end_at}</p>}
                            <StaleFieldNotice
                                field="end_at"
                                event={event}
                                sourceValues={sourceValues}
                                organizations={organizations}
                                onSync={syncField}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-center gap-2">
                                <Label htmlFor="timezone">Timezone</Label>
                                <FieldBadge field="timezone" event={event} />
                            </div>
                            <Input id="timezone" value={data.timezone} onChange={(e) => setData('timezone', e.target.value)} />
                            <StaleFieldNotice
                                field="timezone"
                                event={event}
                                sourceValues={sourceValues}
                                organizations={organizations}
                                onSync={syncField}
                            />
                        </div>

                        <div className="flex items-center gap-2 pt-6">
                            <Checkbox id="all_day" checked={data.all_day} onCheckedChange={(checked) => setData('all_day', checked === true)} />
                            <Label htmlFor="all_day">All-day event</Label>
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="venue">Venue</Label>
                        <Select
                            value={data.venue_id ? String(data.venue_id) : 'none'}
                            onValueChange={(value) => setData('venue_id', value === 'none' ? null : Number(value))}
                        >
                            <SelectTrigger id="venue">
                                <SelectValue placeholder="No venue selected" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">No venue</SelectItem>
                                {venues.map((venue) => (
                                    <SelectItem key={venue.id} value={String(venue.id)}>
                                        {venue.name}
                                        {venue.city ? `, ${venue.city}` : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <div className="flex items-center gap-2">
                            <Label htmlFor="location_name_override">Location name</Label>
                            <FieldBadge field="location_name_override" event={event} />
                        </div>
                        <Input
                            id="location_name_override"
                            value={data.location_name_override}
                            onChange={(e) => setData('location_name_override', e.target.value)}
                            placeholder="Used when no venue is linked"
                        />
                        <StaleFieldNotice
                            field="location_name_override"
                            event={event}
                            sourceValues={sourceValues}
                            organizations={organizations}
                            onSync={syncField}
                        />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="address_override">Address</Label>
                        <Textarea
                            id="address_override"
                            rows={2}
                            value={data.address_override}
                            onChange={(e) => setData('address_override', e.target.value)}
                        />
                    </div>
                </section>

                <section className="flex flex-col gap-4 rounded-lg border p-4">
                    <h2 className="text-muted-foreground text-sm font-semibold uppercase">Links & organizer</h2>

                    <div className="flex flex-col gap-1.5">
                        <div className="flex items-center gap-2">
                            <Label htmlFor="canonical_url">Original listing URL</Label>
                            <FieldBadge field="canonical_url" event={event} />
                        </div>
                        <Input id="canonical_url" value={data.canonical_url} onChange={(e) => setData('canonical_url', e.target.value)} />
                        {errors.canonical_url && <p className="text-destructive text-sm">{errors.canonical_url}</p>}
                        <StaleFieldNotice
                            field="canonical_url"
                            event={event}
                            sourceValues={sourceValues}
                            organizations={organizations}
                            onSync={syncField}
                        />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="ticket_url">Ticket URL</Label>
                        <Input id="ticket_url" value={data.ticket_url} onChange={(e) => setData('ticket_url', e.target.value)} />
                        {errors.ticket_url && <p className="text-destructive text-sm">{errors.ticket_url}</p>}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <div className="flex items-center gap-2">
                            <Label htmlFor="organizer">Organizer</Label>
                            <FieldBadge field="organizer_id" event={event} />
                        </div>
                        <Select
                            value={data.organizer_id ? String(data.organizer_id) : 'none'}
                            onValueChange={(value) => setData('organizer_id', value === 'none' ? null : Number(value))}
                        >
                            <SelectTrigger id="organizer">
                                <SelectValue placeholder="No organizer selected" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">No organizer</SelectItem>
                                {organizations.map((org) => (
                                    <SelectItem key={org.id} value={String(org.id)}>
                                        {org.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <StaleFieldNotice
                            field="organizer_id"
                            event={event}
                            sourceValues={sourceValues}
                            organizations={organizations}
                            onSync={syncField}
                        />
                    </div>
                </section>

                <section className="flex flex-col gap-4 rounded-lg border p-4">
                    <h2 className="text-muted-foreground text-sm font-semibold uppercase">Cost & access</h2>

                    <div className="flex items-center gap-2">
                        <Checkbox id="is_free" checked={data.is_free} onCheckedChange={(checked) => setData('is_free', checked === true)} />
                        <Label htmlFor="is_free">This event is free</Label>
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="price_min">Price min</Label>
                            <Input
                                id="price_min"
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.price_min}
                                onChange={(e) => setData('price_min', e.target.value)}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="price_max">Price max</Label>
                            <Input
                                id="price_max"
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.price_max}
                                onChange={(e) => setData('price_max', e.target.value)}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="currency">Currency</Label>
                            <Input
                                id="currency"
                                maxLength={3}
                                value={data.currency}
                                onChange={(e) => setData('currency', e.target.value.toUpperCase())}
                                placeholder="USD"
                            />
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="age_restriction">Age restriction</Label>
                        <Input
                            id="age_restriction"
                            value={data.age_restriction}
                            onChange={(e) => setData('age_restriction', e.target.value)}
                            placeholder="e.g. 21+, all ages"
                        />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="accessibility_notes">Accessibility notes</Label>
                        <Textarea
                            id="accessibility_notes"
                            rows={2}
                            value={data.accessibility_notes}
                            onChange={(e) => setData('accessibility_notes', e.target.value)}
                        />
                    </div>
                </section>

                <section className="flex flex-col gap-4 rounded-lg border p-4">
                    <h2 className="text-muted-foreground text-sm font-semibold uppercase">Categorization</h2>

                    <div className="flex flex-col gap-1.5">
                        <Label>Categories</Label>
                        <div className="flex flex-wrap gap-3">
                            {categories.map((category) => (
                                <label key={category.id} className="flex items-center gap-1.5 text-sm">
                                    <Checkbox
                                        checked={data.category_ids.includes(category.id)}
                                        onCheckedChange={() => toggleId('category_ids', category.id)}
                                    />
                                    {category.name}
                                </label>
                            ))}
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label>Tags</Label>
                        {tags.length === 0 && <p className="text-muted-foreground text-sm">No tags created yet.</p>}
                        <div className="flex flex-wrap gap-3">
                            {tags.map((tag) => (
                                <label key={tag.id} className="flex items-center gap-1.5 text-sm">
                                    <Checkbox checked={data.tag_ids.includes(tag.id)} onCheckedChange={() => toggleId('tag_ids', tag.id)} />
                                    {tag.name}
                                </label>
                            ))}
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label>Audiences</Label>
                        <div className="flex flex-wrap gap-3">
                            {audiences.map((audience) => (
                                <label key={audience.id} className="flex items-center gap-1.5 text-sm">
                                    <Checkbox
                                        checked={data.audience_ids.includes(audience.id)}
                                        onCheckedChange={() => toggleId('audience_ids', audience.id)}
                                    />
                                    {audience.name}
                                </label>
                            ))}
                        </div>
                    </div>
                </section>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="ghost" asChild>
                        <Link href={route('admin.events.index')}>Cancel</Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Save changes
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
