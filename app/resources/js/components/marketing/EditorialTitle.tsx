import type { TitleSegment, TitleStyle } from '../../types';

export const titleStyles: [TitleStyle, string][] = [
    ['normal', 'Normal / quitar énfasis'], ['bold', 'Negrita'], ['coral', 'Coral'],
    ['underline-coral', 'Subrayado coral'], ['amarillo-acento', 'Amarillo acento'],
];
const classes: Record<TitleStyle, string> = {
    normal: 'editorial-normal', bold: 'editorial-bold', coral: 'editorial-coral',
    'underline-coral': 'editorial-underline', 'amarillo-acento': 'editorial-yellow',
};
export function EditorialTitle({ text, segments }: { text: string; segments?: TitleSegment[] | null }) {
    if (!segments?.length || segments.map(segment => segment.text).join('') !== text) return <>{text}</>;
    return <>{segments.map((segment, index) => <span key={index} className={classes[segment.style] || classes.normal}>{segment.text}</span>)}</>;
}
