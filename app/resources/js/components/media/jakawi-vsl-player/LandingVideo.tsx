import type { LandingContent } from '../../../types';
import { JakawiVslPlayer } from './JakawiVslPlayer';
import { landingVideoProps } from './landing-video-props';
import type { VslEvent } from './types';

export function LandingVideo({ video, url, poster, onEvent }: {
    video: LandingContent['video']; url: string | null; poster: string | null;
    onEvent?: (event: VslEvent) => void;
}) {
    if (!url) return <div className="vsl-player vsl-unavailable" data-vsl-state="unavailable" role="region" aria-label="Presentación de JAKAWI">
        <div className="vsl-unavailable-frame" style={{ aspectRatio: video.aspect_ratio === '3:4' ? '3 / 4' : '16 / 9' }}>
            {poster && <img src={poster} alt="" />}
            <div><img src="/jakawi-mark.svg" alt="" width="64" height="64" /><strong>Algo nuevo empieza aquí.</strong><p>El video de JAKAWI estará aquí próximamente.</p></div>
        </div>
    </div>;
    return <JakawiVslPlayer {...landingVideoProps(video, url, poster)} onEvent={onEvent} />;
}
