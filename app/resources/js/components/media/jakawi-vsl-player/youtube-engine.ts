// API lifecycle/controller adapted from original YouTubePlayerAdapter; retains retry/blocked handling.
import { applyYouTubeCrop } from './youtube-crop.js';
import { initialPlaybackState } from './types';
import type { YoutubeEngine } from './types';
import type { PlaybackState, VslEvent } from './types';

export function parseYoutubeId(value: string): string {
    if (/^[\w-]{11}$/.test(value)) return value;
    const url = new URL(value);
    if (
        !['https:', 'http:'].includes(url.protocol) ||
        url.port ||
        url.username ||
        url.password
    )
        throw new Error('Invalid YouTube URL');
    const host = url.hostname.toLowerCase();
    let id: string | null = null;
    if (host === 'youtu.be') id = url.pathname.split('/')[1];
    else if (
        [
            'youtube.com',
            'www.youtube.com',
            'm.youtube.com',
            'music.youtube.com',
            'youtube-nocookie.com',
            'www.youtube-nocookie.com',
        ].includes(host)
    ) {
        const parts = url.pathname.split('/');
        id =
            url.pathname === '/watch'
                ? url.searchParams.get('v')
                : ['embed', 'shorts', 'live', 'v'].includes(parts[1])
                  ? parts[2]
                  : null;
    }
    if (!id || !/^[\w-]{11}$/.test(id)) throw new Error('Invalid YouTube ID');
    return id;
}

export interface YoutubePlayer {
    playVideo(): void;
    pauseVideo(): void;
    seekTo(time: number, allowSeekAhead: boolean): void;
    mute(): void;
    unMute(): void;
    isMuted(): boolean;
    getVolume(): number;
    setVolume(volume: number): void;
    getCurrentTime(): number;
    getDuration(): number;
    destroy(): void;
}
export interface YoutubeApi {
    Player: new (
        element: HTMLElement,
        options: {
            videoId: string;
            host: string;
            width: string;
            height: string;
            playerVars: Record<string, string | number>;
            events: {
                onReady(event: { target: YoutubePlayer }): void;
                onStateChange(event: { data: number }): void;
                onError(event: { data: number }): void;
                onAutoplayBlocked(): void;
            };
        },
    ) => YoutubePlayer;
}

type YoutubeWindow = Window & {
    YT?: YoutubeApi;
    onYouTubeIframeAPIReady?: () => void;
};
let apiPromise: Promise<YoutubeApi> | undefined;
export function loadYoutubeApi(): Promise<YoutubeApi> {
    const scope = window as YoutubeWindow;
    if (scope.YT?.Player) return Promise.resolve(scope.YT);
    if (apiPromise) return apiPromise;
    apiPromise = new Promise((resolve, reject) => {
        const previous = scope.onYouTubeIframeAPIReady;
        const script = document.createElement('script');
        const cleanup = () => {
            clearTimeout(timeout);
            script.removeEventListener('error', failed);
            if (scope.onYouTubeIframeAPIReady === ready)
                scope.onYouTubeIframeAPIReady = previous;
        };
        const failed = () => {
            cleanup();
            script.remove();
            apiPromise = undefined;
            reject(new Error('YouTube API unavailable'));
        };
        const ready = () => {
            cleanup();
            if (scope.YT?.Player) resolve(scope.YT);
            else {
                apiPromise = undefined;
                reject(new Error('YouTube API unavailable'));
            }
            previous?.();
        };
        const timeout = setTimeout(failed, 15000);
        scope.onYouTubeIframeAPIReady = ready;
        script.src = 'https://www.youtube.com/iframe_api';
        script.addEventListener('error', failed);
        document.head.append(script);
    });
    return apiPromise;
}

export function createYoutubeEngine(
    host: HTMLElement,
    options: {
        source: { type: 'youtube'; url: string };
        origin: string;
        autoplay: boolean;
        presentation?: unknown;
        onState(state: PlaybackState): void;
        onEvent?(event: VslEvent): void;
        loadApi?: () => Promise<YoutubeApi>;
    },
): YoutubeEngine {
    let state = { ...initialPlaybackState };
    let alive = true;
    let player: YoutubePlayer | undefined;
    let audioPending = false;
    let autoplayCancelled = false;
    let intent: 'autoplay' | 'manual' | null = null;
    let progress: ReturnType<typeof setInterval> | undefined;
    let pending: ReturnType<typeof setTimeout> | undefined;
    let stall: ReturnType<typeof setTimeout> | undefined;
    let initializationExpired = false;
    const readiness = setTimeout(() => {
        if (!alive || state.ready) return;
        initializationExpired = true;
        fail('media', 'YouTube está tardando en responder. Intenta de nuevo aquí.');
    }, 20000);
    const update = (patch: Partial<PlaybackState>) => {
        if (!alive) return;
        state = { ...state, ...patch };
        options.onState(state);
    };
    const emit = (type: Exclude<VslEvent['type'], 'error'>) => {
        if (alive) options.onEvent?.({ type, state });
    };
    const clearPending = () => {
        clearTimeout(pending);
        pending = undefined;
    };
    const fail = (
        kind: NonNullable<PlaybackState['error']>['kind'],
        message: string,
        code?: number,
    ) => {
        if (!alive) return;
        clearPending();
        clearTimeout(readiness);
        clearTimeout(stall);
        if (audioPending && kind.endsWith('blocked')) {
            player?.mute();
            update({ muted: true });
        }
        audioPending = false;
        intent = null;
        const error = { kind, message, code };
        update({
            status: kind.endsWith('blocked') ? 'blocked' : 'error',
            error,
        });
        options.onEvent?.({ type: 'error', state, error });
    };
    const read = () => {
        if (!alive || !player || !state.ready) return;
        update({
            currentTime: player.getCurrentTime(),
            duration: player.getDuration(),
            muted: player.isMuted(),
            volume: player.getVolume() / 100,
        });
    };
    const play = (kind: 'autoplay' | 'manual' = 'manual') => {
        if (!alive || !player || !state.ready || state.error?.kind === 'source')
            return;
        clearPending();
        intent = kind;
        update({ status: 'requesting', error: null });
        pending = setTimeout(() => {
            if (alive && state.status === 'requesting')
                fail(
                    kind === 'autoplay'
                        ? 'autoplay-blocked'
                        : 'playback-blocked',
                    'Pulsa reproducir para iniciar el video.',
                );
        }, 10000);
        player.playVideo();
    };
    const seek = (time: number) => {
        if (!alive || !player || !state.ready || !Number.isFinite(time))
            return false;
        const target = Math.max(0, Math.min(time, state.duration || 0));
        player.seekTo(target, true);
        update({ currentTime: target });
        return true;
    };
    update(initialPlaybackState);
    let id: string;
    try {
        id = parseYoutubeId(options.source.url);
    } catch {
        fail('source', 'La fuente de YouTube no está permitida.');
        return controls();
    }
    void (options.loadApi ?? loadYoutubeApi)()
        .then((api) => {
            if (!alive) return;
            const mount = document.createElement('div');
            host.append(mount);
            player = new api.Player(mount, {
                videoId: id,
                host: 'https://www.youtube-nocookie.com',
                width: '100%',
                height: '100%',
                // YouTube chooses the delivered quality. Its quality setters and
                // suggestedQuality are unsupported; impose no resolution/rate cap.
                // Crop uses actual iframe layout dimensions, never transform:scale.
                playerVars: {
                    origin: options.origin,
                    playsinline: 1,
                    controls: 0,
                    enablejsapi: 1,
                    cc_load_policy: 0,
                    iv_load_policy: 3,
                    autoplay: 0,
                    rel: 0,
                },
                events: {
                    onReady: ({ target }) => {
                        if (!alive || initializationExpired) return;
                        clearTimeout(readiness);
                        player = target;
                        applyYouTubeCrop(
                            host.querySelector('iframe'),
                            options.presentation,
                        );
                        target.mute();
                        update({ ready: true, status: 'ready', muted: true });
                        read();
                        emit('ready');
                        progress = setInterval(() => {
                            if (state.status === 'playing') {
                                read();
                                emit('progress');
                            }
                        }, 500);
                        if (options.autoplay && !autoplayCancelled)
                            play('autoplay');
                    },
                    onStateChange: ({ data }) => {
                        if (!alive || !state.ready) return;
                        read();
                        if (data === 1) {
                            clearPending();
                            clearTimeout(stall);
                            update({
                                status: 'playing',
                                hasPlayed: true,
                                error: null,
                            });
                            emit('playing');
                            if (
                                audioPending &&
                                !state.muted &&
                                state.volume > 0
                            ) {
                                audioPending = false;
                                emit('audio-activated');
                            }
                        } else if (data === 2) {
                            clearTimeout(stall);
                            if (
                                [
                                    'requesting',
                                    'error',
                                    'blocked',
                                    'ended',
                                ].includes(state.status)
                            )
                                return;
                            clearPending();
                            intent = null;
                            audioPending = false;
                            update({ status: 'paused' });
                            emit('paused');
                        } else if (data === 0) {
                            clearTimeout(stall);
                            clearPending();
                            intent = null;
                            audioPending = false;
                            update({ status: 'ended' });
                            emit('ended');
                        } else if (data === 3) {
                            update({ status: 'buffering' });
                            clearTimeout(stall);
                            stall = setTimeout(() => {
                                if (alive && state.status === 'buffering')
                                    fail('media', 'No se pudo continuar el video. Intenta de nuevo aquí.');
                            }, 20000);
                        }
                    },
                    onError: ({ data }) =>
                        fail(
                            'media',
                            data === 150 || data === 101
                                ? `YouTube rechazó la reproducción insertada (código ${data}). Intenta de nuevo aquí.`
                                : `YouTube rechazó la reproducción (código ${data}). Intenta de nuevo aquí.`,
                            data,
                        ),
                    onAutoplayBlocked: () => {
                        if (!alive || !intent) return;
                        if (audioPending) {
                            player?.mute();
                            update({ muted: true });
                        }
                        fail(
                            intent === 'autoplay'
                                ? 'autoplay-blocked'
                                : 'playback-blocked',
                            'Pulsa reproducir para iniciar el video.',
                        );
                    },
                },
            });
            const iframe = host.querySelector('iframe');
            iframe?.setAttribute('title', 'Reproductor de YouTube');
            iframe?.setAttribute(
                'allow',
                'autoplay; encrypted-media; fullscreen; picture-in-picture',
            );
        })
        .catch(() =>
            fail('media', 'No se pudo cargar YouTube. Revisa tu conexión.'),
        );
    function controls(): YoutubeEngine {
        return {
            getState: () => state,
            getCapabilities: () => ({
                seek:
                    alive &&
                    state.ready &&
                    !state.error &&
                    state.duration > 0 &&
                    typeof player?.seekTo === 'function',
                mute:
                    alive &&
                    state.ready &&
                    !state.error &&
                    typeof player?.mute === 'function' &&
                    typeof player?.unMute === 'function',
                volume:
                    alive &&
                    state.ready &&
                    !state.error &&
                    typeof player?.setVolume === 'function' &&
                    typeof player?.getVolume === 'function',
            }),
            play: () => {
                if (state.status === 'ended') seek(0);
                play();
            },
            pause: () => {
                if (!alive) return;
                autoplayCancelled = true;
                if (!player || !state.ready) return;
                clearPending();
                clearTimeout(stall);
                intent = null;
                audioPending = false;
                player.pauseVideo();
                update({ status: 'paused' });
            },
            seek,
            activateAudio: () => {
                if (!alive || !player || !state.ready) return;
                player.pauseVideo();
                seek(0);
                player.unMute();
                if (player.getVolume() === 0) player.setVolume(100);
                audioPending = true;
                read();
                play();
            },
            setMuted: (muted) => {
                if (!alive || !player || !state.ready) return;
                if (muted) player.mute();
                else player.unMute();
                read();
            },
            setVolume: (volume) => {
                if (
                    !alive ||
                    !player ||
                    !state.ready ||
                    !Number.isFinite(volume)
                )
                    return;
                player.setVolume(Math.max(0, Math.min(1, volume)) * 100);
                read();
            },
            dispose: () => {
                if (!alive) return;
                alive = false;
                clearPending();
                clearTimeout(readiness);
                clearTimeout(stall);
                clearInterval(progress);
                player?.destroy();
                host.replaceChildren();
            },
        };
    }
    return controls();
}
