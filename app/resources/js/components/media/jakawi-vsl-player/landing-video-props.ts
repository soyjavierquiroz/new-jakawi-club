import type { LandingContent } from '../../../types';
import type { JakawiVslPlayerProps } from './types';

/** Media is operational configuration; it never comes from editorial JSON. */
export function landingVideoProps(video: LandingContent['video'], url: string, poster: string | null): JakawiVslPlayerProps {
    const vertical = video.source_aspect_ratio === '9:16';
    const marketing = video.aspect_ratio === '3:4';
    return {
        source: { type: 'youtube', url },
        mode: 'vsl',
        autoplay: 'muted',
        framing: {
            kind: vertical ? 'embedded-vertical' : video.source_aspect_ratio === '3:4' ? 'native-vertical' : 'horizontal',
            ...(vertical ? {} : { sourceAspectRatio: video.source_aspect_ratio === '3:4' ? 3 / 4 : 16 / 9 }),
            viewportAspectRatio: marketing ? 9 / 16 : 16 / 9,
        },
        marketingFrame: marketing,
        fit: 'cover',
        theme: 'inherit',
        poster: poster ?? undefined,
        title: 'Presentación de JAKAWI',
        progressAcceleration: 3,
        progressSeconds: 90,
    };
}
