import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, CalendarCheck, Files, Folder, Inbox, Layers, LayoutGrid, Link2, Sparkles, Users } from 'lucide-react';
import AppLogo from './app-logo';

const dashboardNavItem: NavItem = {
    title: 'Dashboard',
    url: '/dashboard',
    icon: LayoutGrid,
};

// Shown to anyone who holds the channels.curate permission (editors, curators, administrators).
const curateNavItem: NavItem = {
    title: 'Curate',
    url: '/curate',
    icon: Sparkles,
};

// Shown only to administrators; the server enforces this too (every /admin route needs the role).
const adminNavItems: NavItem[] = [
    {
        title: 'Review queue',
        url: '/admin/events',
        icon: CalendarCheck,
    },
    {
        title: 'Channels',
        url: '/admin/channels',
        icon: Layers,
    },
    {
        title: 'Users',
        url: '/admin/users',
        icon: Users,
    },
    {
        title: 'Submissions',
        url: '/admin/submissions',
        icon: Inbox,
    },
    {
        title: 'Clip an event',
        url: '/admin/clip',
        icon: Link2,
    },
    {
        title: 'My drafts',
        url: '/admin/drafts',
        icon: Files,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        url: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        url: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const mainNavItems = [
        dashboardNavItem,
        ...(auth.permissions.includes('channels.curate') ? [curateNavItem] : []),
        ...(auth.roles.includes('administrator') ? adminNavItems : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
