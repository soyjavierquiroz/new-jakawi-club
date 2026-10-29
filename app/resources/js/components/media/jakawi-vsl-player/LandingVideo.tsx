import type { LandingContent } from '../../../types';
import { JakawiVslPlayer } from './JakawiVslPlayer';
import { landingVideoProps } from './landing-video-props';
import type { VslEvent } from './types';

export function LandingVideo({ video, url, poster, onEvent }: {
    video: LandingContent['video']; url: string | null; poster: string | null;
    onEvent?: (event: VslEvent) => void;
}) {
    if (!url) return null;
    return <JakawiVslPlayer {...landingVideoProps(video, url, poster)} onEvent={onEvent} />;
}
