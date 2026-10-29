export type VslSource = { type: 'youtube'; url: string };

export type PlaybackStatus =
    | 'initializing'
    | 'ready'
    | 'requesting'
    | 'playing'
    | 'buffering'
    | 'paused'
    | 'ended'
    | 'blocked'
    | 'error';

export interface PlaybackError {
    kind: 'autoplay-blocked' | 'playback-blocked' | 'media' | 'source';
    message: string;
    code?: number;
}

export interface PlaybackCapabilities {
    seek: boolean;
    mute: boolean;
    volume: boolean;
}

export interface PlaybackState {
    status: PlaybackStatus;
    ready: boolean;
    hasPlayed: boolean;
    currentTime: number;
    duration: number;
    /** Native metadata, when the provider exposes it. */
    aspectRatio?: number;
    muted: boolean;
    volume: number;
    error: PlaybackError | null;
}

export type VslEvent =
    | {
          type:
              | 'ready'
              | 'playing'
              | 'paused'
              | 'progress'
              | 'ended'
              | 'audio-activated';
          state: PlaybackState;
      }
    | { type: 'error'; state: PlaybackState; error: PlaybackError };

export interface JakawiVslPlayerProps {
    source: VslSource;
    /** Local host supports the official marketing VSL contract. */
    mode?: 'vsl';
    poster?: string;
    title?: string;
    autoplay?: 'muted' | 'off' | boolean;
    theme?: 'inherit' | 'custom';
    colors?: { accent?: string; text?: string; overlay?: string };
    fit?: 'cover' | 'contain';
    /** Marketing videos always use a 3:4 cover frame at every viewport. */
    marketingFrame?: boolean;
    focalPosition?: string;
    /** Stable layout fixed before loading. Auto uses sourceAspectRatio or defaults
     * to horizontal (vertical for the development fixture), never media metadata.
     * Embedded content requires explicit framing.
     */
    framing?: 'embedded-vertical' | 'native-vertical' | 'horizontal' | {
        kind: 'auto' | 'native-vertical' | 'horizontal' | 'embedded-vertical';
        sourceAspectRatio?: number;
        contentAspectRatio?: number;
        viewportAspectRatio?: number;
        focalX?: number;
        focalY?: number;
    };
    /** Decorative curve exponent (1–6); never reported as media progress. */
    progressAcceleration?: number;
    /** Independent decorative clock horizon in seconds (default 90). */
    progressSeconds?: number;
    className?: string;
    onEvent?: (event: VslEvent) => void;
}

// YouTube-only host: retain the official state/control contract without HTML5/HLS.
export const initialPlaybackState: PlaybackState = {
    status: 'initializing', ready: false, hasPlayed: false, currentTime: 0,
    duration: 0, muted: true, volume: 1, error: null,
};
export function shouldAutoplay(options: { autoplay: boolean; reducedMotion: boolean; saveData: boolean }): boolean {
    return options.autoplay && !options.reducedMotion && !options.saveData;
}
export interface YoutubeEngine {
    getState(): PlaybackState;
    getCapabilities(): PlaybackCapabilities;
    play(): void;
    pause(): void;
    seek(time: number): boolean;
    activateAudio(): void;
    setMuted(muted: boolean): void;
    setVolume(volume: number): void;
    dispose(): void;
}
