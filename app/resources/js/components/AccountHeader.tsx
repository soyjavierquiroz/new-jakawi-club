import { Link, usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { BrandLogo } from './UI';
import AccountTheme from './AccountTheme';

type AccountLink = { label: string; href: string };
const label = (link: AccountLink) => ({ '/afiliados': 'Referidos y ganancias', 'https://jakawi.com/beneficios': 'Beneficios', 'https://jakawi.com/perfil': 'Perfil' }[link.href] || (link.label === 'Administración CLUB' ? 'Admin' : link.label));
export default function AccountHeader() {
    const { url, props } = usePage<{ account_navigation?: AccountLink[] }>();
    const links = props.account_navigation || [];
    const [open, setOpen] = useState(false);
    const [more, setMore] = useState(false);
    const [visible, setVisible] = useState(0);
    const [hash, setHash] = useState(() => window.location.hash);
    const header = useRef<HTMLElement>(null);
    const nav = useRef<HTMLElement>(null);
    const measure = useRef<HTMLDivElement>(null);
    const path = url.split(/[?#]/)[0];
    useEffect(() => {
        setOpen(false); setMore(false);
        const sync = () => setHash(window.location.hash);
        sync(); window.addEventListener('hashchange', sync);
        return () => window.removeEventListener('hashchange', sync);
    }, [url]);
    useEffect(() => {
        const outside = (event: PointerEvent) => { if (!header.current?.contains(event.target as Node)) { setOpen(false); setMore(false); } };
        const escape = (event: KeyboardEvent) => { if (event.key === 'Escape') { setOpen(false); setMore(false); header.current?.querySelector<HTMLButtonElement>('[aria-expanded="true"]')?.focus(); } };
        document.addEventListener('pointerdown', outside); document.addEventListener('keydown', escape);
        return () => { document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); };
    }, []);
    useLayoutEffect(() => {
        const resize = () => {
            if (!nav.current || !measure.current) return;
            const sizes = Array.from(measure.current.children).map(child => child.getBoundingClientRect().width);
            const all = sizes.reduce((sum, width) => sum + width, 0) + Math.max(0, sizes.length - 1) * 24;
            const available = nav.current.clientWidth;
            if (all <= available) { setVisible(links.length); return; }
            // Reserve the measured logout and More controls; overflow never creates another row.
            let used = (sizes.at(-1) || 0) + 84;
            let count = 0;
            for (const size of sizes.slice(0, -1)) { if (used + size + 24 > available) break; used += size + 24; count++; }
            setVisible(count);
        };
        const observer = new ResizeObserver(resize);
        if (nav.current) observer.observe(nav.current);
        if (measure.current) observer.observe(measure.current);
        void document.fonts.ready.then(resize); resize();
        return () => observer.disconnect();
    }, [props.account_navigation, links.length]);
    const item = (link: AccountLink) => {
        const active = link.href.includes('#') ? path === link.href.split('#')[0] && hash === '#' + link.href.split('#')[1] : path === link.href;
        const shared = { className: 'account-nav-link', 'aria-current': active ? 'page' as const : undefined, onClick: () => { setOpen(false); setMore(false); } };
        return link.href.startsWith('https:') || link.href.includes('#')
            ? <a key={link.href} href={link.href} {...shared}>{label(link)}</a>
            : <Link key={link.href} href={link.href} {...shared}>{label(link)}</Link>;
    };
    const logout = <Link href="/logout" method="post" as="button" className="account-nav-link" onClick={() => { setOpen(false); setMore(false); }}>Cerrar sesión</Link>;
    return <header ref={header} className="site-header account-header">
        <div className="account-header-bar">
            <Link href="/mi-cuenta" aria-label="JAKAWI, mi cuenta" className="account-header-brand"><BrandLogo /></Link>
            <nav ref={nav} className="account-desktop-nav" aria-label="Mi cuenta">
                {links.slice(0, visible).map(item)}
                {visible < links.length && <div className="account-more"><button type="button" className="account-nav-link" aria-expanded={more} aria-controls="account-more-menu" onClick={() => setMore(!more)}>Más <span aria-hidden="true">⌄</span></button>{more && <div id="account-more-menu" className="account-dropdown">{links.slice(visible).map(item)}</div>}</div>}
                {logout}
            </nav>
            <button type="button" className="account-icon-button account-mobile-toggle" aria-label={open ? 'Cerrar menú' : 'Abrir menú de cuenta'} aria-expanded={open} aria-controls="account-mobile-menu" onClick={() => setOpen(!open)}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d={open ? 'm6 6 12 12M6 18 18 6' : 'M4 6h16M4 12h16M4 18h16'} /></svg></button>
            <AccountTheme />
            <div ref={measure} className="account-nav-measure" aria-hidden="true">{links.map(link => <span key={link.href}>{label(link)}</span>)}<span>Cerrar sesión</span></div>
        </div>
        {open && <nav id="account-mobile-menu" className="account-dropdown account-mobile-menu" aria-label="Mi cuenta móvil">{links.map(item)}{logout}</nav>}
    </header>;
}
