import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export interface CategoryOption {
    id: number;
    name: string;
}

export type ChannelValues = {
    name: string;
    slug: string;
    short_description: string;
    description: string;
    status: string;
    sort_order: number;
    category_ids: number[];
};

const STATUS_HELP: Record<string, string> = {
    draft: 'Being set up. Not listed anywhere public.',
    active: 'Live. Will be listed on the public channels page once that page exists.',
    inactive: 'Temporarily hidden.',
    archived: 'Retired. Kept for history.',
};

interface ChannelFormProps {
    initial: ChannelValues;
    categories: CategoryOption[];
    statuses: string[];
    submitLabel: string;
    onSubmit: (form: ReturnType<typeof useForm<ChannelValues>>) => void;
    slugEditable: boolean;
}

export default function ChannelForm({ initial, categories, statuses, submitLabel, onSubmit, slugEditable }: ChannelFormProps) {
    const form = useForm<ChannelValues>(initial);
    const { data, setData, processing, errors } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        onSubmit(form);
    };

    function toggleCategory(id: number) {
        setData(
            'category_ids',
            data.category_ids.includes(id) ? data.category_ids.filter((existing) => existing !== id) : [...data.category_ids, id],
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <div className="grid gap-2">
                <Label htmlFor="name">Name</Label>
                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="slug">Web address name (slug)</Label>
                <Input
                    id="slug"
                    value={data.slug}
                    onChange={(e) => setData('slug', e.target.value)}
                    placeholder="Leave blank to build it from the name"
                />
                <p className="text-muted-foreground text-xs">
                    Used in the public address, for example /channels/{data.slug || 'your-channel'}.{' '}
                    {slugEditable ? 'Changing it later breaks any link already shared.' : ''}
                </p>
                <InputError message={errors.slug} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="short_description">One-line description</Label>
                <Input
                    id="short_description"
                    value={data.short_description}
                    onChange={(e) => setData('short_description', e.target.value)}
                    maxLength={255}
                />
                <InputError message={errors.short_description} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Description</Label>
                <Textarea id="description" rows={5} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                <InputError message={errors.description} />
            </div>

            <div className="grid gap-2">
                <Label>Categories in this channel</Label>
                <p className="text-muted-foreground text-xs">
                    Every event in any checked category belongs to the channel automatically. Curators can add events by hand and remove individual
                    events later. A channel with no category contains only the events its curators add.
                </p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {categories.map((category) => (
                        <label key={category.id} className="flex items-center gap-1.5 text-sm">
                            <Checkbox checked={data.category_ids.includes(category.id)} onCheckedChange={() => toggleCategory(category.id)} />
                            {category.name}
                        </label>
                    ))}
                </div>
                <InputError message={errors.category_ids} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="status">Status</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value)}>
                        <SelectTrigger id="status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {status.charAt(0).toUpperCase() + status.slice(1)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="text-muted-foreground text-xs">{STATUS_HELP[data.status]}</p>
                    <InputError message={errors.status} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="sort_order">Sort order</Label>
                    <Input
                        id="sort_order"
                        type="number"
                        min={0}
                        value={data.sort_order}
                        onChange={(e) => setData('sort_order', Number(e.target.value))}
                    />
                    <p className="text-muted-foreground text-xs">Lower numbers are listed first.</p>
                    <InputError message={errors.sort_order} />
                </div>
            </div>

            <div className="flex gap-2">
                <Button type="submit" disabled={processing}>
                    {submitLabel}
                </Button>
                <Button type="button" variant="ghost" asChild>
                    <Link href={route('admin.channels.index')}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
