import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface ProfileProps {
    profile: { display_name: string; blurb: string; is_public: boolean };
    blurb_limit: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Public profile', href: '/curate/profile' }];

export default function CuratorProfile({ profile, blurb_limit }: ProfileProps) {
    const { flash } = usePage<SharedData>().props;
    const { data, setData, put, processing, errors } = useForm({
        display_name: profile.display_name,
        blurb: profile.blurb,
        is_public: profile.is_public,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('curate.profile.update'), { preserveScroll: true });
    };

    // Line breaks count as one character, as the server counts them.
    const used = data.blurb.replace(/\r\n/g, '\n').trim().length;
    const over = used > blurb_limit;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Public profile" />

            <form onSubmit={submit} className="mx-auto flex w-full max-w-xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Public profile</h1>
                    <p className="text-muted-foreground text-sm">
                        This is the short introduction readers see next to the channels you curate on the website. It is separate from the
                        introduction you write for each newsletter edition, and from your account settings (name, email, password).
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="display_name">Name shown to readers</Label>
                    <Input
                        id="display_name"
                        value={data.display_name}
                        onChange={(e) => setData('display_name', e.target.value)}
                        maxLength={100}
                        required
                    />
                    <InputError message={errors.display_name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="blurb">About you</Label>
                    <Textarea id="blurb" rows={5} value={data.blurb} onChange={(e) => setData('blurb', e.target.value)} />
                    <div className={`text-xs ${over ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'}`}>
                        {used} of {blurb_limit} characters
                    </div>
                    <InputError message={errors.blurb} />
                </div>

                <label className="flex items-start gap-2 text-sm">
                    <Checkbox checked={data.is_public} onCheckedChange={(checked) => setData('is_public', checked === true)} className="mt-0.5" />
                    <span>
                        Show this profile on my channels&apos; public pages
                        <span className="text-muted-foreground block text-xs">
                            Nothing is shown publicly until the channel pages launch, and then only for profiles with this box checked.
                        </span>
                    </span>
                </label>

                <div>
                    <Button type="submit" disabled={processing || over}>
                        Save profile
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
