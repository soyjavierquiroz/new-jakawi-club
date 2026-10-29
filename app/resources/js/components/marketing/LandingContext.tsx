import { createContext, useContext } from 'react';
import type { Landing } from '../../types';
import { trackMarketing, type MarketingPlacement } from './analytics';

export const LandingContext = createContext<Landing | null>(null);

export function useLandingTracking() {
    const landing = useContext(LandingContext);
    return (event: 'landing_visit' | 'cta_click' | 'video_start', placement: MarketingPlacement) => {
        if (!landing?.preview) trackMarketing(event, placement, landing?.id);
    };
}
