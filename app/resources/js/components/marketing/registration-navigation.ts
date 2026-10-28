import type { MouseEvent } from 'react';

/** Keep a real local anchor, then focus the destination without a second scroll. */
export function focusRegistration(id: string, event: MouseEvent<HTMLAnchorElement>): boolean {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
    const target = document.getElementById(id);
    if (!target) return false;
    event.preventDefault();
    if (location.hash !== `#${id}`) history.pushState(history.state, '', `#${id}`);
    target.focus({ preventScroll: true });
    target.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
    return true;
}
