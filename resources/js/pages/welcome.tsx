import PublicHeader from '@/components/public-header';
import { Head, Link } from '@inertiajs/react';

export default function Welcome() {
    return (
        <>
            <Head title="Mojave River Valley Community">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
            </Head>

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <PublicHeader />

                <main className="mx-auto max-w-4xl px-6 py-16 text-center">
                    <h1 className="mb-4 text-3xl font-semibold">Find things to do in the Mojave River Valley</h1>
                    <p className="mx-auto mb-8 max-w-xl text-[#706f6c] dark:text-[#A1A09A]">
                        A regional calendar of community events — verified before they're listed, and
                        growing as more sources and curators join in.
                    </p>
                    <Link
                        href={route('events.index')}
                        className="inline-block rounded-sm border border-black bg-[#1b1b18] px-6 py-2.5 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    >
                        Browse Community Events
                    </Link>
                </main>
            </div>
        </>
    );
}
