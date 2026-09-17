import {
    extractStrictZipEntries,
    OoxmlContentError,
} from './ooxml-content.mjs';

export class ZipTextContentError extends Error {
    constructor() {
        super('Text ZIP is malformed or unsupported.');
        this.name = 'ZipTextContentError';
    }
}

/**
 * Extracts bounded Markdown bytes from a strict ZIP package. Inner names are
 * used only for validation and canonical ordering; callers receive ordinals.
 */
export function extractZipTextParts(
    bytes,
    { maxTotalBytes, maxEntries = 256, maxCompressionRatio = 100 },
) {
    let entries;

    try {
        entries = extractStrictZipEntries(bytes, {
            maxTotalBytes,
            maxEntries,
            maxCompressionRatio,
        });
    } catch (error) {
        if (error instanceof OoxmlContentError) {
            reject();
        }

        throw error;
    }

    if (
        entries.some(({ name }) => !name.toLowerCase().endsWith('.md'))
    ) {
        reject();
    }

    return [...entries]
        .sort((left, right) =>
            left.name < right.name ? -1 : left.name > right.name ? 1 : 0,
        )
        .map(({ content }, index) => ({
            reportId: `part-${String(index + 1).padStart(4, '0')}`,
            bytes: content,
        }));
}

function reject() {
    throw new ZipTextContentError();
}
