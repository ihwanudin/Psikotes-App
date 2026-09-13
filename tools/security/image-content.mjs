import { Buffer } from 'node:buffer';
import { crc32, inflateSync } from 'node:zlib';

const PNG_SIGNATURE = Buffer.from([
    0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
]);
const PNG_CHUNKS = new Set([
    'IHDR',
    'PLTE',
    'tRNS',
    'pHYs',
    'tEXt',
    'zTXt',
    'iTXt',
    'IDAT',
    'IEND',
]);

export class ImageContentError extends Error {
    constructor() {
        super('Image is malformed or unsupported.');
        this.name = 'ImageContentError';
    }
}

export function imageKind(bytes) {
    if (bytes.subarray(0, PNG_SIGNATURE.length).equals(PNG_SIGNATURE)) {
        return 'png';
    }

    if (
        bytes.length >= 6 &&
        bytes.readUInt16LE(0) === 0 &&
        bytes.readUInt16LE(2) === 1
    ) {
        return 'ico';
    }

    return null;
}

export function extractImageTextParts(bytes, { maxTotalBytes }) {
    const kind = imageKind(bytes);

    if (kind === 'png') {
        return extractPngText(bytes, maxTotalBytes);
    }

    if (kind === 'ico') {
        return extractIcoText(bytes, maxTotalBytes);
    }

    reject();
}

function extractPngText(bytes, maximum) {
    if (bytes.length > maximum || bytes.length < PNG_SIGNATURE.length + 37) {
        reject();
    }

    let offset = PNG_SIGNATURE.length;
    let chunks = 0;
    let state = 'header';
    let header;
    let paletteEntries = 0;
    let remainingTextBytes = maximum;
    const singletonChunks = new Set();
    const imageData = [];
    const text = [];

    while (offset < bytes.length) {
        if (offset + 12 > bytes.length || chunks >= 1024) {
            reject();
        }

        const length = bytes.readUInt32BE(offset);
        const end = offset + 12 + length;

        if (end > bytes.length) {
            reject();
        }

        const typeBytes = bytes.subarray(offset + 4, offset + 8);
        const type = typeBytes.toString('ascii');
        const data = bytes.subarray(offset + 8, offset + 8 + length);
        const expectedCrc = bytes.readUInt32BE(offset + 8 + length);

        if (
            !/^[A-Za-z]{4}$/.test(type) ||
            type[2] !== type[2].toUpperCase() ||
            !PNG_CHUNKS.has(type) ||
            crc32(Buffer.concat([typeBytes, data])) !== expectedCrc
        ) {
            reject();
        }

        if (chunks === 0) {
            if (type !== 'IHDR' || length !== 13) {
                reject();
            }

            header = validatePngHeader(data);
            state = 'before-data';
        } else if (type === 'IHDR' || state === 'ended') {
            reject();
        } else if (type === 'PLTE') {
            if (
                state !== 'before-data' ||
                singletonChunks.has(type) ||
                length === 0 ||
                length > 768 ||
                length % 3 !== 0 ||
                [0, 4].includes(header.colorType)
            ) {
                reject();
            }

            singletonChunks.add(type);
            paletteEntries = length / 3;
        } else if (type === 'tRNS') {
            if (singletonChunks.has(type)) {
                reject();
            }

            validateTransparency(data, header.colorType, paletteEntries, state);
            singletonChunks.add(type);
        } else if (type === 'pHYs') {
            if (
                state !== 'before-data' ||
                singletonChunks.has(type) ||
                length !== 9 ||
                data[8] > 1
            ) {
                reject();
            }

            singletonChunks.add(type);
        } else if (['tEXt', 'zTXt', 'iTXt'].includes(type)) {
            if (state === 'in-data') {
                state = 'after-data';
            }

            const content = extractPngTextChunk(type, data, remainingTextBytes);
            remainingTextBytes -= content.length;
            text.push(content);
        } else if (type === 'IDAT') {
            if (state === 'after-data' || state === 'ended' || length === 0) {
                reject();
            }

            if (header.colorType === 3 && paletteEntries === 0) {
                reject();
            }

            state = 'in-data';
            imageData.push(data);
        } else if (type === 'IEND') {
            if (state !== 'in-data' && state !== 'after-data') {
                reject();
            }

            if (length !== 0 || end !== bytes.length) {
                reject();
            }

            state = 'ended';
        }

        offset = end;
        chunks += 1;
    }

    if (state !== 'ended') {
        reject();
    }

    validatePngRaster(imageData, header, maximum);

    return text.map((content, index) => ({
        reportId: `text-${String(index + 1).padStart(4, '0')}`,
        bytes: content,
    }));
}

function validatePngHeader(data) {
    const width = data.readUInt32BE(0);
    const height = data.readUInt32BE(4);
    const bitDepth = data[8];
    const colorType = data[9];
    const validDepths = new Map([
        [0, new Set([1, 2, 4, 8, 16])],
        [2, new Set([8, 16])],
        [3, new Set([1, 2, 4, 8])],
        [4, new Set([8, 16])],
        [6, new Set([8, 16])],
    ]);

    if (
        width === 0 ||
        height === 0 ||
        width > 100_000 ||
        height > 100_000 ||
        width * height > 100_000_000 ||
        !validDepths.get(colorType)?.has(bitDepth) ||
        data[10] !== 0 ||
        data[11] !== 0 ||
        data[12] > 1
    ) {
        reject();
    }

    if (data[12] !== 0) {
        reject();
    }

    return { width, height, bitDepth, colorType };
}

function validatePngRaster(chunks, header, maximum) {
    const channels = new Map([
        [0, 1],
        [2, 3],
        [3, 1],
        [4, 2],
        [6, 4],
    ]).get(header.colorType);
    const rowBytes = Math.ceil((header.width * channels * header.bitDepth) / 8);
    const expectedBytes = (rowBytes + 1) * header.height;

    if (
        !Number.isSafeInteger(expectedBytes) ||
        expectedBytes > Math.min(64 * 1024 * 1024, maximum * 64)
    ) {
        reject();
    }

    try {
        const compressed = Buffer.concat(chunks);
        const inflated = inflateSync(compressed, {
            info: true,
            maxOutputLength: expectedBytes,
        });

        if (
            inflated.buffer.length !== expectedBytes ||
            inflated.engine.bytesWritten !== compressed.length
        ) {
            reject();
        }

        for (
            let offset = 0;
            offset < inflated.buffer.length;
            offset += rowBytes + 1
        ) {
            if (inflated.buffer[offset] > 4) {
                reject();
            }
        }
    } catch (error) {
        if (error instanceof ImageContentError) {
            throw error;
        }

        reject();
    }
}

function validateTransparency(data, colorType, paletteEntries, state) {
    if (state !== 'before-data') {
        reject();
    }

    const valid =
        (colorType === 0 && data.length === 2) ||
        (colorType === 2 && data.length === 6) ||
        (colorType === 3 &&
            paletteEntries > 0 &&
            data.length > 0 &&
            data.length <= paletteEntries);

    if (!valid) {
        reject();
    }
}

function extractPngTextChunk(type, data, maximum) {
    const separator = data.indexOf(0);

    if (separator < 1 || separator > 79) {
        reject();
    }

    const keyword = data.subarray(0, separator);
    validateLatinKeyword(keyword);
    let values;

    if (type === 'tEXt') {
        values = [keyword, data.subarray(separator + 1)];
    } else if (type === 'zTXt') {
        if (data[separator + 1] !== 0 || separator + 2 > data.length) {
            reject();
        }

        values = [
            keyword,
            inflateBounded(data.subarray(separator + 2), maximum),
        ];
    } else {
        values = parseInternationalText(data, separator, maximum);
    }

    const content = values
        .map((value, index) =>
            index === 0
                ? Buffer.from(value.toString('latin1'), 'utf8')
                : transcodeUtf8OrLatin1(value, type === 'iTXt'),
        )
        .reduce(
            (combined, value) =>
                Buffer.concat([combined, Buffer.from('\n'), value]),
            Buffer.alloc(0),
        );

    if (content.length > maximum) {
        reject();
    }

    return content;
}

function parseInternationalText(data, separator, maximum) {
    const compressionFlag = data[separator + 1];
    const compressionMethod = data[separator + 2];
    let offset = separator + 3;

    if (
        ![0, 1].includes(compressionFlag) ||
        compressionMethod !== 0 ||
        offset > data.length
    ) {
        reject();
    }

    const languageEnd = data.indexOf(0, offset);
    const translatedEnd =
        languageEnd === -1 ? -1 : data.indexOf(0, languageEnd + 1);

    if (languageEnd === -1 || translatedEnd === -1) {
        reject();
    }

    const language = data.subarray(offset, languageEnd);

    if ([...language].some((byte) => byte > 0x7f)) {
        reject();
    }

    const translated = data.subarray(languageEnd + 1, translatedEnd);
    const source = data.subarray(translatedEnd + 1);
    const content =
        compressionFlag === 1 ? inflateBounded(source, maximum) : source;

    return [data.subarray(0, separator), language, translated, content];
}

function validateLatinKeyword(keyword) {
    if (
        keyword[0] === 0x20 ||
        keyword[keyword.length - 1] === 0x20 ||
        [...keyword].some(
            (byte) => byte === 0 || byte === 0x7f || (byte > 0 && byte < 0x20),
        )
    ) {
        reject();
    }
}

function transcodeUtf8OrLatin1(value, utf8) {
    if (!utf8) {
        return Buffer.from(value.toString('latin1'), 'utf8');
    }

    try {
        const decoded = new TextDecoder('utf-8', { fatal: true }).decode(value);

        return Buffer.from(decoded, 'utf8');
    } catch {
        reject();
    }
}

function inflateBounded(bytes, maximum) {
    try {
        return inflateSync(bytes, { maxOutputLength: maximum });
    } catch {
        reject();
    }
}

function extractIcoText(bytes, maximum) {
    if (bytes.length > maximum || bytes.length < 22) {
        reject();
    }

    const count = bytes.readUInt16LE(4);
    const directoryEnd = 6 + count * 16;

    if (count < 1 || count > 256 || directoryEnd > bytes.length) {
        reject();
    }

    const entries = [];

    for (let index = 0; index < count; index += 1) {
        const entry = 6 + index * 16;
        const width = bytes[entry] || 256;
        const height = bytes[entry + 1] || 256;
        const length = bytes.readUInt32LE(entry + 8);
        const offset = bytes.readUInt32LE(entry + 12);

        if (
            bytes[entry + 3] !== 0 ||
            length === 0 ||
            offset < directoryEnd ||
            offset + length > bytes.length
        ) {
            reject();
        }

        entries.push({ width, height, offset, length, index });
    }

    const ordered = [...entries].sort(
        (left, right) => left.offset - right.offset,
    );
    let expectedOffset = directoryEnd;

    for (const entry of ordered) {
        if (entry.offset !== expectedOffset) {
            reject();
        }

        expectedOffset += entry.length;
    }

    if (expectedOffset !== bytes.length) {
        reject();
    }

    const parts = [];

    for (const entry of entries) {
        const frame = bytes.subarray(entry.offset, entry.offset + entry.length);
        const reportId = `frame-${String(entry.index + 1).padStart(4, '0')}`;

        if (imageKind(frame) === 'png') {
            for (const part of extractPngText(frame, maximum)) {
                parts.push({
                    reportId: `${reportId}-${part.reportId}`,
                    bytes: part.bytes,
                });
            }
        } else {
            validateDibFrame(frame, entry.width, entry.height);
            parts.push({ reportId, bytes: printableBytes(frame) });
        }
    }

    return parts;
}

function validateDibFrame(frame, width, height) {
    if (frame.length < 40 || frame.readUInt32LE(0) !== 40) {
        reject();
    }

    const dibWidth = frame.readInt32LE(4);
    const dibHeight = frame.readInt32LE(8);
    const planes = frame.readUInt16LE(12);
    const bits = frame.readUInt16LE(14);
    const compression = frame.readUInt32LE(16);
    const colors = frame.readUInt32LE(32);

    if (
        dibWidth !== width ||
        dibHeight !== height * 2 ||
        planes !== 1 ||
        ![1, 4, 8, 24, 32].includes(bits) ||
        compression !== 0
    ) {
        reject();
    }

    const maximumColors = bits <= 8 ? 2 ** bits : 0;
    const paletteColors = colors === 0 ? maximumColors : colors;

    if (paletteColors > maximumColors) {
        reject();
    }

    const paletteBytes = paletteColors * 4;
    const xorBytes = Math.ceil((width * bits) / 32) * 4 * height;
    const maskBytes = Math.ceil(width / 32) * 4 * height;

    if (40 + paletteBytes + xorBytes + maskBytes !== frame.length) {
        reject();
    }
}

function printableBytes(bytes) {
    const runs = [];
    let start = -1;

    for (let index = 0; index <= bytes.length; index += 1) {
        const printable =
            index < bytes.length &&
            bytes[index] >= 0x20 &&
            bytes[index] <= 0x7e;

        if (printable && start === -1) {
            start = index;
        } else if (!printable && start !== -1) {
            if (index - start >= 7) {
                runs.push(bytes.subarray(start, index));
            }

            start = -1;
        }
    }

    return Buffer.concat(runs.flatMap((run) => [run, Buffer.from('\n')]));
}

function reject() {
    throw new ImageContentError();
}
