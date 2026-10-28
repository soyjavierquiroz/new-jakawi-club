import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

void createInertiaApp({
    title: (title) => title ? `${title} | JAKAWI Launch Engine` : 'JAKAWI Launch Engine',
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
