import { Buffer } from 'node:buffer';
import { crc32, inflateRawSync } from 'node:zlib';

const CENTRAL_SIGNATURE = 0x02014b50;
const END_SIGNATURE = 0x06054b50;
const LOCAL_SIGNATURE = 0x04034b50;
const MAX_COMMENT_BYTES = 0xffff;
const UTF8_FLAG = 0x0800;

export class OoxmlContentError extends Error {
    constructor() {
        super('DOCX package is malformed or unsupported.');
        this.name = 'OoxmlContentError';
    }
}

/**
 * Extracts only bounded XML text from a strict, non-encrypted DOCX package.
 * Inner names never leave this boundary; callers receive opaque part IDs.
 */
export function extractDocxTextParts(
    bytes,
    { maxTotalBytes, maxEntries = 256 },
) {
    const entries = extractStrictZipEntries(bytes, {
        maxTotalBytes,
        maxEntries,
        minEntries: 3,
    });
    assertDocxShape(entries);

    const orderedParts = [...entries].sort((left, right) =>
        left.name < right.name ? -1 : left.name > right.name ? 1 : 0,
    );

    return orderedParts.flatMap(({ content }, index) => {
        const xml = decodeXml(content);
        const entities = decodeEntities(xml);
        const identity = `part-${String(index + 1).padStart(4, '0')}`;

        return [
            { reportId: `${identity}/raw`, bytes: Buffer.from(xml) },
            {
                reportId: `${identity}/entities`,
                bytes: Buffer.from(entities),
            },
            {
                reportId: `${identity}/text`,
                bytes: Buffer.from(projectXmlText(xml)),
            },
        ];
    });
}

/**
 * Shared strict ZIP structure reader. Callers must keep entry names internal.
 */
export function extractStrictZipEntries(
    bytes,
    {
        maxTotalBytes,
        maxEntries = 256,
        minEntries = 1,
        maxCompressionRatio = 100,
    },
) {
    if (
        !Buffer.isBuffer(bytes) ||
        !Number.isSafeInteger(maxTotalBytes) ||
        maxTotalBytes < 1 ||
        !Number.isSafeInteger(maxEntries) ||
        maxEntries < 1 ||
        !Number.isSafeInteger(minEntries) ||
        minEntries < 1 ||
        minEntries > maxEntries ||
        !Number.isSafeInteger(maxCompressionRatio) ||
        maxCompressionRatio < 1
    ) {
        reject();
    }

    const endOffset = findEndRecord(bytes);
    const entryCount = bytes.readUInt16LE(endOffset + 10);
    const centralSize = bytes.readUInt32LE(endOffset + 12);
    const centralOffset = bytes.readUInt32LE(endOffset + 16);

    if (
        bytes.readUInt16LE(endOffset + 4) !== 0 ||
        bytes.readUInt16LE(endOffset + 6) !== 0 ||
        bytes.readUInt16LE(endOffset + 8) !== entryCount ||
        entryCount < minEntries ||
        entryCount > maxEntries ||
        entryCount === 0xffff ||
        centralSize === 0xffffffff ||
        centralOffset === 0xffffffff ||
        centralOffset + centralSize !== endOffset
    ) {
        reject();
    }

    const entries = readCentralDirectory(
        bytes,
        centralOffset,
        centralSize,
        entryCount,
        maxTotalBytes,
        maxCompressionRatio,
    );

    return readLocalEntries(bytes, entries, centralOffset);
}

function findEndRecord(bytes) {
    const first = Math.max(0, bytes.length - (22 + MAX_COMMENT_BYTES));
    let found = -1;

    for (let offset = bytes.length - 22; offset >= first; offset -= 1) {
        if (
            bytes.readUInt32LE(offset) === END_SIGNATURE &&
            offset + 22 + bytes.readUInt16LE(offset + 20) === bytes.length
        ) {
            if (found !== -1 || bytes.readUInt16LE(offset + 20) !== 0) {
                reject();
            }

            found = offset;
        }
    }

    if (found === -1) {
        reject();
    }

    return found;
}

function readCentralDirectory(
    bytes,
    centralOffset,
    centralSize,
    entryCount,
    maximum,
    maxCompressionRatio,
) {
    const entries = [];
    const names = new Set();
    let total = 0;
    let offset = centralOffset;

    for (let ordinal = 0; ordinal < entryCount; ordinal += 1) {
        ensureRange(bytes, offset, 46);

        if (bytes.readUInt32LE(offset) !== CENTRAL_SIGNATURE) {
            reject();
        }

        const flags = bytes.readUInt16LE(offset + 8);
        const method = bytes.readUInt16LE(offset + 10);
        const compressedSize = bytes.readUInt32LE(offset + 20);
        const uncompressedSize = bytes.readUInt32LE(offset + 24);
        const nameLength = bytes.readUInt16LE(offset + 28);
        const extraLength = bytes.readUInt16LE(offset + 30);
        const commentLength = bytes.readUInt16LE(offset + 32);
        const localOffset = bytes.readUInt32LE(offset + 42);
        const madeBy = bytes.readUInt16LE(offset + 4) >>> 8;
        const fileType = bytes.readUInt32LE(offset + 38) >>> 16 & 0xf000;
        const recordLength = 46 + nameLength + extraLength + commentLength;
        ensureRange(bytes, offset, recordLength);

        if (
            nameLength === 0 ||
            extraLength !== 0 ||
            commentLength !== 0 ||
            bytes.readUInt16LE(offset + 34) !== 0 ||
            compressedSize === 0xffffffff ||
            uncompressedSize === 0xffffffff ||
            localOffset === 0xffffffff ||
            (flags & ~UTF8_FLAG) !== 0 ||
            ![0, 8].includes(method) ||
            compressedSize > maximum ||
            uncompressedSize > maximum ||
            (compressedSize === 0 && uncompressedSize !== 0) ||
            (compressedSize > 0 &&
                uncompressedSize / compressedSize > maxCompressionRatio) ||
            (madeBy === 3 && fileType !== 0 && fileType !== 0x8000)
        ) {
            reject();
        }

        const nameBytes = bytes.subarray(offset + 46, offset + 46 + nameLength);
        const name = decodeEntryName(nameBytes);
        const canonical = name.toLowerCase();

        if (names.has(canonical)) {
            reject();
        }

        names.add(canonical);

        total += uncompressedSize;

        if (!Number.isSafeInteger(total) || total > maximum) {
            reject();
        }

        entries.push({
            name,
            nameBytes: Buffer.from(nameBytes),
            flags,
            method,
            checksum: bytes.readUInt32LE(offset + 16),
            compressedSize,
            uncompressedSize,
            localOffset,
        });
        offset += recordLength;
    }

    if (offset !== centralOffset + centralSize) {
        reject();
    }

    return entries;
}

function decodeEntryName(bytes) {
    let name;

    try {
        name = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch {
        reject();
    }

    const segments = name.split('/');

    if (
        name.startsWith('/') ||
        name.endsWith('/') ||
        name.includes('\\') ||
        /^[A-Za-z]:/.test(name) ||
        !/^[A-Za-z0-9_.[\]/-]+$/.test(name) ||
        segments.some((segment) => segment === '' || segment === '.' || segment === '..')
    ) {
        reject();
    }

    return name;
}

function readLocalEntries(bytes, entries, centralOffset) {
    const ordered = [...entries].sort(
        (left, right) => left.localOffset - right.localOffset,
    );
    const contentByName = new Map();
    let expectedOffset = 0;

    for (const entry of ordered) {
        const offset = entry.localOffset;
        ensureRange(bytes, offset, 30);

        if (
            offset !== expectedOffset ||
            bytes.readUInt32LE(offset) !== LOCAL_SIGNATURE ||
            bytes.readUInt16LE(offset + 6) !== entry.flags ||
            bytes.readUInt16LE(offset + 8) !== entry.method ||
            bytes.readUInt32LE(offset + 14) !== entry.checksum ||
            bytes.readUInt32LE(offset + 18) !== entry.compressedSize ||
            bytes.readUInt32LE(offset + 22) !== entry.uncompressedSize ||
            bytes.readUInt16LE(offset + 28) !== 0
        ) {
            reject();
        }

        const nameLength = bytes.readUInt16LE(offset + 26);
        const nameStart = offset + 30;
        ensureRange(bytes, nameStart, nameLength + entry.compressedSize);

        if (
            nameLength !== entry.nameBytes.length ||
            !bytes.subarray(nameStart, nameStart + nameLength).equals(entry.nameBytes)
        ) {
            reject();
        }

        const dataStart = nameStart + nameLength;
        const compressed = bytes.subarray(
            dataStart,
            dataStart + entry.compressedSize,
        );
        const content = decompress(entry, compressed);
        contentByName.set(entry.name, content);
        expectedOffset = dataStart + entry.compressedSize;
    }

    if (expectedOffset !== centralOffset) {
        reject();
    }

    return entries.map(({ name }) => ({ name, content: contentByName.get(name) }));
}

function decompress(entry, compressed) {
    let content;

    if (entry.method === 0) {
        if (entry.compressedSize !== entry.uncompressedSize) {
            reject();
        }

        content = Buffer.from(compressed);
    } else {
        try {
            const inflated = inflateRawSync(compressed, {
                info: true,
                maxOutputLength: Math.max(1, entry.uncompressedSize),
            });

            if (inflated.engine.bytesWritten !== compressed.length) {
                reject();
            }

            content = inflated.buffer;
        } catch {
            reject();
        }
    }

    if (
        content.length !== entry.uncompressedSize ||
        crc32(content) !== entry.checksum
    ) {
        reject();
    }

    return content;
}

function assertDocxShape(entries) {
    const names = new Set(entries.map(({ name }) => name.toLowerCase()));

    for (const required of [
        '[content_types].xml',
        '_rels/.rels',
        'word/document.xml',
    ]) {
        if (!names.has(required)) {
            reject();
        }
    }

    if (
        entries.some(({ name }) => {
            const lower = name.toLowerCase();

            return !(lower.endsWith('.xml') || lower.endsWith('.rels'));
        })
    ) {
        reject();
    }
}

function decodeXml(bytes) {
    let content;

    try {
        if (bytes.subarray(0, 2).equals(Buffer.from([0xff, 0xfe]))) {
            content = new TextDecoder('utf-16le', { fatal: true }).decode(
                bytes.subarray(2),
            );
        } else if (bytes.subarray(0, 2).equals(Buffer.from([0xfe, 0xff]))) {
            content = new TextDecoder('utf-16be', { fatal: true }).decode(
                bytes.subarray(2),
            );
        } else {
            content = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
        }
    } catch {
        reject();
    }

    if (
        /<!\s*(?:DOCTYPE|ENTITY)\b/i.test(content) ||
        [...content].some((character) => !isXmlCharacter(character))
    ) {
        reject();
    }

    decodeEntities(content);

    return content;
}

function decodeEntities(content) {
    if (/&(?!(?:amp|lt|gt|apos|quot|#\d+|#x[0-9a-f]+);)/i.test(content)) {
        reject();
    }

    const decoded = content.replace(
        /&(?:amp|lt|gt|apos|quot|#\d+|#x[0-9a-f]+);/gi,
        (entity) => {
            const known = {
                '&amp;': '&',
                '&lt;': '<',
                '&gt;': '>',
                '&apos;': "'",
                '&quot;': '"',
            }[entity.toLowerCase()];

            if (known !== undefined) {
                return known;
            }

            const hexadecimal = entity[2].toLowerCase() === 'x';
            const value = Number.parseInt(
                entity.slice(hexadecimal ? 3 : 2, -1),
                hexadecimal ? 16 : 10,
            );

            if (!Number.isSafeInteger(value) || !isXmlCodePoint(value)) {
                reject();
            }

            return String.fromCodePoint(value);
        },
    );

    return decoded;
}

function projectXmlText(xml) {
    let result = '';
    let offset = 0;

    while (offset < xml.length) {
        if (xml.startsWith('<!--', offset)) {
            offset = after(xml, '-->', offset + 4);
        } else if (xml.startsWith('<![CDATA[', offset)) {
            const end = xml.indexOf(']]>', offset + 9);

            if (end === -1) {
                reject();
            }

            result += xml.slice(offset + 9, end);
            offset = end + 3;
        } else if (xml.startsWith('<?', offset)) {
            offset = after(xml, '?>', offset + 2);
        } else if (xml[offset] === '<') {
            offset = afterTag(xml, offset + 1);
        } else {
            result += xml[offset];
            offset += 1;
        }
    }

    return decodeEntities(result);
}

function after(content, marker, start) {
    const offset = content.indexOf(marker, start);

    if (offset === -1) {
        reject();
    }

    return offset + marker.length;
}

function afterTag(content, start) {
    let quote = null;

    for (let offset = start; offset < content.length; offset += 1) {
        const character = content[offset];

        if (quote === null && (character === '"' || character === "'")) {
            quote = character;
        } else if (character === quote) {
            quote = null;
        } else if (quote === null && character === '>') {
            return offset + 1;
        } else if (quote === null && character === '<') {
            reject();
        }
    }

    reject();
}

function isXmlCharacter(character) {
    return isXmlCodePoint(character.codePointAt(0));
}

function isXmlCodePoint(value) {
    return (
        value === 0x09 ||
        value === 0x0a ||
        value === 0x0d ||
        (value >= 0x20 && value <= 0xd7ff) ||
        (value >= 0xe000 && value <= 0xfffd) ||
        (value >= 0x10000 && value <= 0x10ffff)
    );
}

function ensureRange(bytes, offset, length) {
    if (
        !Number.isSafeInteger(offset) ||
        !Number.isSafeInteger(length) ||
        offset < 0 ||
        length < 0 ||
        offset + length > bytes.length
    ) {
        reject();
    }
}

function reject() {
    throw new OoxmlContentError();
}
