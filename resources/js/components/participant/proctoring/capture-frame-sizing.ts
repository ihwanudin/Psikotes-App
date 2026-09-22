/**
 * Pure "contain fit" sizing for periodic photo capture (SPEC.md 8A.2 /
 * owner decision item 14, PR #81: 480x360, "kalau rasio kamera berbeda,
 * gambar diperkecil sampai muat tanpa diregangkan" — if the camera's
 * aspect ratio differs, the image is shrunk to fit without being
 * stretched). `maxWidth`/`maxHeight` are caller input, never constants
 * here — this file only computes dimensions from whatever bounds it is
 * given, matching CLAUDE.md's "no thresholds baked into code" rule.
 *
 * "Shrunk to fit" means the aspect ratio is preserved and the source is
 * never upscaled: the output dimensions vary with the source's aspect
 * ratio (e.g. a 16:9 source fit into 480x360 comes out ~480x270), never
 * padded/letterboxed to force an exact 480x360 canvas.
 */

export type FrameSize = { width: number; height: number };
export type FrameSizeBounds = { maxWidth: number; maxHeight: number };

export function computeContainFitSize(
    source: FrameSize,
    bounds: FrameSizeBounds,
): FrameSize {
    if (source.width <= 0 || source.height <= 0) {
        throw new Error(
            'computeContainFitSize: source width/height must be positive.',
        );
    }

    if (bounds.maxWidth <= 0 || bounds.maxHeight <= 0) {
        throw new Error(
            'computeContainFitSize: maxWidth/maxHeight must be positive.',
        );
    }

    const scale = Math.min(
        1,
        bounds.maxWidth / source.width,
        bounds.maxHeight / source.height,
    );

    return {
        width: Math.max(1, Math.round(source.width * scale)),
        height: Math.max(1, Math.round(source.height * scale)),
    };
}
