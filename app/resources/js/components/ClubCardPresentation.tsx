import { useState } from 'react';

export function ClubCardImage({ image, title }: { image: string | null; title: string }) {
    const [failed, setFailed] = useState<string | null>(null);
    return image && failed !== image ? <img src={image} alt="" loading="lazy" referrerPolicy="no-referrer" className="aspect-[16/9] w-full object-cover" onError={() => setFailed(image)} /> : <div data-club-card-fallback className="relative flex aspect-[16/9] w-full flex-col justify-between overflow-hidden bg-ink p-6 text-surface">
        <img src="/jakawi-logo.svg" alt="JAKAWI" className="w-32 rounded-control bg-surface p-2" />
        <p className="relative mt-4 line-clamp-3 break-words text-xl font-extrabold leading-snug">{title}</p>
        <span aria-hidden="true" className="absolute -right-8 -top-10 h-40 w-40 rounded-full border-[24px] border-primary/30" />
    </div>;
}
