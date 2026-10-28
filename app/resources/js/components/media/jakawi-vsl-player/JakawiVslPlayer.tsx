// Local YouTube-only presentation of the official JakawiVslPlayer.
// Keeps its real API engine; excludes course, HLS and decorative progress.
import { useEffect, useRef, useState } from 'react';
import { createYoutubeEngine, parseYoutubeId } from './youtube-engine';
import { initialPlaybackState, type VslEvent, type YoutubeEngine } from './types';

type Props = { url: string | null; poster?: string | null; onEvent?: (event: VslEvent) => void };

export function JakawiVslPlayer({ url, poster, onEvent }: Props) {
    const host = useRef<HTMLDivElement>(null);
    const region = useRef<HTMLDivElement>(null);
    const engine = useRef<YoutubeEngine | null>(null);
    const callback = useRef(onEvent);
    callback.current = onEvent;
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState(initialPlaybackState);
    const [posterFailed, setPosterFailed] = useState(false);
    let valid = false;
    try { if (url) { parseYoutubeId(url); valid = true; } } catch { /* Unapproved/invalid sources stay unavailable. */ }

    useEffect(() => { setPosterFailed(false); }, [poster]);
    useEffect(() => {
        if (!attempt || !valid || !url || !host.current) return;
        setState(initialPlaybackState);
        // No script, iframe or remote thumbnail is requested until a user clicks.
        const timeout = setTimeout(() => {
            engine.current?.dispose();
            setState(previous => ({ ...previous, status: 'error', error: { kind: 'media', message: 'YouTube está tardando en responder. Intenta de nuevo.' } }));
        }, 20000);
        engine.current = createYoutubeEngine(host.current, {
            source: { type: 'youtube', url }, origin: window.location.origin, autoplay: false,
            onState: setState,
            onEvent: event => {
                if (event.type === 'ready' || event.type === 'error') clearTimeout(timeout);
                if (event.type === 'ready') {
                    engine.current?.setMuted(false);
                    engine.current?.play();
                }
                callback.current?.(event);
            },
        });
        return () => { clearTimeout(timeout); engine.current?.dispose(); engine.current = null; };
    }, [attempt, valid, url]);

    const started = attempt > 0 && valid;
    const playing = state.status === 'playing';
    const busy = started && ['initializing', 'requesting', 'buffering'].includes(state.status);
    const failed = state.status === 'error';
    const action = failed ? 'Intentar de nuevo' : state.status === 'ended' ? 'Volver a ver' : state.hasPlayed ? 'Continuar video' : 'Ver presentación';
    function play() {
        if (!started || failed) setAttempt(value => value + 1);
        else engine.current?.play();
    }
    return <div ref={region} className="vsl-player" data-vsl-mode="vsl" data-vsl-state={!valid ? 'unavailable' : started ? state.status : 'poster'} role="region" aria-label="Presentación de JAKAWI">
        <div className="vsl-frame" aria-busy={busy}>
            <div ref={host} className="vsl-host" />
            {(!started || !state.hasPlayed || failed || state.status === 'ended') && <div className="vsl-cover">
                {poster && !posterFailed && <img className="vsl-poster" src={poster} alt="" onError={() => setPosterFailed(true)} />}
                <div className="vsl-cover-copy">
                    {!valid ? <><img src="/jakawi-mark.svg" alt="" width="64" height="64" /><strong>Algo nuevo empieza aquí.</strong><span>El video de JAKAWI estará aquí próximamente.</span><span className="vsl-tag">PRESENTACIÓN · PRÓXIMAMENTE</span></> : <>
                        <p className="eyebrow">CONOCE JAKAWI</p>
                        {!busy && <button type="button" className="vsl-play" onClick={play}><span aria-hidden="true">▶</span> {action}</button>}
                    </>}
                </div>
            </div>}
            {busy && <div className="vsl-status" role="status"><span className="vsl-spinner" aria-hidden="true" /> {state.status === 'buffering' ? 'Cargando video…' : 'Conectando con YouTube…'}</div>}
        </div>
        {failed && <p className="vsl-error" role="alert">No pudimos reproducir el video. Revisa tu conexión o inténtalo de nuevo.</p>}
        {started && state.ready && !failed && <div className="vsl-controls" role="group" aria-label="Controles del video">
            <button type="button" onClick={() => playing || busy ? engine.current?.pause() : play()}>{playing || busy ? 'Pausar' : state.status === 'ended' ? 'Volver a ver' : 'Reproducir'}</button>
            <button type="button" aria-pressed={state.muted} onClick={() => engine.current?.setMuted(!state.muted)}>{state.muted ? 'Activar sonido' : 'Silenciar'}</button>
            <button type="button" onClick={() => { void region.current?.requestFullscreen?.().catch(() => undefined); }}>Pantalla completa</button>
        </div>}
        {started && state.status === 'blocked' && <p className="vsl-error" role="status">Pulsa Reproducir para continuar.</p>}
        <p className="mt-3 text-center text-xs leading-5 text-text-muted">{valid ? 'El video se carga al pulsar reproducir. YouTube aplica su política de privacidad.' : 'Estamos preparando nuestra presentación. Gracias por estar desde el comienzo.'}</p>
    </div>;
}
