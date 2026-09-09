import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { mkdtemp, readFile, rm, unlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { crc32, deflateRawSync } from 'node:zlib';

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
            content: '<?xml version="1.0"?><w:document><w:t>safe</w:t></w:document>',
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
                        [...new Set(error.findings.map(({ rule }) => rule))].sort(),
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
        docx(
            [{ name: 'word/document.xml', content: '<duplicate/>' }],
            { replace: false },
        ),
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
                assert.rejects(scan(root, 'secret'), /malformed or unsupported/i),
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
        docx([{ name: 'word/media/image1.png', content: Buffer.from([0x89, 0x50]) }]),
        docx([
            {
                name: 'word/document.xml',
                content: '<!DOCTYPE x [<!ENTITY y "unsafe">]><w:document>&y;</w:document>',
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

test('scanner implementation and tests contain no detectable secret or PII literals', async () => {
    const scanner = await readFile(
        new URL('./repository-content-scan.mjs', import.meta.url),
    );
    const ooxml = await readFile(new URL('./ooxml-content.mjs', import.meta.url));
    const tests = await readFile(new URL(import.meta.url));

    await withRepository(
        {
            'ooxml.mjs': ooxml,
            'scanner.mjs': scanner,
            'scanner.test.mjs': tests,
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

test('binary PNG and ICO blobs fail closed without a content extractor', async () => {
    for (const [name, bytes] of [
        ['asset.png', Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x00])],
        ['asset.ico', Buffer.from([0x00, 0x00, 0x01, 0x00, 0xff])],
    ]) {
        await withRepository({ [name]: bytes }, async (root) => {
            await assert.rejects(scan(root, 'secret'), /encoding/i);
        });
    }
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
