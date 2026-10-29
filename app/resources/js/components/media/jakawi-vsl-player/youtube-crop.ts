/** Adapted verbatim from Experience Platform YouTubePlayerAdapter.tsx.
 * Snapshot SHA-256: 2717c605eeb31065a29ec8886d5bcabffc9a5d35f88c135e7e8dc99b2709ce3a. */
export function getYouTubeCropLayout(metadata: unknown) {
    if (!metadata || typeof metadata !== 'object') return null;
    const presentation = (metadata as Record<string, unknown>)
        .youtubePresentation;
    if (
        !presentation ||
        typeof presentation !== 'object' ||
        (presentation as Record<string, unknown>).mode !==
            'crop_embedded_vertical'
    )
        return null;
    const value = presentation as Record<string, unknown>,
        x = typeof value.focalPointX === 'number' ? value.focalPointX : 50,
        y = typeof value.focalPointY === 'number' ? value.focalPointY : 50;
    return {
        width: '316.049383%',
        height: 'auto',
        aspectRatio: '16 / 9',
        left: `${100 - x}%`,
        top: `${100 - y}%`,
        transform: 'translate(-50%, -50%)',
    };
}
export function applyYouTubeCrop(
    iframe: HTMLIFrameElement | null,
    metadata: unknown,
) {
    const layout = getYouTubeCropLayout(metadata);
    if (!layout || !iframe) return;
    iframe.style.setProperty('inset', 'auto', 'important');
    for (const [property, value] of Object.entries(layout))
        iframe.style.setProperty(
            property.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`),
            value,
            'important',
        );
}

export function framingMetadata(kind: string, focalX = 0.5, focalY = 0.5) {
    return {
        youtubePresentation: {
            mode:
                kind === 'embedded-vertical'
                    ? 'crop_embedded_vertical'
                    : 'normal',
            focalPointX: focalX * 100,
            focalPointY: focalY * 100,
        },
    };
}
