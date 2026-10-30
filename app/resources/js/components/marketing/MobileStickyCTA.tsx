import { useContext, useEffect, useState } from 'react';
import { Arrow } from '../UI';
import { focusRegistration } from './registration-navigation';
import { LandingContext, useLandingTracking } from './LandingContext';
import { useRegistrationCTA } from './primitives';

export function MobileStickyCTA({ targetId = 'registro' }: { targetId?: string }) {
    const webinar = useContext(LandingContext)?.content.presentation.template === 'WEBINAR';
    const trackMarketing = useLandingTracking();
    const label = useRegistrationCTA();
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const mobile = matchMedia('(max-width: 767px)');
        let stop: (() => void) | undefined;
        function bind() {
            stop?.();
            setVisible(false);
            if (!mobile.matches) return;
            const form = document.getElementById(targetId);
            if (!form) return;
            const player = document.querySelector<HTMLElement>('[data-vsl-state]');
            const viewport = window.visualViewport;
            let formVisible = true; // Wait for the first observation, including deep links.
            let playerVisible = false;
            let playerAtBottom = false;
            let overlapObserver: IntersectionObserver | undefined;
            let observedHeight = 0;
            let fullHeight = window.innerHeight;
            let fullWidth = window.innerWidth;
            const editable = 'textarea, [contenteditable="true"], input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):not([type="button"]):not([type="submit"])';
            const update = () => {
                const active = document.activeElement;
                const editing = !!active?.matches(editable);
                const height = viewport?.height ?? window.innerHeight;
                if (fullWidth !== window.innerWidth) { fullWidth = window.innerWidth; fullHeight = window.innerHeight; }
                if (!editing) fullHeight = Math.max(fullHeight, window.innerHeight);
                const keyboard = (viewport?.scale ?? 1) === 1 && Math.max(fullHeight, document.documentElement.clientHeight) - height > 150;
                const videoState = player?.dataset.vslState;
                const videoActive = playerVisible && !!videoState && !['unavailable', 'poster', 'ended', 'error'].includes(videoState);
                const dialogOpen = !!document.querySelector('dialog[open], [aria-modal="true"]');
                setVisible(!formVisible && !editing && !keyboard && !dialogOpen && !document.fullscreenElement && (webinar || (!videoActive && !playerAtBottom && !player?.contains(active))));
            };
            // Watch only the bottom strip occupied by the CTA, without a scroll listener.
            const resized = () => {
                const height = document.documentElement.clientHeight;
                if (height !== observedHeight) {
                    observedHeight = height;
                    overlapObserver?.disconnect();
                    const clearance = document.querySelector('.mobile-sticky-space')?.getBoundingClientRect().height ?? 96;
                    overlapObserver = new IntersectionObserver(([entry]) => { playerAtBottom = entry.isIntersecting; update(); }, { rootMargin: `-${Math.max(0, height - clearance)}px 0px 0px 0px` });
                    if (player) overlapObserver.observe(player.querySelector('.vsl-frame') ?? player);
                }
                update();
            };
            const focusChanged = () => queueMicrotask(update);
            const formObserver = new IntersectionObserver(([entry]) => { formVisible = entry.isIntersecting; update(); }, { rootMargin: '0px 0px 96px 0px' });
            formObserver.observe(form);
            const playerObserver = new IntersectionObserver(([entry]) => { playerVisible = entry.isIntersecting; update(); });
            if (player) playerObserver.observe(player);
            const interactionObserver = new MutationObserver(update);
            interactionObserver.observe(form, { subtree: true, attributes: true, attributeFilter: ['open', 'aria-modal'] });
            if (player) interactionObserver.observe(player, { attributes: true, attributeFilter: ['data-vsl-state'] });
            document.addEventListener('focusin', focusChanged);
            document.addEventListener('focusout', focusChanged);
            document.addEventListener('fullscreenchange', update);
            viewport?.addEventListener('resize', resized);
            resized();
            stop = () => {
                formObserver.disconnect(); playerObserver.disconnect(); overlapObserver?.disconnect(); interactionObserver.disconnect();
                document.removeEventListener('focusin', focusChanged);
                document.removeEventListener('focusout', focusChanged);
                document.removeEventListener('fullscreenchange', update);
                viewport?.removeEventListener('resize', resized);
            };
        }
        bind();
        mobile.addEventListener('change', bind);
        return () => { stop?.(); mobile.removeEventListener('change', bind); };
    }, [targetId, webinar]);

    return <div className="mobile-sticky-space"><aside className="mobile-sticky-cta" aria-label="Sé parte de JAKAWI" hidden={!visible}>
        <a href={`#${targetId}`} className="btn btn-primary w-full" data-marketing-cta="sticky" onClick={event => { if (focusRegistration(targetId, event)) trackMarketing('cta_click', 'final'); }}>{label}<Arrow /></a>
    </aside></div>;
}
