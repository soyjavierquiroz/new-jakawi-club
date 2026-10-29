import type { JakawiVslPlayerProps } from './types';

/** Decorative only: never changes media time or emits retention metrics. */
export function vslProgress(
    currentTime: number,
    duration: number,
    acceleration = 3,
): number {
    if (
        !Number.isFinite(currentTime) ||
        !Number.isFinite(duration) ||
        duration <= 0
    )
        return 0;
    const ratio = Math.max(0, Math.min(1, currentTime / duration));
    const power = Number.isFinite(acceleration)
        ? Math.max(1, Math.min(6, acceleration))
        : 3;
    return 1 - Math.pow(1 - ratio, power);
}

/** Geometry for a vertical content region INSIDE a wider source canvas.
 * A real 9:16 file has sourceAspectRatio === contentAspectRatio.
 * A pillarboxed YouTube canvas has sourceAspectRatio = 16/9, contentAspectRatio = 9/16.
 * HTML5 cover geometry. YouTube embedded mode uses the original youtube-crop module.
 */
export function verticalFraming({
    sourceAspectRatio,
    contentAspectRatio = sourceAspectRatio,
    viewportAspectRatio = 3 / 4,
    focalX = 0.5,
    focalY = 0.5,
}: {
    sourceAspectRatio: number;
    contentAspectRatio?: number;
    viewportAspectRatio?: number;
    focalX?: number;
    focalY?: number;
}) {
    if (
        ![sourceAspectRatio, contentAspectRatio, viewportAspectRatio].every(
            (value) => Number.isFinite(value) && value > 0,
        ) ||
        contentAspectRatio > sourceAspectRatio
    )
        throw new Error('Invalid framing ratios');
    const clamp = (value: number) =>
        Number.isFinite(value) ? Math.max(0, Math.min(1, value)) : 0.5;
    const height = Math.max(1, viewportAspectRatio / contentAspectRatio);
    const width = sourceAspectRatio * height;
    return {
        widthPercent: (width / viewportAspectRatio) * 100,
        heightPercent: height * 100,
        leftPercent:
            (Math.max(
                viewportAspectRatio - width,
                Math.min(0, viewportAspectRatio / 2 - width * clamp(focalX)),
            ) /
                viewportAspectRatio) *
            100,
        topPercent:
            Math.max(1 - height, Math.min(0, 0.5 - height * clamp(focalY))) *
            100,
    };
}

/** Layout is resolved before loading media and never depends on playback metadata.
 * Auto uses a configured ratio; otherwise defaults to 16:9 (9:16 for the dev fixture).
 */
export function resolveFraming(
    sourceType: JakawiVslPlayerProps['source']['type'],
    framing?: JakawiVslPlayerProps['framing'],
) {
    framing = typeof framing === 'string' ? { kind: framing } : framing;
    const configured =
        framing?.sourceAspectRatio &&
        Number.isFinite(framing.sourceAspectRatio) &&
        framing.sourceAspectRatio > 0
            ? framing.sourceAspectRatio
            : undefined;
    const sourceAspectRatio =
        configured ??
        (framing?.kind === 'native-vertical'
            ? 9 / 16
            : 16 / 9);
    const kind =
        !framing || framing.kind === 'auto'
            ? sourceAspectRatio < 1
                ? 'native-vertical'
                : 'horizontal'
            : framing.kind;
    return {
        kind,
        sourceAspectRatio,
        viewportAspectRatio: framing?.viewportAspectRatio && Number.isFinite(framing.viewportAspectRatio) && framing.viewportAspectRatio > 0
            ? framing.viewportAspectRatio : kind === 'horizontal' ? 16 / 9 : 3 / 4,
        contentAspectRatio:
            kind === 'embedded-vertical'
                ? (framing?.contentAspectRatio ?? 9 / 16)
                : sourceAspectRatio,
    };
}
