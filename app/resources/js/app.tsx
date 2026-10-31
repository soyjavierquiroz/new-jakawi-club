import { Head, createInertiaApp, usePage } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import type { Landing } from './types';

function MetadataLayout({ children }: { children: ReactNode }) {
    const page = usePage();
    const landing = ['Welcome', 'MembershipSales'].includes(page.component) ? page.props.landing as Landing | undefined : undefined;
    return <><Head><meta head-key="robots" name="robots" content={landing?.social?.robots || 'noindex, nofollow'} /></Head>{children}</>;
}

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

void createInertiaApp({
    title: (title) => title ? title : 'JAKAWI CLUB',
    layout: () => MetadataLayout,
    resolve: async (name) => {
        const page = pages[`./pages/${name}.tsx`];
        if (!page) {
            throw new Error(`Unknown page: ${name}`);
        }
        return (await page()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <StrictMode>
                <App {...props} />
            </StrictMode>,
        );
    },
    progress: { color: 'var(--brand)' },
});
