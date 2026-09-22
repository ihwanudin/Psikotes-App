import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolvePageLayout } from './resolve-page-layout.ts';

const here = dirname(fileURLToPath(import.meta.url));
const pagesDir = join(here, 'pages');

/**
 * Every real Inertia page name, derived from the actual files on disk
 * under resources/js/pages/** — not a hand-maintained list, so a future
 * page added there is automatically covered here without anyone
 * remembering to update this test. Mirrors how Inertia's page name maps
 * to a file path: relative to pages/, no extension, forward slashes.
 */
function realPageNames(): string[] {
    return readdirSync(pagesDir, { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile() && entry.name.endsWith('.tsx'))
        .map((entry) => {
            const relativeDir = entry
                .parentPath!.slice(pagesDir.length)
                .replace(/^[/\\]/, '')
                .replaceAll('\\', '/');
            const base = entry.name.replace(/\.tsx$/, '');

            return relativeDir === '' ? base : `${relativeDir}/${base}`;
        });
}

test('every real page under resources/js/pages resolves a layout without throwing', () => {
    const names = realPageNames();

    // Sanity check on the harness itself: if this codebase's page tree
    // is ever empty, the loop below would trivially "pass" without
    // testing anything.
    assert.ok(names.length > 0, 'expected at least one real page file');

    for (const name of names) {
        assert.doesNotThrow(
            () => resolvePageLayout(name),
            `resolvePageLayout("${name}") threw — add a case for this page`,
        );
    }
});

test('an unrecognized page name throws instead of silently resolving to null', () => {
    assert.throws(() => resolvePageLayout('some/future/admin-page'));
});
