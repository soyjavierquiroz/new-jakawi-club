import { Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AccountTheme from '../AccountTheme';
import { BrandLogo, Flash } from '../UI';

export type AdminLink = { label: string; href: string };
export default function AdminLayout({ children }: { children: ReactNode }) {
    const { url, props } = usePage<{ admin_navigation?: AdminLink[]; admin_support_mode?: boolean }>();
    const [open, setOpen] = useState(false);
    const links = props.admin_navigation || [];
    const path = url.split('?')[0].replace(/\/$/, '');
    return <div className="admin-shell min-h-screen bg-background text-foreground lg:grid lg:grid-cols-[248px_minmax(0,1fr)]">
        <a href="#admin-content" className="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-surface-raised focus:p-4">Ir al contenido</a>
        <aside className="border-b border-border bg-surface-raised lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto lg:border-r lg:border-b-0">
            <div className="flex items-center justify-between gap-4 p-5 lg:block">
                <div><BrandLogo /><p className="mt-3 text-xs font-bold tracking-widest text-text-secondary">CLUB · ADMINISTRACIÓN</p></div>
                <button type="button" className="btn btn-secondary lg:hidden" aria-expanded={open} aria-controls="admin-menu" onClick={() => setOpen(!open)}>{open ? 'Cerrar' : 'Menú'}</button>
            </div>
            <nav id="admin-menu" aria-label="Administración CLUB" className={`${open ? 'block' : 'hidden'} px-3 pb-5 lg:block`}>
                {links.map(link => {
                    const active = link.href === '/admin' ? path === '/admin' : path === link.href || path.startsWith(link.href + '/');
                    return <Link key={link.href} href={link.href} aria-current={active ? 'page' : undefined} onClick={() => setOpen(false)} className={`my-1 flex min-h-12 items-center rounded-control border-l-4 px-4 py-3 text-sm font-semibold ${active ? 'border-brand bg-brand-subtle text-foreground' : 'border-transparent text-text-secondary hover:bg-surface-subtle'}`}>{link.label}</Link>;
                })}
                {!props.admin_support_mode && <div className="mt-6 border-t border-border pt-4"><Link href="/mi-cuenta" className="block px-4 py-3 text-sm font-semibold">Volver a mi cuenta ↗</Link></div>}
            </nav>
        </aside>
        <div className="min-w-0">
            <header className="flex min-h-16 flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3 sm:px-8">
                <span className="text-sm font-semibold">Centro de administración</span><AccountTheme />
                {links.some(link => link.href === '/admin') && <Link href="/admin" className="text-sm font-bold underline underline-offset-4">Volver al resumen</Link>}
            </header>
            <main id="admin-content" className="min-w-0 px-4 py-8 sm:px-8 lg:py-10"><Flash />{children}</main>
        </div>
    </div>;
}
