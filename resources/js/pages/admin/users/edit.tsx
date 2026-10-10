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

interface Assignment {
    channel_id: number;
    channel_name: string;
    channel_status: string;
    role: string;
    status: 'active' | 'ended';
    started_at: string | null;
    ended_at: string | null;
}

interface EditUserProps {
    user: ManagedUser;
    roles: string[];
    assignments: Assignment[];
    available_channels: { id: number; name: string }[];
    assignment_roles: string[];
}

const capitalize = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
}

function RoleSelect({ id, value, options, onChange }: { id?: string; value: string; options: string[]; onChange: (value: string) => void }) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger id={id}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option} value={option}>
                        {capitalize(option)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function EditUser({ user, roles, assignments, available_channels, assignment_roles }: EditUserProps) {
    const { flash } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Users', href: '/admin/users' },
        { title: user.name, href: `/admin/users/${user.id}/edit` },
    ];
    const roleForm = useForm({ role: user.role ?? 'curator' });
    const assignForm = useForm({ channel_id: '', role: 'curator' });

    const saveRole: FormEventHandler = (e) => {
        e.preventDefault();
        roleForm.put(route('admin.users.update', user.id));
    };

    const assign: FormEventHandler = (e) => {
        e.preventDefault();
        assignForm.post(route('admin.users.assignments.store', user.id), { preserveScroll: true, onSuccess: () => assignForm.reset('channel_id') });
    };

    function changeAssignmentRole(channelId: number, role: string) {
        router.put(route('admin.users.assignments.update', [user.id, channelId]), { role }, { preserveScroll: true });
    }

    function endAssignment(assignment: Assignment) {
        if (window.confirm(`End ${user.name}'s work on "${assignment.channel_name}"? They lose access to it immediately.`)) {
            router.delete(route('admin.users.assignments.destroy', [user.id, assignment.channel_id]), { preserveScroll: true });
        }
    }

    function resend() {
        router.post(route('admin.users.resend', user.id), {}, { preserveScroll: true });
    }

    function revoke() {
        if (window.confirm(`Revoke the invitation for ${user.email}? Their account will be removed.`)) {
            router.delete(route('admin.users.destroy', user.id));
        }
    }

    function removeAccount() {
        if (
            window.confirm(
                `Remove ${user.name}'s account? They are logged out everywhere and lose all access. Their picks stay in the channels. This cannot be undone.`,
            )
        ) {
            router.post(route('admin.users.remove', user.id));
        }
    }

    const active = assignments.filter((a) => a.status === 'active');
    const ended = assignments.filter((a) => a.status === 'ended');

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

                <form onSubmit={saveRole} className="flex flex-col gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        <RoleSelect id="role" value={roleForm.data.role} options={roles} onChange={(value) => roleForm.setData('role', value)} />
                        <InputError message={roleForm.errors.role} />
                    </div>
                    <div>
                        <Button type="submit" disabled={roleForm.processing}>
                            Save role
                        </Button>
                    </div>
                </form>

                <section className="border-sidebar-border/70 flex flex-col gap-3 rounded-md border p-4">
                    <h2 className="text-sm font-semibold">Channels</h2>
                    <p className="text-muted-foreground text-xs">
                        {user.name} can feature and exclude events only in the channels listed here. The role in a channel only orders the public
                        byline.
                    </p>

                    {active.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Not assigned to any channel.</p>
                    ) : (
                        <ul className="divide-sidebar-border/50 divide-y">
                            {active.map((assignment) => (
                                <li key={assignment.channel_id} className="flex items-center justify-between gap-3 py-2">
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium">{assignment.channel_name}</div>
                                        <div className="text-muted-foreground text-xs">since {formatDate(assignment.started_at)}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className="w-36">
                                            <RoleSelect
                                                value={assignment.role}
                                                options={assignment_roles}
                                                onChange={(value) => changeAssignmentRole(assignment.channel_id, value)}
                                            />
                                        </div>
                                        <Button type="button" variant="outline" size="sm" onClick={() => endAssignment(assignment)}>
                                            End
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {available_channels.length > 0 ? (
                        <form onSubmit={assign} className="border-sidebar-border/50 flex flex-col gap-2 border-t pt-3">
                            <div className="flex flex-wrap items-end gap-2">
                                <div className="grid min-w-40 flex-1 gap-1">
                                    <Label htmlFor="channel">Add a channel</Label>
                                    <Select value={assignForm.data.channel_id} onValueChange={(value) => assignForm.setData('channel_id', value)}>
                                        <SelectTrigger id="channel">
                                            <SelectValue placeholder="Choose a channel" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {available_channels.map((channel) => (
                                                <SelectItem key={channel.id} value={String(channel.id)}>
                                                    {channel.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid w-36 gap-1">
                                    <Label htmlFor="assignment-role">Role</Label>
                                    <RoleSelect
                                        id="assignment-role"
                                        value={assignForm.data.role}
                                        options={assignment_roles}
                                        onChange={(value) => assignForm.setData('role', value)}
                                    />
                                </div>
                                <Button type="submit" disabled={assignForm.processing || assignForm.data.channel_id === ''}>
                                    Assign
                                </Button>
                            </div>
                            <InputError message={assignForm.errors.channel_id ?? assignForm.errors.role} />
                        </form>
                    ) : (
                        <p className="border-sidebar-border/50 text-muted-foreground border-t pt-3 text-xs">
                            No other channel is available to assign. Create channels under Channels.
                        </p>
                    )}

                    {ended.length > 0 && (
                        <div className="border-sidebar-border/50 text-muted-foreground border-t pt-3 text-xs">
                            Ended: {ended.map((a) => `${a.channel_name} (until ${formatDate(a.ended_at)})`).join(', ')}
                        </div>
                    )}
                </section>

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
                    <div className="flex flex-col gap-3 rounded-md border border-red-600/30 p-4">
                        <h2 className="text-sm font-semibold">Remove account</h2>
                        <p className="text-muted-foreground text-sm">
                            Deletes the account and logs the person out everywhere. Their assignments end with it. Picks they made stay in the
                            channels, with no author recorded.
                        </p>
                        <div>
                            <Button type="button" variant="destructive" onClick={removeAccount}>
                                Remove account
                            </Button>
                        </div>
                    </div>
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
