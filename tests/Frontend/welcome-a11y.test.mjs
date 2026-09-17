import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const welcomePath = fileURLToPath(
    new URL('../../resources/js/pages/welcome.tsx', import.meta.url),
);
const source = await readFile(welcomePath, 'utf8');
const lucideImport = source.match(
    /import\s*\{(?<names>[\s\S]*?)\}\s*from\s*'lucide-react';/,
);

assert.ok(lucideImport?.groups?.names, 'welcome must retain its Lucide import');

const lucideNames = new Set(
    lucideImport.groups.names
        .split(',')
        .map((name) => name.trim())
        .filter(Boolean),
);
const renderedIcons = [
    ...source.matchAll(
        /<(?<name>[A-Z][A-Za-z0-9]*)\b(?<attributes>[^<>]*?)\/>/g,
    ),
].filter(
    (match) =>
        lucideNames.has(match.groups.name) || match.groups.name === 'Icon',
);

test('all decorative landing icons are hidden from assistive technology and focus', () => {
    assert.equal(
        renderedIcons.length,
        14,
        'review every newly rendered landing icon',
    );

    for (const icon of renderedIcons) {
        const line = source.slice(0, icon.index).split('\n').length;

        assert.match(
            icon.groups.attributes,
            /aria-hidden=(?:"true"|\{true\})/,
            `${icon.groups.name} on line ${line} must be aria-hidden`,
        );
        assert.match(
            icon.groups.attributes,
            /focusable="false"/,
            `${icon.groups.name} on line ${line} must not receive focus`,
        );
    }
});

test('meaningful brand and registration links retain accessible text', () => {
    assert.match(source, /aria-label="ONCAM Psikotes — beranda"/);
    assert.match(source, />\s*Daftar psikotes\s*<ArrowRight/);
    assert.match(source, />\s*Daftar sekarang\s*<ArrowRight/);
});
