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

export const initialPlaybackState: PlaybackState = {
    status: 'initializing', ready: false, hasPlayed: false, currentTime: 0,
    duration: 0, muted: true, volume: 1, error: null,
};

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
