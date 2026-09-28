import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef } from 'react';

interface Option {
    id: number;
    name: string;
}

interface VenueOption extends Option {
    city: string | null;
}

interface Prefilled {
    title?: string;
    description?: string;
    start_at?: string;
    end_at?: string;
    all_day?: boolean;
    location_name_override?: string;
    address_override?: string;
    canonical_url?: string;
}

interface ClipCreateProps {
    prefilled: Prefilled;
    sourceUrl: string | null;
    clipUrl: string;
    venues: VenueOption[];
    organizations: Option[];
    categories: Option[];
    tags: Option[];
    audiences: Option[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clip an event', href: '/admin/clip' }];

export default function ClipCreate({ prefilled, sourceUrl, clipUrl, venues, organizations, categories, tags, audiences }: ClipCreateProps) {
    const { flash } = usePage<SharedData>().props;

    const { data, setData, post, processing, errors } = useForm({
        title: prefilled.title ?? '',
        description: prefilled.description ?? '',
        start_at: prefilled.start_at ?? '',
        end_at: prefilled.end_at ?? '',
        timezone: 'America/Los_Angeles',
        all_day: prefilled.all_day ?? false,
        venue_id: null as number | null,
        organizer_id: null as number | null,
        location_name_override: prefilled.location_name_override ?? '',
        address_override: prefilled.address_override ?? '',
        canonical_url: prefilled.canonical_url ?? sourceUrl ?? '',
        is_free: false,
        price_min: '',
        price_max: '',
        category_ids: [] as number[],
        tag_ids: [] as number[],
        audience_ids: [] as number[],
        publish_now: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.clip.store'));
    };

    function toggleId(key: 'category_ids' | 'tag_ids' | 'audience_ids', id: number) {
        setData(key, data[key].includes(id) ? data[key].filter((existing) => existing !== id) : [...data[key], id]);
    }

    const bookmarklet = `javascript:location.href='${clipUrl}?url='+encodeURIComponent(location.href)`;
    const foundNothing = sourceUrl && Object.keys(prefilled).length === 0;

    // React 19 refuses to set a javascript: URL via the JSX href prop (an XSS guard against
    // untrusted strings reaching href) — a bookmarklet legitimately needs exactly that, so the
    // href is set imperatively here instead, bypassing the guard for this one static, code-authored
    // string rather than any value that could originate from user input.
    const bookmarkletRef = useRef<HTMLAnchorElement>(null);
    useEffect(() => {
        bookmarkletRef.current?.setAttribute('href', bookmarklet);
    }, [bookmarklet]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clip an event" />

            <div className="mx-auto flex max-w-3xl flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Clip an event</h1>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('admin.events.index')}>Back to queue</Link>
                    </Button>
                </div>

                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                <section className="flex flex-col gap-2 rounded-lg border p-4">
                    <h2 className="text-muted-foreground text-sm font-semibold uppercase">Bookmarklet</h2>
                    <p className="text-muted-foreground text-sm">
                        Drag this link to your bookmarks bar. Clicking it on any event's page sends that page here, pre-filled from whatever it can
                        find.
                    </p>
                    <a
                        ref={bookmarkletRef}
                        onClick={(e) => e.preventDefault()}
                        className="border-input bg-accent w-fit cursor-grab rounded-md border px-3 py-1.5 text-sm font-medium"
                    >
                        Clip to Community
                    </a>
                </section>

                {sourceUrl && (
                    <p className="text-muted-foreground text-sm">
                        Clipped from{' '}
                        <a href={sourceUrl} target="_blank" rel="noreferrer" className="underline underline-offset-2">
                            {sourceUrl}
                        </a>
                        {foundNothing && ' — nothing usable was found there, so the form below is blank. Fill it in by hand.'}
                    </p>
                )}

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <section className="flex flex-col gap-4 rounded-lg border p-4">
                        <h2 className="text-muted-foreground text-sm font-semibold uppercase">Basics</h2>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="title">Title</Label>
                            <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                            {errors.title && <p className="text-destructive text-sm">{errors.title}</p>}
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="description">Description</Label>
                            <Textarea id="description" rows={5} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        </div>
                    </section>

                    <section className="flex flex-col gap-4 rounded-lg border p-4">
                        <h2 className="text-muted-foreground text-sm font-semibold uppercase">When & where</h2>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="start_at">Starts</Label>
                                <Input
                                    id="start_at"
                                    type="datetime-local"
                                    value={data.start_at}
                                    onChange={(e) => setData('start_at', e.target.value)}
                                />
                                {errors.start_at && <p className="text-destructive text-sm">{errors.start_at}</p>}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="end_at">Ends</Label>
                                <Input id="end_at" type="datetime-local" value={data.end_at} onChange={(e) => setData('end_at', e.target.value)} />
                                {errors.end_at && <p className="text-destructive text-sm">{errors.end_at}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="timezone">Timezone</Label>
                                <Input id="timezone" value={data.timezone} onChange={(e) => setData('timezone', e.target.value)} />
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
                            <Label htmlFor="location_name_override">Location name</Label>
                            <Input
                                id="location_name_override"
                                value={data.location_name_override}
                                onChange={(e) => setData('location_name_override', e.target.value)}
                                placeholder="Used when no venue is linked"
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
                            <Label htmlFor="canonical_url">Original listing URL</Label>
                            <Input id="canonical_url" value={data.canonical_url} onChange={(e) => setData('canonical_url', e.target.value)} />
                            {errors.canonical_url && <p className="text-destructive text-sm">{errors.canonical_url}</p>}
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="organizer">Organizer</Label>
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
                        </div>
                    </section>

                    <section className="flex flex-col gap-4 rounded-lg border p-4">
                        <h2 className="text-muted-foreground text-sm font-semibold uppercase">Cost</h2>

                        <div className="flex items-center gap-2">
                            <Checkbox id="is_free" checked={data.is_free} onCheckedChange={(checked) => setData('is_free', checked === true)} />
                            <Label htmlFor="is_free">This event is free</Label>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
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

                    <section className="flex items-center justify-between rounded-lg border p-4">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.publish_now} onCheckedChange={(checked) => setData('publish_now', checked === true)} />
                            Publish now (unchecked saves this as a draft — see "My drafts")
                        </label>
                    </section>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" asChild>
                            <Link href={route('admin.events.index')}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {data.publish_now ? 'Publish event' : 'Save as draft'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
