import { DirectoryHero } from '@/components/mrv/directory-hero';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import PublicLayout from '@/layouts/public-layout';
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
        // Only a real success clears the form; a rate-limit refusal also redirects back to this page
        // and must not throw away what the visitor typed.
        post(route('submit.store'), {
            onSuccess: (page) => {
                if ((page.props as unknown as SharedData).flash?.success) reset();
            },
        });
    };

    const foundNothing = sourceUrl && Object.keys(prefilled).length === 0;

    return (
        <PublicLayout activeId="submit">
            <Head title="Submit an Event" />

            <DirectoryHero eyebrow="Community" title="Submit an event">
                Know about something happening in the Mojave River Valley? Paste a link to the event's page below, or just fill in what you know by
                hand. Every submission is reviewed before it appears on the calendar.
            </DirectoryHero>

            <section className="alt">
                <div className="mrv-container" style={{ maxWidth: '760px' }}>
                    {flash?.success && <div className="notice success">{flash.success}</div>}

                    {flash?.error && <div className="notice error">{flash.error}</div>}

                    {sourceUrl && (
                        <p className="card-note" style={{ marginBottom: '18px' }}>
                            Reading from{' '}
                            <a href={sourceUrl} target="_blank" rel="noreferrer" style={{ textDecoration: 'underline', textUnderlineOffset: '2px' }}>
                                {sourceUrl}
                            </a>
                            {foundNothing && ' — nothing usable was found there, so the form below is blank. Fill it in by hand.'}
                        </p>
                    )}

                    <form onSubmit={submit} className="form-stack">
                        <section className="card form-section">
                            <div className="form-field">
                                <Label htmlFor="title">Event title</Label>
                                <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                                {errors.title && <p className="field-error">{errors.title}</p>}
                            </div>

                            <div className="form-field">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    rows={5}
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                />
                            </div>
                        </section>

                        <section className="card form-section">
                            <div className="form-pair">
                                <div className="form-field">
                                    <Label htmlFor="start_at">Starts</Label>
                                    <Input
                                        id="start_at"
                                        type="datetime-local"
                                        value={data.start_at}
                                        onChange={(e) => setData('start_at', e.target.value)}
                                    />
                                    {errors.start_at && <p className="field-error">{errors.start_at}</p>}
                                </div>

                                <div className="form-field">
                                    <Label htmlFor="end_at">Ends (optional)</Label>
                                    <Input
                                        id="end_at"
                                        type="datetime-local"
                                        value={data.end_at}
                                        onChange={(e) => setData('end_at', e.target.value)}
                                    />
                                    {errors.end_at && <p className="field-error">{errors.end_at}</p>}
                                </div>
                            </div>

                            <div className="form-check">
                                <Checkbox id="all_day" checked={data.all_day} onCheckedChange={(checked) => setData('all_day', checked === true)} />
                                <Label htmlFor="all_day">All-day event</Label>
                            </div>

                            <div className="form-field">
                                <Label htmlFor="location_name_override">Where</Label>
                                <Input
                                    id="location_name_override"
                                    value={data.location_name_override}
                                    onChange={(e) => setData('location_name_override', e.target.value)}
                                    placeholder="Venue or place name"
                                />
                            </div>

                            <div className="form-field">
                                <Label htmlFor="address_override">Address</Label>
                                <Textarea
                                    id="address_override"
                                    rows={2}
                                    value={data.address_override}
                                    onChange={(e) => setData('address_override', e.target.value)}
                                />
                            </div>
                        </section>

                        <section className="card form-section">
                            <div className="form-field">
                                <Label htmlFor="canonical_url">Link to the event (if any)</Label>
                                <Input id="canonical_url" value={data.canonical_url} onChange={(e) => setData('canonical_url', e.target.value)} />
                                {errors.canonical_url && <p className="field-error">{errors.canonical_url}</p>}
                            </div>

                            <div className="form-check">
                                <Checkbox id="is_free" checked={data.is_free} onCheckedChange={(checked) => setData('is_free', checked === true)} />
                                <Label htmlFor="is_free">This event is free</Label>
                            </div>

                            <div className="form-pair">
                                <div className="form-field">
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
                                <div className="form-field">
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

                        <section className="card form-section">
                            <p className="card-note">Your name and email are optional — especially useful if you'd like to help curate this list.</p>

                            <div className="form-pair">
                                <div className="form-field">
                                    <Label htmlFor="name">Your name</Label>
                                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                </div>
                                <div className="form-field">
                                    <Label htmlFor="email">Your email</Label>
                                    <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                    {errors.email && <p className="field-error">{errors.email}</p>}
                                </div>
                            </div>
                        </section>

                        <div className="form-actions">
                            <button type="submit" className="btn-primary" disabled={processing}>
                                Submit for review
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </PublicLayout>
    );
}
