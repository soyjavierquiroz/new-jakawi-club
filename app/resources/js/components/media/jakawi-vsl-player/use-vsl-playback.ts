import { useEffect, useRef, useState } from 'react';
import { framingMetadata } from './youtube-crop';
import { createYoutubeEngine } from './youtube-engine';
import { initialPlaybackState, shouldAutoplay, type JakawiVslPlayerProps, type YoutubeEngine } from './types';

/** Official VSL lifecycle, restricted to the provider supported by this host. */
export function useVslPlayback({ source, autoplay, onEvent, framing }: JakawiVslPlayerProps) {
    framing = typeof framing === 'string' ? { kind: framing } : framing;
    const youtubeRef = useRef<HTMLDivElement>(null);
    const engineRef = useRef<YoutubeEngine | null>(null);
    const callbackRef = useRef(onEvent);
    const [state, setState] = useState(initialPlaybackState);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => { callbackRef.current = onEvent; }, [onEvent]);
    useEffect(() => {
        if (!youtubeRef.current) return;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const connection = (navigator as Navigator & {
            connection?: EventTarget & { saveData?: boolean };
        }).connection;
        const engine = createYoutubeEngine(youtubeRef.current, {
            source,
            origin: window.location.origin,
            autoplay: shouldAutoplay({
                autoplay: autoplay === 'muted' ? true : autoplay === 'off' ? false : autoplay ?? true,
                reducedMotion: reducedMotion.matches,
                saveData: connection?.saveData === true,
            }),
            presentation: framingMetadata(framing?.kind ?? 'horizontal', framing?.focalX, framing?.focalY),
            onState: setState,
            onEvent: event => callbackRef.current?.(event),
        });
        engineRef.current = engine;
        const honorPreference = () => {
            if (reducedMotion.matches || connection?.saveData) engine.pause();
        };
        reducedMotion.addEventListener('change', honorPreference);
        connection?.addEventListener('change', honorPreference);
        return () => {
            reducedMotion.removeEventListener('change', honorPreference);
            connection?.removeEventListener('change', honorPreference);
            engineRef.current = null;
            engine.dispose();
        };
    }, [source.type, source.url, autoplay, attempt]);
    return { state, youtubeRef, engineRef, retry: () => setAttempt(value => value + 1) };
}
