import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const appPath = fileURLToPath(
    new URL('../../resources/js/app.tsx', import.meta.url),
);
const composePath = fileURLToPath(
    new URL('../../compose.yaml', import.meta.url),
);
const source = await readFile(appPath, 'utf8');
const composeSource = await readFile(composePath, 'utf8');

function readAppNameContract() {
    const declaration = source.match(
        /const appName = (?<configured>import\.meta\.env\.VITE_APP_NAME) \|\| '(?<fallback>[^']+)';/,
    );

    assert.ok(
        declaration?.groups,
        'app name must retain an explicit VITE_APP_NAME override and a source fallback',
    );

    return declaration.groups;
}

test('public title suffix defaults to the ONCAM Psikotes brand', () => {
    const { fallback } = readAppNameContract();

    assert.equal(fallback, 'ONCAM Psikotes');
    assert.match(
        source,
        /title: \(title\) => \(title \? `\$\{title\} - \$\{appName\}` : appName\),/,
    );
});

test('an explicit VITE_APP_NAME still overrides the public title suffix fallback', () => {
    const { configured, fallback } = readAppNameContract();
    const explicitName = 'Configured application name';
    const resolvedName = explicitName || fallback;

    assert.equal(configured, 'import.meta.env.VITE_APP_NAME');
    assert.equal(resolvedName, explicitName);
});

test('Compose gives every app service safe public metadata defaults', () => {
    assert.match(
        composeSource,
        /^ {4}APP_NAME: \$\{APP_NAME:-ONCAM Psikotes\}$/m,
    );
    assert.match(composeSource, /^ {4}APP_LOCALE: \$\{APP_LOCALE:-id\}$/m);
});

test('Compose keeps the public application URL environment-backed', () => {
    assert.match(
        composeSource,
        /^ {4}APP_URL: \$\{APP_URL:-http:\/\/localhost:8000\}$/m,
    );
});
