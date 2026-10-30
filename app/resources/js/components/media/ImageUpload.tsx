import { useEffect, useRef, useState } from 'react';

type Props = {
    currentUrl?: string | null; error?: string; disabled?: boolean;
    onChange: (token: string, remove: boolean, preview: string | null) => void;
    onBusy: (busy: boolean) => void;
};

export function ImageUpload({ currentUrl, error, disabled, onChange, onBusy }: Props) {
    const input = useRef<HTMLInputElement>(null);
    const request = useRef<XMLHttpRequest | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [removed, setRemoved] = useState(false);
    const [failure, setFailure] = useState('');
    const [state, setState] = useState('');
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState(0);
    useEffect(() => () => { if (preview?.startsWith('blob:')) URL.revokeObjectURL(preview); }, [preview]);
    useEffect(() => () => { request.current?.abort(); }, []);
    function choose(file?: File) {
        if (!file || disabled || busy) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) {
            setFailure('Selecciona JPEG, PNG o WebP de hasta 10 MiB.');
            return;
        }
        request.current?.abort();
        const local = URL.createObjectURL(file);
        setPreview(local); setRemoved(false); setFailure(''); setBusy(true); onBusy(true); setProgress(0); setState('Subiendo imagen…');
        onChange('', false, local);
        const xhr = new XMLHttpRequest(); request.current = xhr;
        xhr.open('POST', '/media/uploads'); xhr.timeout = 90000;
        xhr.setRequestHeader('Accept', 'application/json');
        const csrf = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
        if (csrf) xhr.setRequestHeader('X-XSRF-TOKEN', decodeURIComponent(csrf));
        xhr.upload.onprogress = event => { if (event.lengthComputable) setProgress(Math.round(event.loaded / event.total * 100)); };
        xhr.upload.onload = () => setState('Procesando imagen…');
        const finish = () => { setBusy(false); onBusy(false); };
        const fail = (message: string) => {
            finish(); setState(''); setFailure(message); setPreview(null);
            onChange('', false, null);
        };
        xhr.onload = () => {
            let data: { token?: string; url?: string; errors?: { image?: string[] } } = {};
            try { data = JSON.parse(xhr.responseText); } catch { /* Readable proxy error below. */ }
            if (xhr.status === 200 && data.token && data.url) {
                finish(); setPreview(data.url); onChange(data.token, false, data.url); setState('Imagen lista. Guarda el borrador para conservarla.');
            } else fail(data.errors?.image?.[0] || (xhr.status === 419 ? 'Tu sesión caducó. Recarga el editor antes de subir la imagen.' : 'No pudimos subir la imagen. La imagen guardada sigue disponible.'));
        };
        xhr.onerror = xhr.ontimeout = () => fail('Error de conexión. Selecciona la imagen de nuevo.');
        const body = new FormData(); body.append('image', file); xhr.send(body);
    }
    return <fieldset disabled={disabled} className="min-w-0 space-y-3 rounded-card border border-border p-4" aria-label="Imagen promocional de tarjeta">
        <legend className="px-1 font-bold">Imagen promocional de tarjeta (opcional)</legend>
        <div className="rounded-control border-2 border-dashed border-border p-4 text-center" onDragOver={e => e.preventDefault()} onDrop={e => { e.preventDefault(); choose(e.dataTransfer.files[0]); }}>
            <p className="text-sm">ARRASTRA TU IMAGEN AQUÍ</p>
            <button type="button" disabled={busy} className="btn btn-secondary mt-3 w-full" onClick={() => input.current?.click()}>{preview || currentUrl ? 'REEMPLAZAR IMAGEN' : 'SELECCIONAR IMAGEN'}</button>
            <input ref={input} type="file" aria-label="Seleccionar imagen promocional" accept="image/jpeg,image/png,image/webp" className="sr-only" disabled={busy} onChange={e => { choose(e.target.files?.[0]); e.target.value = ''; }} />
            <p className="mt-3 text-xs text-text-secondary">JPEG, PNG o WebP · hasta 10 MiB · recomendado 16:9</p>
        </div>
        {!removed && (preview || currentUrl) && <img src={preview || currentUrl || undefined} alt="Vista previa de la imagen promocional" className="aspect-video w-full rounded-control object-cover" />}
        {busy && <progress aria-label="Progreso de subida" className="w-full" max={100} value={progress} />}
        <p role="status" className="text-sm">{state}</p>
        {(failure || error) && <p role="alert" className="text-sm text-danger">{failure || error}</p>}
        {!removed && (preview || currentUrl) && <button type="button" disabled={busy} className="btn btn-secondary" onClick={() => {
            setPreview(null); setRemoved(true); setFailure(''); setState('Imagen retirada del borrador. Guarda para aplicar el cambio.'); onChange('', true, null);
        }}>QUITAR IMAGEN</button>}
    </fieldset>;
}
