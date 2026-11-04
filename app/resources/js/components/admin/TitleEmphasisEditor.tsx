import { useRef, useState } from 'react';
import type { TitleSegment, TitleStyle } from '../../types';
import { EditorialTitle, titleStyles } from '../marketing/EditorialTitle';

export function TitleEmphasisEditor({ text, segments, id, label, onChange }: {
    text: string; segments?: TitleSegment[] | null; id: string; label: string;
    onChange: (text: string, segments: TitleSegment[] | null) => void;
}) {
    const input = useRef<HTMLTextAreaElement>(null);
    const [selection, setSelection] = useState<[number, number]>([0, 0]);
    function apply(style: TitleStyle) {
        const [start, end] = selection;
        if (start === end) return;
        const source = segments?.length ? segments : [{ text, style: 'normal' as const }];
        const next: TitleSegment[] = [];
        let offset = 0;
        for (const segment of source) {
            const stop = offset + segment.text.length;
            const cuts = [...new Set([offset, Math.max(offset, Math.min(stop, start)), Math.max(offset, Math.min(stop, end)), stop])].sort((a,b) => a-b);
            for (let i = 0; i < cuts.length - 1; i++) {
                const a = cuts[i], b = cuts[i+1];
                if (a === b) continue;
                const fragment = { text: segment.text.slice(a-offset, b-offset), style: a >= start && b <= end ? style : segment.style };
                const previous = next.at(-1);
                if (previous?.style === fragment.style) previous.text += fragment.text;
                else next.push(fragment);
            }
            offset = stop;
        }
        if (next.length > 32) return;
        onChange(text, next.every(segment => segment.style === 'normal') ? null : next);
        input.current?.focus(); input.current?.setSelectionRange(start, end);
    }
    return <div className="space-y-3" data-title-editor>
        <label className="label" htmlFor={id}>{label}</label>
        <textarea ref={input} className="input" id={id} rows={3} maxLength={1200} required value={text}
            onSelect={event => setSelection([event.currentTarget.selectionStart, event.currentTarget.selectionEnd])}
            onChange={event => onChange(event.target.value, null)} />
        <p className="text-xs text-text-secondary">Selecciona un fragmento y aplica énfasis (hasta 32 fragmentos). Editar el texto restablece el énfasis.</p>
        <div className="flex flex-wrap gap-2" role="group" aria-label={`Énfasis de ${label}`}>
            {titleStyles.map(([style, name]) => <button className="btn btn-secondary text-xs" key={style} type="button" disabled={selection[0] === selection[1]} onMouseDown={event => event.preventDefault()} onClick={() => apply(style)}>{name}</button>)}
            <button className="btn btn-secondary text-xs" type="button" onClick={() => onChange(text, null)}>Quitar todo el énfasis</button>
        </div>
        <p className="text-lg font-bold" data-title-preview><EditorialTitle text={text} segments={segments} /></p>
    </div>;
}
