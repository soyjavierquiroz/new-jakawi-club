import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { BrandLogo } from './UI';
import AccountTheme from './AccountTheme';

type AccountLink = { label: string; href: string };
export default function AccountHeader() {
    const { url, props } = usePage<{ account_navigation?: AccountLink[] }>();
    const [open, setOpen] = useState(false);
    const [hash, setHash] = useState(() => window.location.hash);
    useEffect(() => {
        const sync = () => setHash(window.location.hash);
        sync();
        window.addEventListener('hashchange', sync);
        return () => window.removeEventListener('hashchange', sync);
    }, [url]);
    const path = url.split(/[?#]/)[0];
    return <header className="site-header border-b border-border bg-surface-raised">
        <div className="shell py-4">
            <div className="flex items-center justify-between gap-4">
                <Link href="/mi-cuenta" aria-label="JAKAWI, mi cuenta"><BrandLogo /></Link>
                <div className="ml-auto"><AccountTheme /></div>
                <button type="button" className="btn btn-secondary account-menu-toggle lg:hidden" aria-expanded={open} aria-controls="account-menu" onClick={() => setOpen(!open)}>{open ? 'Cerrar menú' : 'Menú de cuenta'}</button>
            </div>
            <nav id="account-menu" aria-label="Mi cuenta" className={`${open ? 'block' : 'hidden'} mt-4 lg:block`}>
                <div className="flex flex-col gap-1 lg:flex-row lg:flex-wrap">
                    {(props.account_navigation || []).map(link => {
                        const active = link.href.includes('#') ? path === '/mi-cuenta' && hash === '#mi-membresia' : path === link.href && !hash;
                        const style = `flex min-h-11 items-center rounded-control px-3 py-3 text-sm font-semibold ${active ? 'bg-brand-subtle text-foreground border-b-2 border-brand' : 'text-text-secondary hover:bg-surface-subtle'}`;
                        return link.href.startsWith('https:') || link.href.includes('#')
                            ? <a key={link.href} href={link.href} aria-current={active ? 'location' : undefined} onClick={() => setOpen(false)} className={style}>{link.label}</a>
                            : <Link key={link.href} href={link.href} aria-current={active ? 'page' : undefined} onClick={() => setOpen(false)} className={style}>{link.label}</Link>;
                    })}
                    <Link href="/logout" method="post" as="button" className="min-h-11 px-3 py-3 text-left text-sm font-semibold underline underline-offset-4">Cerrar sesión</Link>
                </div>
            </nav>
        </div>
    </header>;
}
