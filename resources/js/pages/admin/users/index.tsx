import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';

interface UserRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    status: 'active' | 'invited';
    invited_at: string | null;
    editable: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/admin/users' }];

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
}

export default function UsersIndex({ users }: { users: UserRow[] }) {
    const { flash } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <div className="flex flex-col gap-4 p-4">
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

                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Users</h1>
                        <p className="text-muted-foreground text-sm">Invite editors and curators by email. They choose their own password.</p>
                    </div>
                    <Button asChild>
                        <Link href={route('admin.users.create')}>
                            <UserPlus className="size-4" />
                            Invite user
                        </Link>
                    </Button>
                </div>

                <div className="border-sidebar-border/70 overflow-x-auto rounded-md border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-sidebar-border/70 bg-muted/40 text-muted-foreground border-b text-xs">
                            <tr>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Email</th>
                                <th className="px-3 py-2 font-medium">Role</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id} className="border-sidebar-border/50 border-b last:border-0">
                                    <td className="px-3 py-2 font-medium">{user.name}</td>
                                    <td className="px-3 py-2">{user.email}</td>
                                    <td className="px-3 py-2">
                                        {user.roles.length ? user.roles.join(', ') : <span className="text-muted-foreground">none</span>}
                                    </td>
                                    <td className="px-3 py-2">
                                        {user.status === 'invited' ? (
                                            <span className="flex items-center gap-2">
                                                <Badge variant="secondary">Invited</Badge>
                                                <span className="text-muted-foreground text-xs">sent {formatDate(user.invited_at)}</span>
                                            </span>
                                        ) : (
                                            <Badge>Active</Badge>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {user.editable && (
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={route('admin.users.edit', user.id)}>Manage</Link>
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
