import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    role: string | null;
    status: 'active' | 'invited';
    invited_at: string | null;
    invited_by: string | null;
}

export default function EditUser({ user, roles }: { user: ManagedUser; roles: string[] }) {
    const { flash } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Users', href: '/admin/users' },
        { title: user.name, href: `/admin/users/${user.id}/edit` },
    ];
    const { data, setData, put, processing, errors } = useForm({ role: user.role ?? 'curator' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.users.update', user.id));
    };

    function resend() {
        router.post(route('admin.users.resend', user.id), {}, { preserveScroll: true });
    }

    function revoke() {
        if (window.confirm(`Revoke the invitation for ${user.email}? Their account will be removed.`)) {
            router.delete(route('admin.users.destroy', user.id));
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Manage ${user.name}`} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 p-4">
                {flash?.success && (
                    <div className="rounded-md border border-green-600/30 bg-green-600/10 px-4 py-2 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-md border border-red-600/30 bg-red-600/10 px-4 py-2 text-sm text-red-700 dark:text-red-400">
                        {flash.error}
                    </div>
                )}

                <div>
                    <h1 className="text-xl font-semibold">{user.name}</h1>
                    <p className="text-muted-foreground text-sm">{user.email}</p>
                    <div className="mt-2 flex items-center gap-2">
                        <Badge variant={user.status === 'invited' ? 'secondary' : 'default'}>
                            {user.status === 'invited' ? 'Invited' : 'Active'}
                        </Badge>
                        {user.invited_by && <span className="text-muted-foreground text-xs">invited by {user.invited_by}</span>}
                    </div>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4">
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
                        <InputError message={errors.role} />
                    </div>
                    <div>
                        <Button type="submit" disabled={processing}>
                            Save role
                        </Button>
                    </div>
                </form>

                {user.status === 'invited' ? (
                    <div className="border-sidebar-border/70 flex flex-col gap-3 rounded-md border p-4">
                        <h2 className="text-sm font-semibold">Pending invitation</h2>
                        <p className="text-muted-foreground text-sm">
                            This person has not set a password yet. Resending sends a new link and cancels the old one. Revoking removes the account.
                        </p>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={resend}>
                                Resend invitation
                            </Button>
                            <Button type="button" variant="destructive" onClick={revoke}>
                                Revoke invitation
                            </Button>
                        </div>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        This account is active. Removing an active account is not available yet; assignments and removal arrive with the curator
                        workspace.
                    </p>
                )}

                <div>
                    <Button variant="ghost" asChild>
                        <Link href={route('admin.users.index')}>Back to users</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
