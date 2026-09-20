import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm, unlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { crc32, deflateRawSync, deflateSync } from 'node:zlib';

import {
    parseTrackedEntries,
    reportPath,
    ScanFailure,
    scanRepository,
} from './repository-content-scan.mjs';

const fixedNow = new Date('2026-09-09T12:00:00Z');

async function repository(files, policy = {}) {
    const root = await mkdtemp(path.join(tmpdir(), 'psikotes-content-scan-'));
    git(root, ['init', '--quiet']);
    git(root, ['config', 'user.email', 'scanner@example.test']);
    git(root, ['config', 'user.name', 'Scanner Test']);

    for (const [name, content] of Object.entries(files)) {
        const target = path.join(root, name);
        await import('node:fs/promises').then(({ mkdir }) =>
            mkdir(path.dirname(target), { recursive: true }),
        );
        await writeFile(target, content);
    }

    await writeFile(
        path.join(root, 'policy.json'),
        JSON.stringify({
            version: 1,
            max_blob_bytes: 1024,
            exceptions: [],
            ...policy,
        }),
    );
    git(root, ['add', '--all']);

    return root;
}

function git(root, args) {
    const result = spawnSync('git', ['-c', 'core.autocrlf=false', ...args], {
        cwd: root,
        encoding: 'utf8',
    });
    assert.equal(result.status, 0, result.stderr);
}

async function scan(root, kind) {
    return scanRepository({
        root,
        kind,
        policyPath: path.join(root, 'policy.json'),
        now: fixedNow,
    });
}

async function withRepository(files, operation, policy = {}) {
    const root = await repository(files, policy);

    try {
        return await operation(root);
    } finally {
        await rm(root, { recursive: true, force: true });
    }
}

function secretCanary() {
    return ['AK', 'IA', '0123456789ABCDEF'].join('');
}

function privateKeyCanary() {
    return ['-----BEGIN ', 'PRIVATE ', 'KEY-----'].join('');
}

function zip(entries) {
    const local = [];
    const central = [];
    let offset = 0;

    for (const entry of entries) {
        const name = Buffer.from(entry.name);
        const content = Buffer.from(entry.content);
        const method = entry.method ?? 8;
        const compressed = method === 8 ? deflateRawSync(content) : content;
        const checksum = crc32(content);
        const flags = entry.flags ?? 0x0800;
        const header = Buffer.alloc(30);
        header.writeUInt32LE(0x04034b50, 0);
        header.writeUInt16LE(20, 4);
        header.writeUInt16LE(flags, 6);
        header.writeUInt16LE(method, 8);
        header.writeUInt32LE(checksum, 14);
        header.writeUInt32LE(compressed.length, 18);
        header.writeUInt32LE(content.length, 22);
        header.writeUInt16LE(name.length, 26);
        local.push(header, name, compressed);

        const directory = Buffer.alloc(46);
        directory.writeUInt32LE(0x02014b50, 0);
        directory.writeUInt16LE(0x0314, 4);
        directory.writeUInt16LE(20, 6);
        directory.writeUInt16LE(flags, 8);
        directory.writeUInt16LE(method, 10);
        directory.writeUInt32LE(checksum, 16);
        directory.writeUInt32LE(compressed.length, 20);
        directory.writeUInt32LE(content.length, 24);
        directory.writeUInt16LE(name.length, 28);
        directory.writeUInt32LE(entry.externalAttributes ?? 0, 38);
        directory.writeUInt32LE(offset, 42);
        central.push(directory, name);
        offset += header.length + name.length + compressed.length;
    }

    const centralBytes = Buffer.concat(central);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(entries.length, 8);
    end.writeUInt16LE(entries.length, 10);
    end.writeUInt32LE(centralBytes.length, 12);
    end.writeUInt32LE(offset, 16);

    return Buffer.concat([...local, centralBytes, end]);
}

function docx(parts = [], { replace = true } = {}) {
    const entries = [
        {
            name: '[Content_Types].xml',
            content:
                '<?xml version="1.0"?><Types><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        },
        {
            name: '_rels/.rels',
            content:
                '<?xml version="1.0"?><Relationships><Relationship Target="word/document.xml"/></Relationships>',
        },
        {
            name: 'word/document.xml',
            content:
                '<?xml version="1.0"?><w:document><w:t>safe</w:t></w:document>',
        },
    ];

    for (const part of parts) {
        const index = entries.findIndex(({ name }) => name === part.name);

        if (index === -1 || !replace) {
            entries.push(part);
        } else {
            entries[index] = part;
        }
    }

    return zip(entries);
}

function pngChunk(type, content = Buffer.alloc(0)) {
    const name = Buffer.from(type, 'ascii');
    const header = Buffer.alloc(8);
    header.writeUInt32BE(content.length, 0);
    name.copy(header, 4);
    const checksum = Buffer.alloc(4);
    checksum.writeUInt32BE(crc32(Buffer.concat([name, content])), 0);

    return Buffer.concat([header, content, checksum]);
}

function png(
    metadata = [],
    imageData = deflateSync(Buffer.from([0, 0, 0, 0, 0])),
) {
    const header = Buffer.alloc(13);
    header.writeUInt32BE(1, 0);
    header.writeUInt32BE(1, 4);
    header[8] = 8;
    header[9] = 6;

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        pngChunk('IHDR', header),
        ...metadata,
        pngChunk('IDAT', imageData),
        pngChunk('IEND'),
    ]);
}

function dibIcon(payload = Buffer.alloc(0)) {
    const width = 32;
    const height = 32;
    const xor = Buffer.alloc(width * height * 4);
    payload.copy(xor, 0, 0, Math.min(payload.length, xor.length));
    const mask = Buffer.alloc(Math.ceil(width / 32) * 4 * height);
    const dib = Buffer.alloc(40);
    dib.writeUInt32LE(40, 0);
    dib.writeInt32LE(width, 4);
    dib.writeInt32LE(height * 2, 8);
    dib.writeUInt16LE(1, 12);
    dib.writeUInt16LE(32, 14);
    dib.writeUInt32LE(xor.length, 20);
    const image = Buffer.concat([dib, xor, mask]);
    const icon = Buffer.alloc(22);
    icon.writeUInt16LE(1, 2);
    icon.writeUInt16LE(1, 4);
    icon[6] = width;
    icon[7] = height;
    icon.writeUInt16LE(1, 10);
    icon.writeUInt16LE(32, 12);
    icon.writeUInt32LE(image.length, 14);
    icon.writeUInt32LE(icon.length, 18);

    return Buffer.concat([icon, image]);
}

function mutate(bytes, signature, fieldOffset, size, value) {
    const result = Buffer.from(bytes);
    const offset = result.indexOf(signature);
    assert.notEqual(offset, -1);
    result[`writeUInt${size * 8}LE`](value, offset + fieldOffset);

    return result;
}

test('clean tracked repository passes both independent profiles', async () => {
    await withRepository(
        { 'clean.txt': 'participant@example.test\nphone=620000000000\n' },
        async (root) => {
            assert.deepEqual((await scan(root, 'secret')).findings, []);
            assert.deepEqual((await scan(root, 'pii')).findings, []);
        },
    );
});

test('clean tracked DOCX scans all XML package parts in both profiles', async () => {
    await withRepository(
        {
            'requirements.docx': docx([
                {
                    name: 'word/settings.xml',
                    content: '<settings/>',
                    method: 0,
                },
            ]),
        },
        async (root) => {
            assert.deepEqual((await scan(root, 'secret')).findings, []);
            assert.deepEqual((await scan(root, 'pii')).findings, []);
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('DOCX logical text catches secret and PII split across Word runs', async () => {
    const token = secretCanary();
    const phone = ['+62', '81297538641'].join('');
    const document = `<?xml version="1.0"?><w:document><w:t>${token.slice(0, 8)}</w:t><w:t>${token.slice(8)}</w:t><w:t>${phone.slice(0, 6)}</w:t><w:t>${phone.slice(6)}</w:t></w:document>`;

    await withRepository(
        {
            'requirements.docx': docx([
                { name: 'word/document.xml', content: document },
            ]),
        },
        async (root) => {
            await assert.rejects(scan(root, 'secret'), /redacted finding/i);
            await assert.rejects(scan(root, 'pii'), /redacted finding/i);
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('DOCX scans document metadata comments headers and relationships without exposing values or inner names', async () => {
    const provider = ['xnd_', 'development_', 'A1b2C3d4E5f6G7h8'].join('');
    const password = ['q9Vx', '2LmP', '7ZaN', '4RtK', '8WdH', '3CyF'].join('');
    const email = ['person', '@', 'corp', '.', 'id'].join('');
    const phone = ['+62', '81297538641'].join('');
    const nik = ['327301', '150890', '0001'].join('');
    const identity = ['P123', '4567', '890'].join('');
    const file = docx([
        {
            name: 'word/_rels/document.xml.rels',
            content: `<Relationships><Relationship Target="${provider}"/></Relationships>`,
        },
        {
            name: 'docProps/core.xml',
            content: `<core><creator>${email}</creator><value>password=&quot;${password}&quot;</value></core>`,
        },
        {
            name: 'word/comments.xml',
            content: `<comments><comment>${nik}</comment></comments>`,
        },
        {
            name: 'word/header1.xml',
            content: `<header>${phone}<w:t>passport_number = &quot;${identity}&quot;</w:t></header>`,
        },
    ]);

    await withRepository(
        { 'requirements.docx': file },
        async (root) => {
            for (const [kind, rules, values] of [
                [
                    'secret',
                    ['credential_assignment', 'provider_token'],
                    [provider, password],
                ],
                [
                    'pii',
                    ['email', 'identity_assignment', 'indonesian_phone', 'nik'],
                    [email, identity, phone, nik],
                ],
            ]) {
                await assert.rejects(scan(root, kind), (error) => {
                    assert.deepEqual(
                        [
                            ...new Set(error.findings.map(({ rule }) => rule)),
                        ].sort(),
                        rules,
                    );
                    const report = JSON.stringify(error);

                    for (const value of values) {
                        assert(!report.includes(value));
                    }

                    for (const inner of [
                        'word/_rels/document.xml.rels',
                        'docProps/core.xml',
                        'word/comments.xml',
                        'word/header1.xml',
                    ]) {
                        assert(!report.includes(inner));
                    }

                    return true;
                });
            }
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('DOCX finding identifiers disclose neither common inner names nor their hash guesses', async () => {
    const token = secretCanary();
    const phone = ['+62', '81297538641'].join('');
    const innerNames = [
        'word/document.xml',
        'word/styles.xml',
        'docProps/core.xml',
        'word/comments.xml',
    ];
    const file = docx(
        innerNames.map((name) => ({
            name,
            content: `<part>${token} ${phone}</part>`,
        })),
    );

    await withRepository(
        { 'requirements.docx': file },
        async (root) => {
            for (const kind of ['secret', 'pii']) {
                await assert.rejects(scan(root, kind), (error) => {
                    const report = JSON.stringify(error);

                    for (const innerName of innerNames) {
                        const hashGuess = createHash('sha256')
                            .update(innerName)
                            .digest('hex');
                        assert(!report.includes(innerName));
                        assert(!report.includes(hashGuess));
                    }

                    return true;
                });
            }
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('both staged and working-tree DOCX snapshots are scanned', async () => {
    await withRepository(
        { 'requirements.docx': docx() },
        async (root) => {
            await writeFile(
                path.join(root, 'requirements.docx'),
                docx([
                    {
                        name: 'word/document.xml',
                        content: `<w:document><w:t>${secretCanary()}</w:t></w:document>`,
                    },
                ]),
            );
            git(root, ['add', 'requirements.docx']);
            await writeFile(path.join(root, 'requirements.docx'), docx());
            await assert.rejects(scan(root, 'secret'), /redacted finding/i);

            git(root, ['add', 'requirements.docx']);
            await writeFile(
                path.join(root, 'requirements.docx'),
                docx([
                    {
                        name: 'word/document.xml',
                        content: `<w:document><w:t>${secretCanary()}</w:t></w:document>`,
                    },
                ]),
            );
            await assert.rejects(scan(root, 'secret'), /redacted finding/i);
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('DOCX rejects malformed central records local mismatches CRC corruption and missing required parts', async () => {
    const central = Buffer.from([0x50, 0x4b, 0x01, 0x02]);
    const local = Buffer.from([0x50, 0x4b, 0x03, 0x04]);
    const clean = docx();
    const corruptions = [
        clean.subarray(0, clean.length - 1),
        mutate(clean, central, 10, 2, 0),
        mutate(mutate(clean, local, 14, 4, 1), central, 16, 4, 1),
        zip([
            { name: '[Content_Types].xml', content: '<Types/>' },
            { name: '_rels/.rels', content: '<Relationships/>' },
            { name: 'word/other.xml', content: '<other/>' },
        ]),
    ];

    for (const file of corruptions) {
        await withRepository(
            { 'requirements.docx': file },
            async (root) => {
                await assert.rejects(
                    scan(root, 'secret'),
                    /malformed or unsupported/i,
                );
            },
            { max_blob_bytes: 1024 * 1024 },
        );
    }
});

test('DOCX rejects traversal duplicate encrypted descriptor ZIP64 special-mode and unsupported-method entries', async () => {
    const central = Buffer.from([0x50, 0x4b, 0x01, 0x02]);
    const local = Buffer.from([0x50, 0x4b, 0x03, 0x04]);
    const encrypted = mutate(mutate(docx(), local, 6, 2, 1), central, 8, 2, 1);
    const descriptor = mutate(
        mutate(docx(), local, 6, 2, 0x0808),
        central,
        8,
        2,
        0x0808,
    );
    const zip64 = mutate(docx(), central, 24, 4, 0xffffffff);
    const unsupportedMethod = mutate(
        mutate(docx(), local, 8, 2, 99),
        central,
        10,
        2,
        99,
    );
    const scenarios = [
        docx([{ name: '../hidden.xml', content: '<hidden/>' }]),
        docx([{ name: 'word/document.xml', content: '<duplicate/>' }], {
            replace: false,
        }),
        docx([
            {
                name: 'word/link.xml',
                content: '<link/>',
                externalAttributes: 0xa0000000,
            },
        ]),
        encrypted,
        descriptor,
        zip64,
        unsupportedMethod,
    ];

    for (const file of scenarios) {
        await withRepository(
            { 'requirements.docx': file },
            async (root) =>
                assert.rejects(
                    scan(root, 'secret'),
                    /malformed or unsupported/i,
                ),
            { max_blob_bytes: 1024 * 1024 },
        );
    }
});

test('DOCX rejects decompression bombs unsupported media and unsafe XML without leaking inner names', async () => {
    const secretName = ['word/', 'person', '@', 'corp', '.', 'id.xml'].join('');
    const scenarios = [
        docx([
            {
                name: 'word/document.xml',
                content: `<w:document>${'x'.repeat(2048)}</w:document>`,
            },
        ]),
        docx([
            {
                name: 'word/media/image1.png',
                content: Buffer.from([0x89, 0x50]),
            },
        ]),
        docx([
            {
                name: 'word/document.xml',
                content:
                    '<!DOCTYPE x [<!ENTITY y "unsafe">]><w:document>&y;</w:document>',
            },
        ]),
        docx([
            {
                name: 'word/document.xml',
                content: '<w:document>&unknown;</w:document>',
            },
        ]),
        docx([{ name: secretName, content: '<safe/>' }]),
    ];

    for (const file of scenarios) {
        await withRepository(
            { 'requirements.docx': file },
            async (root) => {
                await assert.rejects(scan(root, 'secret'), (error) => {
                    assert.match(error.message, /malformed or unsupported/i);
                    assert(!JSON.stringify(error).includes(secretName));

                    return true;
                });
            },
            { max_blob_bytes: 1024 },
        );
    }
});

test('tracked Markdown ZIP scans every entry with redacted ordinal identifiers', async () => {
    const token = secretCanary();
    const phone = ['+62', '81297538641'].join('');
    const innerNames = ['notes/private.md', 'records/participants.md'];
    const file = zip([
        { name: innerNames[0], content: token },
        { name: innerNames[1], content: phone },
    ]);

    await withRepository(
        { 'files.zip': file },
        async (root) => {
            for (const [kind, rule] of [
                ['secret', 'aws_access_key'],
                ['pii', 'indonesian_phone'],
            ]) {
                await assert.rejects(scan(root, kind), (error) => {
                    assert(
                        error.findings.some((finding) => finding.rule === rule),
                    );
                    const report = JSON.stringify(error);

                    for (const innerName of innerNames) {
                        const hashGuess = createHash('sha256')
                            .update(innerName)
                            .digest('hex');
                        assert(!report.includes(innerName));
                        assert(!report.includes(hashGuess));
                    }

                    assert.match(report, /files\.zip#part-\d{4}/);

                    return true;
                });
            }
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('both staged and working-tree Markdown ZIP snapshots are scanned', async () => {
    await withRepository(
        { 'files.zip': zip([{ name: 'clean.md', content: 'safe' }]) },
        async (root) => {
            await writeFile(
                path.join(root, 'files.zip'),
                zip([{ name: 'staged.md', content: secretCanary() }]),
            );
            git(root, ['add', 'files.zip']);
            await writeFile(
                path.join(root, 'files.zip'),
                zip([
                    {
                        name: 'working.md',
                        content: ['+62', '81297538641'].join(''),
                    },
                ]),
            );

            await assert.rejects(scan(root, 'secret'), (error) => {
                assert(
                    error.findings.some(
                        ({ rule }) => rule === 'aws_access_key',
                    ),
                );

                return true;
            });
            await assert.rejects(scan(root, 'pii'), (error) => {
                assert(
                    error.findings.some(
                        ({ rule }) => rule === 'indonesian_phone',
                    ),
                );

                return true;
            });
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('Markdown ZIP rejects corruption traversal binary nested and excessive compression ratio', async () => {
    const clean = zip([{ name: 'clean.md', content: 'safe' }]);
    const local = Buffer.from([0x50, 0x4b, 0x03, 0x04]);
    const invalid = [
        clean.subarray(0, clean.length - 1),
        mutate(clean, local, 14, 4, 0),
        zip([{ name: '../private.md', content: 'safe' }]),
        zip([{ name: 'image.png', content: Buffer.from([0x89, 0x50]) }]),
        zip([{ name: 'nested.zip', content: clean }]),
        zip([{ name: 'bomb.md', content: 'a'.repeat(20_000) }]),
    ];

    for (const file of invalid) {
        await withRepository(
            { 'files.zip': file },
            async (root) => {
                await assert.rejects(
                    scan(root, 'secret'),
                    /ZIP is malformed or unsupported/i,
                );
            },
            { max_blob_bytes: 1024 * 1024 },
        );
    }
});

test('Markdown ZIP delegates UTF decoding to the repository scanner', async () => {
    const content = Buffer.concat([
        Buffer.from([0xff, 0xfe]),
        Buffer.from(secretCanary(), 'utf16le'),
    ]);

    await withRepository(
        { 'files.zip': zip([{ name: 'utf16.md', content }]) },
        async (root) => {
            await assert.rejects(scan(root, 'secret'), (error) => {
                assert(
                    error.findings.some(
                        ({ rule }) => rule === 'aws_access_key',
                    ),
                );

                return true;
            });
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('scanner implementation and tests contain no detectable secret or PII literals', async () => {
    const scanner = await readFile(
        new URL('./repository-content-scan.mjs', import.meta.url),
    );
    const ooxml = await readFile(
        new URL('./ooxml-content.mjs', import.meta.url),
    );
    const zipText = await readFile(
        new URL('./zip-text-content.mjs', import.meta.url),
    );
    const imageContent = await readFile(
        new URL('./image-content.mjs', import.meta.url),
    );
    const tests = await readFile(new URL(import.meta.url));

    await withRepository(
        {
            'image-content.mjs': imageContent,
            'ooxml.mjs': ooxml,
            'scanner.mjs': scanner,
            'scanner.test.mjs': tests,
            'zip-text.mjs': zipText,
        },
        async (root) => {
            assert.deepEqual((await scan(root, 'secret')).findings, []);
            assert.deepEqual((await scan(root, 'pii')).findings, []);
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('secret profile detects known token and private key markers without returning matched bytes', async () => {
    const token = secretCanary();
    const key = privateKeyCanary();
    await withRepository({ 'leak.txt': `${token}\n${key}\n` }, async (root) => {
        await assert.rejects(scan(root, 'secret'), (error) => {
            assert(error instanceof ScanFailure);
            assert.deepEqual(
                error.findings.map(({ rule }) => rule),
                ['aws_access_key', 'private_key'],
            );
            assert(!JSON.stringify(error).includes(token));
            assert(!JSON.stringify(error).includes(key));

            return true;
        });
    });
});

test('secret profile detects high entropy credential assignment but not hashes without credential context', async () => {
    const random = ['q9Vx', '2LmP', '7ZaN', '4RtK', '8WdH', '3CyF'].join('');
    await withRepository(
        {
            'config.txt': `api_secret = "${random}"\nchecksum = "${random}"\n`,
        },
        async (root) => {
            await assert.rejects(scan(root, 'secret'), (error) => {
                assert.equal(error.findings.length, 1);
                assert.equal(error.findings[0].rule, 'credential_assignment');

                return true;
            });
        },
    );
});

test('placeholder words embedded in a high entropy secret do not bypass detection', async () => {
    const secret = ['q9Vx', 'example', '2LmP', 'dummy', '7ZaN4RtK'].join('');

    await withRepository(
        { 'config.txt': `api_secret = "${secret}"\n` },
        async (root) => {
            await assert.rejects(scan(root, 'secret'), (error) => {
                assert.equal(error.findings[0].rule, 'credential_assignment');

                return true;
            });
        },
    );
});

test('both staged index blobs and tracked working-tree bytes are scanned', async () => {
    await withRepository({ 'config.txt': 'safe\n' }, async (root) => {
        await writeFile(path.join(root, 'config.txt'), `${secretCanary()}\n`);
        git(root, ['add', 'config.txt']);
        await writeFile(path.join(root, 'config.txt'), 'safe again\n');
        await assert.rejects(scan(root, 'secret'), /redacted finding/i);

        git(root, ['add', 'config.txt']);
        await writeFile(path.join(root, 'config.txt'), `${secretCanary()}\n`);
        await assert.rejects(scan(root, 'secret'), /redacted finding/i);
    });
});

test('UTF-16LE secrets are detected and unsupported text encodings fail closed', async () => {
    await withRepository(
        { 'utf16.txt': Buffer.from(`\ufeff${secretCanary()}\n`, 'utf16le') },
        async (root) =>
            assert.rejects(scan(root, 'secret'), /redacted finding/i),
    );

    await withRepository(
        { 'invalid.txt': Buffer.from([0xc3, 0x28]) },
        async (root) => assert.rejects(scan(root, 'secret'), /encoding/i),
    );
});

test('PII profile detects non-reserved email, Indonesian phone, and valid NIK without returning values', async () => {
    const email = ['person', '@', 'corp', '.', 'id'].join('');
    const phone = ['+62', '81297538641'].join('');
    const nik = ['327301', '150890', '0001'].join('');
    await withRepository(
        { 'records.txt': `${email}\n${phone}\n${nik}\n` },
        async (root) => {
            await assert.rejects(scan(root, 'pii'), (error) => {
                assert.deepEqual(
                    error.findings.map(({ rule }) => rule),
                    ['email', 'indonesian_phone', 'nik'],
                );

                for (const value of [email, phone, nik]) {
                    assert(!JSON.stringify(error).includes(value));
                }

                return true;
            });
        },
    );
});

test('only exact reserved synthetic contacts and unrelated long numbers pass PII profile', async () => {
    await withRepository(
        {
            'fixtures.txt': [
                ['person', '@', 'example', '.', 'test'].join(''),
                ['hello', '@', 'example', '.', 'com'].join(''),
                ['nama', '@', 'email', '.', 'com'].join(''),
                '+6281234567890',
                '620000000000',
                '9007199254740991',
            ].join('\n'),
        },
        async (root) =>
            assert.deepEqual((await scan(root, 'pii')).findings, []),
    );
});

test('repository-owned synthetic phone fixtures are exact', async () => {
    const exactFixtures = [
        ['08', '0000000000'].join(''),
        ['08', '0000000001'].join(''),
        ['08', '0000000002'].join(''),
        ['08', '0000000003'].join(''),
        ['08', '0000000004'].join(''),
        ['08', '0000000005'].join(''),
        ['0812', '00000000'].join(''),
        ['62', '0000000001'].join(''),
        ['62', '0000000999'].join(''),
        ['628', '000000000'].join(''),
        ['628', '00123456'].join(''),
        ['628', '111111111'].join(''),
        ['629', '999999999'].join(''),
    ];
    await withRepository(
        {
            'fixtures.txt': exactFixtures.join('\n'),
        },
        async (root) =>
            assert.deepEqual((await scan(root, 'pii')).findings, []),
    );
});

test('synthetic phone allowlist keeps the original 18 canonical numbers', async () => {
    const source = await readFile(
        new URL('./repository-content-scan.mjs', import.meta.url),
        'utf8',
    );
    const allowlist = source.match(/const SYNTHETIC_PHONES = new Set\(\[([\s\S]*?)\]\);/);
    assert.ok(allowlist);
    const numbers = [...allowlist[1].matchAll(/'(\d+)'/g)].map((match) => match[1]);

    assert.deepEqual(numbers.sort(), [
        '620000000000',
        '620000000001',
        '620000000999',
        '628000000000',
        '6280000000000',
        '6280000000001',
        '6280000000002',
        '6280000000003',
        '6280000000004',
        '6280000000005',
        '62800123456',
        '628111111110',
        '628111111111',
        '6281200000000',
        '6281234567800',
        '628123456789',
        '6281234567890',
        '629999999999',
    ]);
});

test('synthetic phone is accepted in local and international notation only', async () => {
    const synthetic = ['0812', '00000000'].join('');
    const real = ['0812', '34567891'].join('');

    await withRepository(
        {
            'fixtures.txt': [synthetic, `62${synthetic.slice(1)}`].join('\n'),
        },
        async (root) =>
            assert.deepEqual((await scan(root, 'pii')).findings, []),
    );

    await withRepository(
        {
            'records.txt': [real, `62${real.slice(1)}`].join('\n'),
        },
        async (root) => {
            await assert.rejects(scan(root, 'pii'), (error) => {
                assert.deepEqual(
                    error.findings.map(({ rule, line }) => [rule, line]),
                    [
                        ['indonesian_phone', 1],
                        ['indonesian_phone', 2],
                    ],
                );

                return true;
            });
        },
    );
});

test('only exact repository-owned incomplete phone literals pass PII profile', async () => {
    const repeatedPrefix = ['628', '11111111'].join('');
    const paddedPrefix = ['+628', '1234567'].join('');

    await withRepository(
        {
            'tests/Feature/Admin/OrganizationInvitationTest.php': `<?php $phone = '${repeatedPrefix}'.$suffix;`,
            'tests/Feature/Admin/OrganizationPortalIsolationTest.php': `<?php $phone = '${repeatedPrefix}'.$suffix;`,
            'tests/Feature/Registration/ParticipantRegistrationTest.php': `<?php $phone = '${paddedPrefix}'.str_pad($attempt);`,
        },
        async (root) =>
            assert.deepEqual((await scan(root, 'pii')).findings, []),
    );
});

test('incomplete phone treatment rejects wrong paths and continuations', async () => {
    const repeatedPrefix = ['628', '11111111'].join('');
    const paddedPrefix = ['+628', '1234567'].join('');

    await withRepository(
        {
            'records.php': `<?php $first = '${repeatedPrefix}'.$suffix;`,
            'tests/Feature/Admin/OrganizationInvitationTest.php': `<?php $second = '${repeatedPrefix}'.trim($suffix);`,
            'tests/Feature/Registration/ParticipantRegistrationTest.php': `<?php $third = '${paddedPrefix}'.$suffix;`,
        },
        async (root) => {
            await assert.rejects(scan(root, 'pii'), (error) => {
                assert.equal(
                    error.findings.filter(
                        ({ rule }) => rule === 'indonesian_phone',
                    ).length,
                    3,
                );

                return true;
            });
        },
    );
});

test('realistic phones containing repeated or ascending digits remain PII', async () => {
    const repeated = ['6281', '1111', '1119'].join('');
    const ascending = ['+6281', '2345', '6781'].join('');

    await withRepository(
        {
            'records.txt': [repeated, ascending].join('\n'),
        },
        async (root) => {
            await assert.rejects(scan(root, 'pii'), (error) => {
                assert.equal(
                    error.findings.filter(
                        ({ rule }) => rule === 'indonesian_phone',
                    ).length,
                    2,
                );

                return true;
            });
        },
    );
});

test('quoted realistic phones followed by dotted expressions remain PII', async () => {
    const repeated = ['6281', '1111', '1119'].join('');
    const ascending = ['+6281', '2345', '6781'].join('');

    await withRepository(
        {
            'tests/Feature/Admin/OrganizationInvitationTest.php': `<?php $first = '${repeated}'.trim($suffix);`,
            'records.php': `<?php $second = '${ascending}' . trim($suffix);`,
        },
        async (root) => {
            await assert.rejects(scan(root, 'pii'), (error) => {
                assert.equal(
                    error.findings.filter(
                        ({ rule }) => rule === 'indonesian_phone',
                    ).length,
                    2,
                );

                return true;
            });
        },
    );
});

test('PII findings expose no stable candidate verifier', async () => {
    const candidates = [
        ['6281', '1111', '1119'].join(''),
        ['6281', '1111', '1129'].join(''),
    ];
    const reports = [];

    for (const phone of candidates) {
        await withRepository({ 'records.txt': `${phone}\n` }, async (root) => {
            await assert.rejects(scan(root, 'pii'), (error) => {
                reports.push(error.findings);
                assert.deepEqual(Object.keys(error.findings[0]).sort(), [
                    'kind',
                    'line',
                    'path',
                    'rule',
                ]);

                return true;
            });
        });
    }

    assert.deepEqual(reports[0], reports[1]);
});

test('control characters in reported paths are encoded against log injection', () => {
    const encoded = reportPath(
        'line\ninject\t%\u0085\u2028\u2029\u202ename.txt',
    );

    assert.equal(
        encoded,
        'line%0Ainject%09%25%C2%85%E2%80%A8%E2%80%A9%E2%80%AEname.txt',
    );
    assert.match(encoded, /^[\x20-\x7e]+$/);
});

test('strict clean PNG and ICO images pass both profiles by magic bytes', async () => {
    await withRepository(
        {
            'asset-without-extension': png(),
            'favicon.ico': dibIcon(),
        },
        async (root) => {
            assert.deepEqual((await scan(root, 'secret')).findings, []);
            assert.deepEqual((await scan(root, 'pii')).findings, []);
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('PNG text metadata and ICO raster bytes remain secret and PII scan surfaces', async () => {
    const token = secretCanary();
    const phone = ['+62', '81297538641'].join('');
    const compressedText = Buffer.concat([
        Buffer.from('audit\0\0', 'latin1'),
        deflateSync(Buffer.from(token)),
    ]);
    const internationalText = Buffer.concat([
        Buffer.from('contact\0\0\0\0\0', 'utf8'),
        Buffer.from(phone),
    ]);

    await withRepository(
        {
            'metadata.png': png([
                pngChunk('zTXt', compressedText),
                pngChunk('iTXt', internationalText),
            ]),
            'embedded.ico': dibIcon(Buffer.from(`${token}\n${phone}`)),
        },
        async (root) => {
            await assert.rejects(scan(root, 'secret'), (error) => {
                assert.deepEqual(
                    new Set(
                        error.findings.map(
                            ({ path, rule }) => `${path}:${rule}`,
                        ),
                    ),
                    new Set([
                        'embedded.ico#frame-0001:aws_access_key',
                        'metadata.png#text-0001:aws_access_key',
                    ]),
                );

                return true;
            });
            await assert.rejects(scan(root, 'pii'), (error) => {
                assert.deepEqual(
                    new Set(
                        error.findings.map(
                            ({ path, rule }) => `${path}:${rule}`,
                        ),
                    ),
                    new Set([
                        'embedded.ico#frame-0001:indonesian_phone',
                        'metadata.png#text-0002:indonesian_phone',
                    ]),
                );

                return true;
            });
        },
        { max_blob_bytes: 1024 * 1024 },
    );
});

test('PNG and ICO classifiers reject malformed, ambiguous, and hidden metadata carriers', async () => {
    const corrupted = Buffer.from(png());
    corrupted[corrupted.length - 1] ^= 0xff;
    const unknownAncillary = png([pngChunk('vpAg', Buffer.from('hidden'))]);
    const invalidRaster = png([], Buffer.from('not-a-zlib-stream'));
    const truncatedIcon = dibIcon().subarray(0, 64);

    for (const [name, bytes] of [
        ['corrupted.png', corrupted],
        ['unknown.png', unknownAncillary],
        ['invalid-raster.png', invalidRaster],
        ['truncated.ico', truncatedIcon],
    ]) {
        await withRepository({ [name]: bytes }, async (root) => {
            await assert.rejects(scan(root, 'secret'), /image/i);
        });
    }
});

test('PNG metadata decompression is bounded across all chunks', async () => {
    const compressed = deflateSync(Buffer.alloc(700, 0x41));
    const carrier = png([
        pngChunk('zTXt', Buffer.concat([Buffer.from('first\0\0'), compressed])),
        pngChunk(
            'zTXt',
            Buffer.concat([Buffer.from('second\0\0'), compressed]),
        ),
    ]);

    await withRepository(
        { 'aggregate-bomb.png': carrier },
        async (root) => {
            await assert.rejects(scan(root, 'secret'), /image/i);
        },
        { max_blob_bytes: 1024 },
    );
});

test('PII fingerprint exceptions are forbidden without private fingerprint authority', async () => {
    await withRepository(
        { 'clean.txt': 'safe' },
        async (root) => {
            await assert.rejects(scan(root, 'pii'), /PII.*exception/i);
        },
        {
            exceptions: [
                {
                    kind: 'pii',
                    rule: 'indonesian_phone',
                    path: 'records.txt',
                    fingerprint: '0'.repeat(64),
                    reason: 'No private fingerprint authority.',
                    expires_on: '2026-10-01',
                },
            ],
        },
    );
});

test('PII profile treats exact dependency metadata and obvious fixture syntax as non-participant data', async () => {
    const packageEmail = ['maintainer', '@', 'package', '.', 'dev'].join('');
    const localeFilename = ['aa_ER', '@', 'saaho', '.', 'php'].join('');
    await withRepository(
        {
            'composer.lock': JSON.stringify({
                authors: [{ email: packageEmail }],
            }),
            'fixtures.txt': `628111111110\n628123456789\n+6281234567800\n${localeFilename}\n`,
        },
        async (root) =>
            assert.deepEqual((await scan(root, 'pii')).findings, []),
    );
});

test('PII dependency-metadata treatment is exact and does not exempt a similarly named path', async () => {
    const email = ['person', '@', 'corp', '.', 'id'].join('');
    await withRepository({ 'nested/composer.lock': email }, async (root) => {
        await assert.rejects(scan(root, 'pii'), (error) => {
            assert.equal(error.findings[0].rule, 'email');

            return true;
        });
    });
});

test('only tracked files are scanned', async () => {
    await withRepository({ 'tracked.txt': 'safe' }, async (root) => {
        await writeFile(path.join(root, 'untracked.txt'), secretCanary());
        assert.deepEqual((await scan(root, 'secret')).findings, []);
    });
});

test('exact unexpired fingerprint exception is accepted', async () => {
    const token = secretCanary();
    await withRepository({ 'leak.txt': token }, async (root) => {
        let finding;
        await assert.rejects(scan(root, 'secret'), (error) => {
            [finding] = error.findings;

            return true;
        });
        await writeFile(
            path.join(root, 'policy.json'),
            JSON.stringify({
                version: 1,
                max_blob_bytes: 1024,
                exceptions: [
                    {
                        kind: 'secret',
                        rule: finding.rule,
                        path: finding.path,
                        fingerprint: finding.fingerprint,
                        reason: 'Synthetic external fixture.',
                        expires_on: '2026-10-01',
                    },
                ],
            }),
        );
        assert.deepEqual((await scan(root, 'secret')).findings, []);
    });
});

for (const scenario of [
    'wrong-kind',
    'wrong-rule',
    'wrong-path',
    'wrong-fingerprint',
    'expired',
    'stale',
    'unknown-rule',
]) {
    test(`${scenario} exception fails closed`, async () => {
        const token = secretCanary();
        await withRepository({ 'leak.txt': token }, async (root) => {
            let finding;
            await assert.rejects(scan(root, 'secret'), (error) => {
                [finding] = error.findings;

                return true;
            });
            const exception = {
                kind: 'secret',
                rule: finding.rule,
                path: finding.path,
                fingerprint: finding.fingerprint,
                reason: 'Synthetic external fixture.',
                expires_on: '2026-10-01',
            };

            if (scenario === 'wrong-kind') {
                exception.kind = 'pii';
            }

            if (scenario === 'wrong-rule') {
                exception.rule = 'private_key';
            }

            if (scenario === 'wrong-path') {
                exception.path = 'other.txt';
            }

            if (scenario === 'wrong-fingerprint') {
                exception.fingerprint = '0'.repeat(64);
            }

            if (scenario === 'expired') {
                exception.expires_on = '2026-09-08';
            }

            if (scenario === 'stale') {
                await writeFile(path.join(root, 'leak.txt'), 'safe');
                git(root, ['add', 'leak.txt']);
            }

            if (scenario === 'unknown-rule') {
                exception.rule = 'made_up_rule';
            }

            await writeFile(
                path.join(root, 'policy.json'),
                JSON.stringify({
                    version: 1,
                    max_blob_bytes: 1024,
                    exceptions: [exception],
                }),
            );
            await assert.rejects(scan(root, 'secret'), ScanFailure);
        });
    });
}

test('nonexistent calendar expiry and unknown policy fields fail closed', async () => {
    await withRepository({ 'clean.txt': secretCanary() }, async (root) => {
        await writeFile(
            path.join(root, 'policy.json'),
            JSON.stringify({
                version: 1,
                max_blob_bytes: 1024,
                exceptions: [],
                unexpected: true,
            }),
        );
        await assert.rejects(scan(root, 'secret'), /shape/i);

        let finding;
        await writeFile(
            path.join(root, 'policy.json'),
            JSON.stringify({
                version: 1,
                max_blob_bytes: 1024,
                exceptions: [],
            }),
        );
        await assert.rejects(scan(root, 'secret'), (error) => {
            [finding] = error.findings;

            return true;
        });
        await writeFile(
            path.join(root, 'policy.json'),
            JSON.stringify({
                version: 1,
                max_blob_bytes: 1024,
                exceptions: [
                    {
                        kind: 'secret',
                        rule: finding.rule,
                        path: finding.path,
                        fingerprint: finding.fingerprint,
                        reason: 'Synthetic expiry probe.',
                        expires_on: '2026-09-31',
                    },
                ],
            }),
        );
        await assert.rejects(scan(root, 'secret'), /malformed|expired/i);
    });
});

test('tracked symlink mode fails closed without requiring host symlink privileges', async () => {
    const root = await repository({ 'target.txt': 'safe' });

    try {
        const blob = spawnSync('git', ['hash-object', '-w', '--stdin'], {
            cwd: root,
            encoding: 'utf8',
            input: 'target.txt',
        });
        assert.equal(blob.status, 0, blob.stderr);
        git(root, [
            'update-index',
            '--add',
            '--cacheinfo',
            `120000,${blob.stdout.trim()},link.txt`,
        ]);
        await assert.rejects(scan(root, 'secret'), /symlink/i);
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('missing or unreadable tracked file fails closed', async () => {
    const root = await repository({ 'gone.txt': 'safe' });

    try {
        await unlink(path.join(root, 'gone.txt'));
        await assert.rejects(scan(root, 'secret'), /unreadable/i);
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('oversized tracked blob fails closed', async () => {
    await withRepository({ 'large.txt': 'x'.repeat(1025) }, async (root) => {
        await assert.rejects(scan(root, 'secret'), /maximum/i);
    });
});

test('unsupported kind and malformed policy fail closed', async () => {
    await withRepository({ 'clean.txt': 'safe' }, async (root) => {
        await assert.rejects(scan(root, 'other'), /kind/i);
        await writeFile(path.join(root, 'policy.json'), '{');
        await assert.rejects(scan(root, 'secret'), /policy/i);
    });
});

test('NUL-delimited tracked-entry parser preserves embedded newlines in paths', () => {
    const objectId = 'a'.repeat(40);
    assert.deepEqual(
        parseTrackedEntries(`100644 ${objectId} 0\tline\nbreak.txt\0`),
        [
            {
                mode: '100644',
                objectId,
                stage: 0,
                path: 'line\nbreak.txt',
            },
        ],
    );
});
