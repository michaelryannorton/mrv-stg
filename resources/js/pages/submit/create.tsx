import PublicHeader from '@/components/public-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

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

interface SubmitCreateProps {
    prefilled: Prefilled;
    sourceUrl: string | null;
}

export default function SubmitCreate({ prefilled, sourceUrl }: SubmitCreateProps) {
    const { flash } = usePage<SharedData>().props;

    const { data, setData, post, processing, errors, reset } = useForm({
        title: prefilled.title ?? '',
        description: prefilled.description ?? '',
        start_at: prefilled.start_at ?? '',
        end_at: prefilled.end_at ?? '',
        timezone: 'America/Los_Angeles',
        all_day: prefilled.all_day ?? false,
        location_name_override: prefilled.location_name_override ?? '',
        address_override: prefilled.address_override ?? '',
        canonical_url: prefilled.canonical_url ?? sourceUrl ?? '',
        is_free: false,
        price_min: '',
        price_max: '',
        name: '',
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('submit.store'), { onSuccess: () => reset() });
    };

    const foundNothing = sourceUrl && Object.keys(prefilled).length === 0;

    return (
        <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
            <Head title="Submit an Event" />
            <PublicHeader />

            <main className="mx-auto max-w-2xl px-6 py-10">
                <h1 className="mb-2 text-2xl font-semibold">Submit an event</h1>
                <p className="mb-8 text-[#706f6c] dark:text-[#A1A09A]">
                    Know about something happening in the Mojave River Valley? Paste a link to the event's page below, or just fill in what you know
                    by hand. Every submission is reviewed before it appears on the calendar.
                </p>

                {flash?.success && (
                    <div className="mb-6 rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                {sourceUrl && (
                    <p className="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        Reading from{' '}
                        <a href={sourceUrl} target="_blank" rel="noreferrer" className="underline underline-offset-2">
                            {sourceUrl}
                        </a>
                        {foundNothing && ' — nothing usable was found there, so the form below is blank. Fill it in by hand.'}
                    </p>
                )}

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <section className="flex flex-col gap-4 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="title">Event title</Label>
                            <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                            {errors.title && <p className="text-destructive text-sm">{errors.title}</p>}
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="description">Description</Label>
                            <Textarea id="description" rows={5} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        </div>
                    </section>

                    <section className="flex flex-col gap-4 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
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
                                <Label htmlFor="end_at">Ends (optional)</Label>
                                <Input id="end_at" type="datetime-local" value={data.end_at} onChange={(e) => setData('end_at', e.target.value)} />
                                {errors.end_at && <p className="text-destructive text-sm">{errors.end_at}</p>}
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox id="all_day" checked={data.all_day} onCheckedChange={(checked) => setData('all_day', checked === true)} />
                            <Label htmlFor="all_day">All-day event</Label>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="location_name_override">Where</Label>
                            <Input
                                id="location_name_override"
                                value={data.location_name_override}
                                onChange={(e) => setData('location_name_override', e.target.value)}
                                placeholder="Venue or place name"
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

                    <section className="flex flex-col gap-4 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="canonical_url">Link to the event (if any)</Label>
                            <Input id="canonical_url" value={data.canonical_url} onChange={(e) => setData('canonical_url', e.target.value)} />
                            {errors.canonical_url && <p className="text-destructive text-sm">{errors.canonical_url}</p>}
                        </div>

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

                    <section className="flex flex-col gap-4 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
                        <p className="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                            Your name and email are optional — especially useful if you'd like to help curate this list.
                        </p>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="name">Your name</Label>
                                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="email">Your email</Label>
                                <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                {errors.email && <p className="text-destructive text-sm">{errors.email}</p>}
                            </div>
                        </div>
                    </section>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            Submit for review
                        </Button>
                    </div>
                </form>
            </main>
        </div>
    );
}
