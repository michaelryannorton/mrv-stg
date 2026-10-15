import PublicLayout from '@/layouts/public-layout';
import { Head, Link } from '@inertiajs/react';

// Placeholder front page. On staging the invite-only gate sends signed-out visitors to the login instead, and the
// real front page (port phase 3) replaces this one.
export default function Welcome() {
    return (
        <PublicLayout activeId="home">
            <Head title="Mojave River Valley Community" />

            <div className="mx-auto max-w-4xl px-6 py-16 text-center">
                <h1 className="mb-4 text-3xl font-semibold">Find things to do in the Mojave River Valley</h1>
                <p className="mx-auto mb-8 max-w-xl text-[#706f6c]">
                    A regional calendar of community events — verified before they're listed, and growing as more sources and curators join in.
                </p>
                <Link
                    href={route('events.index')}
                    className="inline-block rounded-sm border border-black bg-[#1b1b18] px-6 py-2.5 text-sm font-medium text-white hover:bg-black"
                >
                    Browse Community Events
                </Link>
            </div>
        </PublicLayout>
    );
}
