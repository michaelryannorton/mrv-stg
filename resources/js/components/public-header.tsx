import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export default function PublicHeader() {
    const { auth } = usePage<SharedData>().props;

    return (
        <header className="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
            <div className="mx-auto flex max-w-4xl items-center justify-between px-6 py-4">
                <Link href={route('home')} className="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                    Mojave River Valley
                </Link>

                <nav className="flex items-center gap-4 text-sm">
                    <Link href={route('events.index')} className="text-[#1b1b18] hover:underline dark:text-[#EDEDEC]">
                        Community Events
                    </Link>

                    {auth.user ? (
                        <Link
                            href={route('dashboard')}
                            className="rounded-sm border border-[#19140035] px-4 py-1.5 text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                        >
                            Dashboard
                        </Link>
                    ) : (
                        <Link
                            href={route('login')}
                            className="rounded-sm border border-[#19140035] px-4 py-1.5 text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                        >
                            Log in
                        </Link>
                    )}
                </nav>
            </div>
        </header>
    );
}
