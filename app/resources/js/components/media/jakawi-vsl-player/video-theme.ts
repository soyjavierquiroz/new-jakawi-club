/** Solid instance overrides keep text at WCAG AA contrast (4.5:1). */
export function videoThemeColors(colors?: { accent?: string; text?: string; overlay?: string }) {
    const hex = (color?: string) => color && /^#[\da-f]{6}$/i.test(color) ? color : undefined;
    const luminance = (color: string) => {
        const channels = [1, 3, 5].map(offset => parseInt(color.slice(offset, offset + 2), 16) / 255)
            .map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
        return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
    };
    const accent = hex(colors?.accent);
    const overlay = hex(colors?.overlay);
    const requestedText = hex(colors?.text);
    if (!overlay && !requestedText) return { accent };
    const background = overlay ?? '#111111';
    const foreground = requestedText ?? '#fff8f2';
    const a = luminance(background), b = luminance(foreground);
    const text = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05) >= 4.5
        ? foreground : a > 0.179 ? '#000000' : '#ffffff';
    return { accent, text, overlay: background };
}
