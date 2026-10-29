import { useEffect, useRef, useState } from 'react';
import type { PlaybackState } from './types';
import { vslProgress } from './marketing-presentation';

/** Independent elapsed-playing clock. Never reads media time or duration. */
export function useDecorativeProgress(
    state: PlaybackState,
    sourceKey: string,
    acceleration = 3,
    seconds = 90,
    enabled = true,
) {
    const clock = useRef({ key: sourceKey, elapsed: 0 });
    const [visual, setVisual] = useState(0);
    useEffect(() => {
        if (clock.current.key !== sourceKey) {
            clock.current = { key: sourceKey, elapsed: 0 };
            setVisual(0);
        }
        if (!enabled) return;
        if (state.status === 'ended') {
            setVisual(1);
            return;
        }
        if (state.status !== 'playing') return;
        let previous = performance.now();
        let frame = 0;
        const tick = () => {
            const now = performance.now();
            clock.current.elapsed += (now - previous) / 1000;
            previous = now;
            setVisual(
                Math.min(
                    0.97,
                    vslProgress(
                        clock.current.elapsed,
                        Number.isFinite(seconds) && seconds > 0 ? seconds : 90,
                        acceleration,
                    ),
                ),
            );
            frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(frame);
    }, [sourceKey, state.status, acceleration, seconds, enabled]);
    return clock.current.key === sourceKey ? visual : 0;
}
