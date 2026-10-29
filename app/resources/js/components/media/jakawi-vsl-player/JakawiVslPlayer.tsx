// Ported from JAKAWI 130bfac; VSL YouTube branch only.
import { useEffect, useRef, useState, type CSSProperties } from 'react';
import styles from './JakawiVslPlayer.module.css';
import { Pause, Play, RotateCcw, Volume2 } from './player-icons';
import { applyYouTubeCrop, framingMetadata } from './youtube-crop';
import { resolveFraming, verticalFraming } from './marketing-presentation';
import { useDecorativeProgress } from './use-decorative-progress';
import type { JakawiVslPlayerProps } from './types';
import { useVslPlayback } from './use-vsl-playback';
import { videoThemeColors } from './video-theme';
import { parseYoutubeId } from './youtube-engine';

export function JakawiVslPlayer(props: JakawiVslPlayerProps) {
    props = { ...props, framing: typeof props.framing === 'string' ? { kind: props.framing } : props.framing };
    const framingOptions = typeof props.framing === 'object' ? props.framing : undefined;
    const {
        poster,
        title = 'Video',
        className,
    } = props;
    const colors = videoThemeColors(props.colors);
    const { state, youtubeRef, engineRef, retry } = useVslPlayback(props);
    const sourceKey = `${props.source.type}:${props.source.url}`;
    let watchUrl: string | undefined;
    try { watchUrl = `https://www.youtube.com/watch?v=${parseYoutubeId(props.source.url)}`; } catch { /* Engine reports invalid sources. */ }
    const [progressReset, setProgressReset] = useState(0);
    const progress = useDecorativeProgress(
        state,
        `${sourceKey}:vsl:${progressReset}`,
        props.progressAcceleration,
        props.progressSeconds,
        true,
    );
    const playing = state.status === 'playing';
    const audible = playing && !state.muted && state.volume > 0;
    const [audioRequest, setAudioRequest] = useState<string | null>(null);
    const audioInFlight = useRef(false);
    useEffect(() => {
        audioInFlight.current = false;
        setAudioRequest(null);
    }, [sourceKey]);
    useEffect(() => {
        if (
            audible ||
            ['blocked', 'error', 'paused', 'ended'].includes(state.status)
        )
            setAudioRequest(null);
        if (!['requesting', 'buffering'].includes(state.status))
            audioInFlight.current = false;
    }, [audible, state.status]);
    const awaitingAudio =
        audioRequest === sourceKey &&
        ['requesting', 'buffering'].includes(state.status);
    const needsSound = (playing && !audible) || awaitingAudio;
    const manual = ['ready', 'paused', 'ended', 'blocked', 'error'].includes(
        state.status,
    );
    const showPoster = !state.hasPlayed || state.status === 'ended';
    const replay = () => {
        setProgressReset((value) => value + 1);
        engineRef.current?.seek(0);
        engineRef.current?.play();
    };
    const action =
        state.status === 'ended'
            ? 'Volver a ver'
            : state.status === 'paused'
              ? 'Continuar'
              : state.status === 'error'
                ? 'Intentar de nuevo'
                : 'Ver video';
    const framing = resolveFraming(props.source.type, props.framing);
    const desktopCover = verticalFraming(framing);
    const mobileCover = verticalFraming({ ...framing, viewportAspectRatio: 3 / 4 });
    useEffect(() => {
        const iframe = youtubeRef.current?.querySelector('iframe');
        if (!iframe) return;
        iframe.removeAttribute('style');
        // The landing frame changes through CSS, without recreating playback on resize.
        // Remove the engine's one-time inline crop so both breakpoints can use their geometry.
        if (props.marketingFrame) return;
        if (framing.kind === 'embedded-vertical') {
            applyYouTubeCrop(
                iframe,
                framingMetadata(
                    framing.kind,
                    framingOptions?.focalX,
                    framingOptions?.focalY,
                ),
            );
        } else if (framing.kind === 'native-vertical') {
            Object.assign(iframe.style, {
                width: '100%',
                height: 'auto',
                aspectRatio: String(framing.sourceAspectRatio),
                left: '50%',
                top: '50%',
                inset: 'auto',
                transform: 'translate(-50%, -50%)',
            });
        }
    }, [
        state.ready,
        framing.kind,
        framingOptions?.focalX,
        framingOptions?.focalY,
        props.marketingFrame,
        youtubeRef,
    ]);
    const soundAction = (
        <button
            type="button"
            className={styles.soundOverlay}
            data-vsl-sound
            aria-label="Enciende tus parlantes. Toca para escuchar desde el inicio"
            onClick={() => {
                if (
                    audioInFlight.current ||
                    !engineRef.current?.getState().ready
                ) return;
                audioInFlight.current = true;
                setAudioRequest(sourceKey);
                setProgressReset((value) => value + 1);
                engineRef.current?.activateAudio();
            }}
        >
            <span className={styles.soundCopy}>
                <strong>Enciende tus parlantes</strong>
                <span className={styles.soundPrompt}>
                    <Volume2 size={16} strokeWidth={1.6} aria-hidden="true" />
                    <span>Toca para escuchar</span>
                </span>
            </span>
        </button>
    );
    return (
        <div
            data-vsl-state={state.status}
            data-vsl-mode="vsl"
            data-theme={props.theme ?? 'inherit'}
            data-marketing-video-frame={props.marketingFrame ? framing.kind : undefined}
            style={{
                '--jakawi-video-accent': colors.accent,
                '--jakawi-video-text': colors.text,
                '--jakawi-video-overlay': colors.overlay,
                ...(props.marketingFrame ? {
                    '--vsl-desktop-width': `${desktopCover.widthPercent}%`,
                    '--vsl-desktop-height': `${desktopCover.heightPercent}%`,
                    '--vsl-desktop-left': `${desktopCover.leftPercent}%`,
                    '--vsl-desktop-top': `${desktopCover.topPercent}%`,
                    '--vsl-mobile-width': `${mobileCover.widthPercent}%`,
                    '--vsl-mobile-height': `${mobileCover.heightPercent}%`,
                    '--vsl-mobile-left': `${mobileCover.leftPercent}%`,
                    '--vsl-mobile-top': `${mobileCover.topPercent}%`,
                } : {}),
            } as CSSProperties}
            role="region"
            aria-label={title}
            aria-busy={
                state.status === 'requesting' || state.status === 'buffering'
            }
            className={[
                styles.player,
                framing.kind === 'horizontal' && styles.horizontal,
                props.marketingFrame && styles.marketingFrame,
                'vsl-player',
                className,
            ].filter(Boolean).join(' ')}
        >
            <div
                className={`${styles.frame} vsl-frame`}
                style={{ aspectRatio: props.marketingFrame ? `var(--vsl-frame-ratio, ${framing.viewportAspectRatio})` : framing.viewportAspectRatio }}
            >
                <div ref={youtubeRef} className={`${styles.youtube} vsl-host`} />
                <>
                    {showPoster && poster && (
                        <img
                            src={poster}
                            alt=""
                            className={styles.media}
                            style={{ objectFit: 'cover' }}
                        />
                    )}
                    {manual && (
                        <div className={styles.overlay}>
                            <button
                                type="button"
                                className={`${styles.start} vsl-play`}
                                disabled={
                                    (!state.ready &&
                                        state.error?.kind !== 'media') ||
                                    state.error?.kind === 'source'
                                }
                                onClick={
                                    state.status === 'ended'
                                        ? replay
                                        : state.status === 'error'
                                          ? retry
                                          : () => engineRef.current?.play()
                                }
                            >
                                {state.status === 'ended' ? (
                                    <RotateCcw size={26} aria-hidden="true" />
                                ) : (
                                    <Play size={28} aria-hidden="true" />
                                )}
                                {action}
                            </button>
                        </div>
                    )}
                    {needsSound && soundAction}
                    {playing && (
                        <button
                            type="button"
                            className={styles.pause}
                            aria-label="Pausar video"
                            onClick={() => engineRef.current?.pause()}
                        >
                            <Pause size={20} aria-hidden="true" />
                        </button>
                    )}
                </>
                {state.error && (
                    <p role="status" className={styles.error}>
                        {state.error.message}{' '}
                        {watchUrl && <a href={watchUrl} target="_blank" rel="noopener noreferrer">Ver en YouTube</a>}
                    </p>
                )}
                {(state.status === 'initializing' ||
                    state.status === 'requesting' ||
                    state.status === 'buffering') && (
                    <span className={styles.loading} role="status">
                        {state.status === 'initializing'
                            ? 'Preparando video…'
                            : 'Cargando…'}
                    </span>
                )}

                {(
                    <div
                        className={styles.progress}
                        aria-hidden="true"
                        data-vsl-progress="decorative"
                    >
                        <div style={{ transform: `scaleX(${progress})` }} />
                    </div>
                )}
            </div>
            <span className={styles.srOnly} role="status" aria-live="polite">
                {audible
                    ? 'Reproduciendo con sonido'
                    : needsSound
                      ? 'Reproduciendo sin sonido'
                      : state.status === 'ended'
                        ? 'Video terminado'
                        : state.status === 'paused'
                          ? 'Video pausado'
                          : ''}
            </span>
        </div>
    );
}
