import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const ROLE_HELP: Record<string, string> = {
    curator: 'Picks events for the channels they are assigned to. Cannot approve or edit events.',
    editor: 'Same access as a curator for now. Will also approve newsletter editions once those exist.',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/admin/users' },
    { title: 'Invite user', href: '/admin/users/create' },
];

export default function InviteUser({ roles }: { roles: string[] }) {
    const { flash } = usePage<SharedData>().props;
    const { data, setData, post, processing, errors } = useForm({ name: '', email: '', role: 'curator' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.users.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Invite user" />

            <form onSubmit={submit} className="mx-auto flex w-full max-w-xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Invite user</h1>
                    <p className="text-muted-foreground text-sm">
                        They receive an email with a link, valid for 7 days, to choose their own password. You never see it.
                    </p>
                </div>

                {flash?.error && (
                    <div className="rounded-md border border-red-600/30 bg-red-600/10 px-4 py-2 text-sm text-red-700 dark:text-red-400">
                        {flash.error}
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="role">Role</Label>
                    <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                        <SelectTrigger id="role">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((role) => (
                                <SelectItem key={role} value={role}>
                                    {role.charAt(0).toUpperCase() + role.slice(1)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="text-muted-foreground text-xs">{ROLE_HELP[data.role]}</p>
                    <InputError message={errors.role} />
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Send invitation
                    </Button>
                    <Button type="button" variant="ghost" asChild>
                        <Link href={route('admin.users.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
