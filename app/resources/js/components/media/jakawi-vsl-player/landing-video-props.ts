import type { LandingContent } from '../../../types';
import type { JakawiVslPlayerProps } from './types';

/** Media is operational configuration; it never comes from editorial JSON. */
export function landingVideoProps(video: LandingContent['video'], url: string, poster: string | null): JakawiVslPlayerProps {
    const vertical = video.source_aspect_ratio === '9:16';
    return {
        source: { type: 'youtube', url },
        mode: 'vsl',
        autoplay: 'muted',
        framing: {
            kind: vertical ? 'embedded-vertical' : video.source_aspect_ratio === '3:4' ? 'native-vertical' : 'horizontal',
            ...(vertical ? {} : { sourceAspectRatio: video.source_aspect_ratio === '3:4' ? 3 / 4 : 16 / 9 }),
            viewportAspectRatio: 3 / 4,
            focalX: video.focal_x ?? 0.5,
            focalY: video.focal_y ?? 0.5,
        },
        marketingFrame: true,
        focalPosition: `${(video.focal_x ?? 0.5) * 100}% ${(video.focal_y ?? 0.5) * 100}%`,
        fit: 'cover',
        theme: 'inherit',
        poster: poster ?? undefined,
        title: 'Presentación de JAKAWI',
        progressAcceleration: 3,
        progressSeconds: 90,
    };
}
