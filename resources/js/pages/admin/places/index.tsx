import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

interface Place {
    id: number;
    name: string;
    street: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    latitude: string | null;
    longitude: string | null;
    upcoming_count: number;
    state_of_map: 'mapped' | 'no_street' | 'not_found' | 'waiting';
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Places', href: '/admin/places' }];

const MAP_STATE: Record<Place['state_of_map'], string> = {
    mapped: 'On the map',
    no_street: 'Needs a street address',
    not_found: 'Address not found',
    waiting: 'Waiting to be looked up',
};

const FIELDS = [
    { key: 'street', label: 'Street', width: 'sm:col-span-2' },
    { key: 'city', label: 'City', width: '' },
    { key: 'state', label: 'State', width: '' },
    { key: 'postal_code', label: 'ZIP', width: '' },
    { key: 'latitude', label: 'Latitude', width: '' },
    { key: 'longitude', label: 'Longitude', width: '' },
] as const;

function PlaceForm({ place, onSaved }: { place: Place; onSaved: () => void }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        street: place.street ?? '',
        city: place.city ?? '',
        state: place.state ?? '',
        postal_code: place.postal_code ?? '',
        latitude: place.latitude ?? '',
        longitude: place.longitude ?? '',
    });

    function save(e: FormEvent) {
        e.preventDefault();
        put(route('admin.places.update', place.id), { preserveScroll: true, onSuccess: onSaved });
    }

    return (
        <form onSubmit={save} className="grid gap-2 sm:grid-cols-4">
            {FIELDS.map((field) => (
                <label key={field.key} className={`flex flex-col gap-1 text-sm ${field.width}`}>
                    {field.label}
                    <Input value={data[field.key]} onChange={(e) => setData(field.key, e.target.value)} />
                    <InputError message={errors[field.key]} />
                </label>
            ))}
            <p className="text-muted-foreground text-xs sm:col-span-3">
                Change the address and leave the two numbers, and the point is looked up again. Type the numbers to set it yourself.
            </p>
            <Button type="submit" size="sm" disabled={processing || !isDirty} className="self-end justify-self-end">
                Save
            </Button>
        </form>
    );
}

export default function PlacesIndex({ places }: { places: Place[] }) {
    const { flash } = usePage<SharedData>().props;
    const [missingOnly, setMissingOnly] = useState(places.some((p) => p.state_of_map !== 'mapped'));
    const [editing, setEditing] = useState<number | null>(null);
    const [search, setSearch] = useState('');

    const wanted = search.trim().toLowerCase();
    const shown = places.filter(
        (p) =>
            (!missingOnly || p.state_of_map !== 'mapped') && (wanted === '' || [p.name, p.street, p.city].join(' ').toLowerCase().includes(wanted)),
    );
    const missing = places.filter((p) => p.state_of_map !== 'mapped').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Places" />

            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Places</h1>
                <p className="text-muted-foreground max-w-prose text-sm">
                    Where upcoming events are held. {places.length} places, {missing} without a map point.
                </p>

                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search places"
                        aria-label="Search places"
                        className="max-w-xs"
                    />
                    <label className="flex items-center gap-1.5 text-sm">
                        <input type="checkbox" checked={missingOnly} onChange={(e) => setMissingOnly(e.target.checked)} />
                        Only those without a map point
                    </label>
                </div>

                {shown.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center">No places to show.</p>
                ) : (
                    <ul className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border">
                        {shown.map((place) => (
                            <li key={place.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 last:border-b-0">
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span className="font-medium">{place.name}</span>
                                    <span className="text-muted-foreground text-sm">
                                        {[place.street, place.city, place.postal_code].filter(Boolean).join(', ') || 'No address'}
                                    </span>
                                    <Badge variant="outline">{MAP_STATE[place.state_of_map]}</Badge>
                                    <span className="text-muted-foreground text-sm">{place.upcoming_count} upcoming</span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="ml-auto"
                                        aria-expanded={editing === place.id}
                                        onClick={() => setEditing(editing === place.id ? null : place.id)}
                                    >
                                        {editing === place.id ? 'Close' : 'Correct'}
                                    </Button>
                                </div>
                                {editing === place.id && (
                                    <div className="mt-3">
                                        <PlaceForm place={place} onSaved={() => setEditing(null)} />
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
