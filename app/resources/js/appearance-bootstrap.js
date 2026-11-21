// Shared pre-paint resolver and external store. Never remounts page content.
(() => {
    const valid = value => ['light', 'dark', 'system'].includes(value);
    const media = matchMedia('(prefers-color-scheme: dark)');
    const listeners = new Set();
    let identity = window.__appearance.identity;
    let error = '';
    let queue = Promise.resolve();
    const guest = () => {
        let value;
        try { value = localStorage.getItem('appearance'); } catch {}
        if (!valid(value)) value = document.cookie.split('; ').find(v => v.startsWith('appearance='))?.split('=')[1];
        return valid(value) ? value : 'system';
    };
    let preference = identity ? window.__appearance.preference : guest();
    if (!valid(preference)) preference = 'system';
    const key = () => identity ? 'appearance:' + identity : 'appearance';
    const snapshot = () => preference + ':' + error;
    const apply = () => {
        const resolved = preference === 'system' ? (media.matches ? 'dark' : 'light') : preference;
        const root = document.documentElement;
        root.classList.toggle('dark', resolved === 'dark');
        root.dataset.appearance = preference;
        root.dataset.accountTheme = resolved;
        root.style.colorScheme = resolved;
        listeners.forEach(listener => listener());
    };
    window.appearanceStore = {
        subscribe(listener) { listeners.add(listener); return () => listeners.delete(listener); },
        snapshot,
        sync(nextIdentity, nextPreference) {
            if (identity === nextIdentity) return;
            identity = nextIdentity;
            preference = identity && valid(nextPreference) ? nextPreference : guest();
            error = '';
            apply();
        },
        set(value) {
            if (!valid(value)) return;
            preference = value;
            error = '';
            try { localStorage.setItem(key(), value); } catch {}
            if (!identity) document.cookie = 'appearance=' + value + '; Path=/; Max-Age=31536000; SameSite=Lax; Secure';
            apply();
            if (!identity) return;
            const owner = identity;
            queue = queue.then(async () => {
                if (owner !== identity) return;
                try {
                    const response = await fetch('/settings/appearance', {
                        method: 'PATCH', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(document.cookie.split('; ').some(v => v.startsWith('XSRF-TOKEN=')) ? { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN=')).slice(11)) } : { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content }), 'X-Appearance-Identity': owner },
                        body: JSON.stringify({ theme_preference: value }),
                    });
                    if (!response.ok) throw new Error('save');
                } catch {
                    if (owner === identity) { error = 'No se pudo guardar la apariencia por cuenta. Tu elección se mantiene en esta sesión.'; apply(); }
                }
            });
        },
    };
    addEventListener('storage', event => {
        if (event.key !== key() && event.key !== null) return;
        if (!identity) preference = guest();
        else if (valid(event.newValue)) preference = event.newValue;
        apply();
    });
    media.addEventListener('change', apply);
    apply();
})();
