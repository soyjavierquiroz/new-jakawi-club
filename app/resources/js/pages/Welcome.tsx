import { Head } from '@inertiajs/react';

type WelcomeProps = {
    heading: string;
    message: string;
};

export default function Welcome({ heading, message }: WelcomeProps) {
    return (
        <>
            <Head title="Próximamente" />
            <main className="relative grid min-h-svh place-items-center overflow-hidden bg-[#0b2026] px-6 py-16 text-[#f6f1e8] sm:px-12">
                <div aria-hidden="true" className="pointer-events-none absolute -right-48 -top-48 size-[36rem] rounded-full bg-[#34725e]/15 blur-3xl" />
                <section className="relative w-full max-w-3xl border-t border-white/20 py-10 sm:py-14" aria-labelledby="welcome-heading">
                    <div className="mb-16 flex items-center gap-3 text-xs font-medium tracking-[0.24em] text-[#d3dcd4] sm:mb-24">
                        <span aria-hidden="true" className="size-2 rounded-full bg-[#f4a477]" />
                        JAKAWI.CLUB
                    </div>
                    <h1 id="welcome-heading" className="max-w-2xl text-balance text-5xl leading-[1.05] font-semibold tracking-[-0.045em] sm:text-7xl">
                        {heading}
                    </h1>
                    <p className="mt-8 text-lg leading-relaxed text-[#f4a477] sm:text-xl">{message}</p>
                </section>
            </main>
        </>
    );
}
