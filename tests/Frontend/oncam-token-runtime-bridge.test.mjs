import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import { build } from 'vite';

import {
    GENERATED_CSS_FILENAME,
    oncamTokenRuntimeBridge,
    transformOncamTokens,
} from '../../tools/design-tokens/oncam-runtime-bridge.mjs';

const tokenFile = new URL(
    '../../resources/design-tokens/oncam.tokens.json',
    import.meta.url,
);
const appCssFile = new URL('../../resources/css/app.css', import.meta.url);
const viteConfigFile = new URL('../../vite.config.ts', import.meta.url);

const clone = (value) => structuredClone(value);

async function canonicalTokens() {
    return JSON.parse(await readFile(tokenFile, 'utf8'));
}

function runtimeName(path) {
    const [layer, maybeMode, ...rest] = path.split('.');
    const parts = ['shared', 'light', 'dark'].includes(maybeMode)
        ? rest
        : [maybeMode, ...rest];

    return `--oncam-${layer}-${parts
        .map((part) =>
            part
                .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
                .replace(/[^a-zA-Z0-9]+/g, '-')
                .toLowerCase(),
        )
        .join('-')}`;
}

function leafEntries(root, path = [], inheritedType) {
    if (!root || typeof root !== 'object' || Array.isArray(root)) {
        return [];
    }

    const type = root.$type ?? inheritedType;

    if (Object.hasOwn(root, '$value')) {
        return [{ path: path.join('.'), type, value: root.$value }];
    }

    return Object.keys(root)
        .filter((key) => !key.startsWith('$'))
        .flatMap((key) => leafEntries(root[key], [...path, key], type));
}

test('emits the accepted 538-token census with deterministic LF output', async () => {
    const tokens = await canonicalTokens();
    const first = transformOncamTokens(tokens);
    const second = transformOncamTokens(clone(tokens));

    assert.deepEqual(first.census, {
        total: 538,
        primitive: 102,
        semantic: 188,
        component: 248,
    });
    assert.equal(first.css, second.css);
    assert.equal(first.css.endsWith('\n'), true);
    assert.equal(first.css.includes('\r'), false);
});

test('uses stable mode-independent names and light/dark selectors', async () => {
    const { css } = transformOncamTokens(await canonicalTokens());

    assert.match(css, /^:root \{\n/m);
    assert.match(css, /^\.dark \{\n/m);
    assert.doesNotMatch(
        css,
        /--oncam-(semantic|component)-(light|dark|shared)-/,
    );
    assert.match(css, /--oncam-primitive-color-brand-green: #005F41;/);
    assert.match(
        css,
        /--oncam-semantic-color-action-primary-background: var\(--oncam-primitive-color-brand-green\);/,
    );
});

test('preserves every semantic and component alias as an emitted var target', async () => {
    const tokens = await canonicalTokens();
    const { css } = transformOncamTokens(tokens);
    const leaves = leafEntries(tokens);
    const emitted = new Set(
        [
            ...css.matchAll(
                /^\s+(--oncam-(?:primitive|semantic|component)-[^:]+):/gm,
            ),
        ].map(([, name]) => name),
    );

    for (const leaf of leaves.filter(
        ({ path }) => !path.startsWith('primitive.'),
    )) {
        assert.match(String(leaf.value), /^\{[^{}]+\}$/);
        const target = String(leaf.value).slice(1, -1);
        assert.equal(
            emitted.has(runtimeName(target)),
            true,
            `${leaf.path} targets missing ${target}`,
        );
    }

    for (const line of css
        .split('\n')
        .filter((entry) => /^\s+--oncam-(semantic|component)-/.test(entry))) {
        assert.match(
            line,
            /: var\(--oncam-(primitive|semantic|component)-[^)]+\);$/,
        );
    }
});

test('maps destructive button values through light and dark aliases', async () => {
    const { css } = transformOncamTokens(await canonicalTokens());
    const root = css.slice(css.indexOf(':root {'), css.indexOf('.dark {'));
    const dark = css.slice(
        css.indexOf('.dark {'),
        css.indexOf('@theme inline'),
    );

    assert.match(
        root,
        /--oncam-component-button-destructive-default-background: var\(--oncam-semantic-color-action-destructive-background\);/,
    );
    assert.match(
        root,
        /--oncam-semantic-color-action-destructive-background: var\(--oncam-primitive-color-error-700\);/,
    );
    assert.match(
        dark,
        /--oncam-component-button-destructive-default-background: var\(--oncam-semantic-color-action-destructive-background\);/,
    );
    assert.match(
        dark,
        /--oncam-semantic-color-action-destructive-background: var\(--oncam-primitive-color-error-900\);/,
    );
});

test('serializes every accepted primitive value type safely', async () => {
    const { css } = transformOncamTokens(await canonicalTokens());

    assert.match(
        css,
        /--oncam-primitive-font-family-sans: "Instrument Sans", "ui-sans-serif", "system-ui", "sans-serif";/,
    );
    assert.match(css, /--oncam-primitive-font-weight-semibold: 600;/);
    assert.match(css, /--oncam-primitive-font-line-height-normal: 1\.5;/);
    assert.match(
        css,
        /--oncam-primitive-shadow-md: 0 4px 12px rgb\(14 23 19 \/ 0\.12\);/,
    );
    assert.match(css, /--oncam-primitive-motion-duration-fast: 120ms;/);
    assert.match(
        css,
        /--oncam-primitive-motion-easing-standard: cubic-bezier\(0\.2, 0, 0, 1\);/,
    );
});

test('emits only ONCAM-namespaced Tailwind v4 compatibility aliases', async () => {
    const { css } = transformOncamTokens(await canonicalTokens());
    const theme = css.slice(css.indexOf('@theme inline'));
    const aliases = [...theme.matchAll(/^\s+(--[^:]+):/gm)].map(
        ([, name]) => name,
    );

    assert.match(theme, /^@theme inline static \{/);
    assert.ok(aliases.length > 0);
    assert.equal(
        aliases.every((name) => name.includes('-oncam-')),
        true,
    );
    assert.equal(new Set(aliases).size, aliases.length);
    assert.equal(
        aliases.some((name) => name === '--color-primary'),
        false,
    );
    assert.equal(
        aliases.some((name) => name === '--radius-lg'),
        false,
    );
});

test('fails closed with actionable paths for collisions, dangling aliases, cycles, parity, and unsupported values', async (t) => {
    const canonical = await canonicalTokens();

    await t.test('normalized-name collisions', () => {
        const tokens = clone(canonical);
        tokens.primitive.collision = {
            $type: 'dimension',
            fooBar: { $value: '1px' },
            'foo-bar': { $value: '2px' },
        };
        assert.throws(
            () => transformOncamTokens(tokens),
            /primitive\.collision\.(fooBar|foo-bar).*collision/i,
        );
    });

    await t.test('dangling aliases', () => {
        const tokens = clone(canonical);
        tokens.semantic.light.color.text.primary.$value =
            '{primitive.color.missing.value}';
        assert.throws(
            () => transformOncamTokens(tokens),
            /semantic\.light\.color\.text\.primary.*primitive\.color\.missing\.value/i,
        );
    });

    await t.test('cycles', () => {
        const tokens = clone(canonical);
        tokens.semantic.shared.cycle = {
            a: { $value: '{semantic.shared.cycle.b}' },
            b: { $value: '{semantic.shared.cycle.a}' },
        };
        assert.throws(
            () => transformOncamTokens(tokens),
            /cycle.*semantic\.shared\.cycle\.[ab]/i,
        );
    });

    await t.test('mode parity errors', () => {
        const tokens = clone(canonical);
        delete tokens.component.dark.button.destructive.activeBackground;
        assert.throws(
            () => transformOncamTokens(tokens),
            /component\.dark\.button\.destructive\.activeBackground.*parity/i,
        );
    });

    await t.test('mode parity type errors', () => {
        const tokens = clone(canonical);
        tokens.semantic.dark.color.text.primary.$value =
            '{primitive.font.size.14}';
        assert.throws(
            () => transformOncamTokens(tokens),
            /semantic\.dark\.color\.text\.primary.*parity type/i,
        );
    });

    await t.test('unsupported primitive values', () => {
        const tokens = clone(canonical);
        tokens.primitive.color.brand.green.$value = 'red; body { color: red }';
        assert.throws(
            () => transformOncamTokens(tokens),
            /primitive\.color\.brand\.green.*color/i,
        );
    });

    await t.test('unsafe shadow values', () => {
        const tokens = clone(canonical);
        tokens.primitive.shadow.sm.$value = 'url(https://example.test/a.png)';
        assert.throws(
            () => transformOncamTokens(tokens),
            /primitive\.shadow\.sm.*shadow/i,
        );
    });

    await t.test('malformed and out-of-range colors', () => {
        for (const value of [
            '#12345',
            'rgb(not-a-color)',
            'rgb(256 0 0)',
            'rgb(0 0)',
            'rgb(0 0 0 / 101%)',
            'hsl(0 50 50%)',
            'oklch(120% 0.2 30)',
            'rgb(0 0 0); @import "https://example.test/x.css"',
        ]) {
            const tokens = clone(canonical);
            tokens.primitive.color.brand.green.$value = value;
            assert.throws(
                () => transformOncamTokens(tokens),
                /primitive\.color\.brand\.green.*color/i,
                value,
            );
        }
    });

    await t.test('out-of-range or malformed cubic bezier values', () => {
        for (const value of [
            [-0.01, 0, 0, 1],
            [0.2, 0, 1.01, 1],
            [0.2, 0, 1],
            ['0.2', 0, 0, 1],
        ]) {
            const tokens = clone(canonical);
            tokens.primitive.motion.easing.standard.$value = value;
            assert.throws(
                () => transformOncamTokens(tokens),
                /primitive\.motion\.easing\.standard.*cubicBezier/i,
                JSON.stringify(value),
            );
        }
    });

    await t.test('out-of-range or malformed font weights', () => {
        for (const value of [0, 1001, 450.5, '600']) {
            const tokens = clone(canonical);
            tokens.primitive.font.weight.semibold.$value = value;
            assert.throws(
                () => transformOncamTokens(tokens),
                /primitive\.font\.weight\.semibold.*fontWeight/i,
                String(value),
            );
        }
    });
});

test('accepts strictly valid supported functional colors', async () => {
    const canonical = await canonicalTokens();
    const cases = [
        'rgb(0 95 65)',
        'rgb(0 95 65 / 80%)',
        'rgba(0, 95, 65, 0.8)',
        'hsl(161deg 100% 19%)',
        'hsla(161, 100%, 19%, 0.8)',
        'oklch(45% 0.1 160 / 0.8)',
        'oklab(45% -0.1 0.05)',
        'lab(45% -10 5)',
        'lch(45% 20 160)',
    ];

    for (const value of cases) {
        const tokens = clone(canonical);
        tokens.primitive.color.brand.green.$value = value;
        const result = transformOncamTokens(tokens);
        assert.match(
            result.css,
            new RegExp(
                `--oncam-primitive-color-brand-green: ${value.replace(
                    /[.*+?^${}()|[\]\\]/g,
                    '\\$&',
                )};`,
            ),
            value,
        );
        assert.deepEqual(result.census, {
            total: 538,
            primitive: 102,
            semantic: 188,
            component: 248,
        });
    }
});

test('accepts exact supported hex, cubic bezier, and font-weight boundaries', async () => {
    const canonical = await canonicalTokens();

    for (const color of ['#abc', '#abcd', '#aabbcc', '#aabbccdd']) {
        const tokens = clone(canonical);
        tokens.primitive.color.brand.green.$value = color;
        assert.match(
            transformOncamTokens(tokens).css,
            new RegExp(`--oncam-primitive-color-brand-green: ${color};`),
        );
    }

    for (const weight of [1, 1000]) {
        const tokens = clone(canonical);
        tokens.primitive.font.weight.semibold.$value = weight;
        assert.match(
            transformOncamTokens(tokens).css,
            new RegExp(`--oncam-primitive-font-weight-semibold: ${weight};`),
        );
    }

    for (const easing of [
        [0, -2, 1, 3],
        [1, 0, 0, 1],
    ]) {
        const tokens = clone(canonical);
        tokens.primitive.motion.easing.standard.$value = easing;
        assert.match(
            transformOncamTokens(tokens).css,
            new RegExp(
                `--oncam-primitive-motion-easing-standard: cubic-bezier\\(${easing.join(
                    ', ',
                )}\\);`,
            ),
        );
    }
});

test('rejects raw semantic values and cross-mode aliases', async () => {
    const canonical = await canonicalTokens();
    const raw = clone(canonical);
    raw.semantic.light.color.text.primary.$value = '#000000';
    assert.throws(
        () => transformOncamTokens(raw),
        /semantic\.light\.color\.text\.primary.*alias/i,
    );

    const mixed = clone(canonical);
    mixed.component.dark.button.primary.defaultBackground.$value =
        '{semantic.light.color.action.primary.background}';
    assert.throws(
        () => transformOncamTokens(mixed),
        /component\.dark\.button\.primary\.defaultBackground.*semantic\.light/i,
    );
});

async function withTempDir(run) {
    const dir = await mkdtemp(join(tmpdir(), 'oncam-runtime-bridge-'));

    try {
        return await run(dir);
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
}

test('Vite plugin writes the runtime CSS to disk and watches the canonical JSON', async () => {
    await withTempDir(async (dir) => {
        const outputFile = join(dir, GENERATED_CSS_FILENAME);
        const plugin = oncamTokenRuntimeBridge({ outputFile });
        const watched = [];

        await plugin.buildStart.call({
            addWatchFile: (file) => watched.push(file),
        });

        const css = await readFile(outputFile, 'utf8');

        assert.equal(plugin.name, 'oncam-token-runtime-bridge');
        assert.equal(plugin.enforce, 'pre');
        assert.match(css, /^:root \{/);
        assert.equal(watched.length, 1);
        assert.match(
            watched[0].replaceAll('\\', '/'),
            /resources\/design-tokens\/oncam\.tokens\.json$/,
        );
    });
});

test('Vite plugin overwrites a stale file unconditionally on every start', async () => {
    await withTempDir(async (dir) => {
        const outputFile = join(dir, GENERATED_CSS_FILENAME);
        await writeFile(outputFile, '/* stale CSS from a previous run */');

        const plugin = oncamTokenRuntimeBridge({ outputFile });
        await plugin.buildStart.call({ addWatchFile: () => {} });

        const css = await readFile(outputFile, 'utf8');

        assert.doesNotMatch(css, /stale CSS from a previous run/);
        assert.match(css, /^:root \{/);
    });
});

test('Vite plugin fails closed when the token source is invalid, without touching the output file', async () => {
    await withTempDir(async (dir) => {
        const badTokenFile = join(dir, 'oncam.tokens.json');
        const outputFile = join(dir, GENERATED_CSS_FILENAME);
        await writeFile(badTokenFile, '{ not valid json');

        const plugin = oncamTokenRuntimeBridge({
            tokenFile: badTokenFile,
            outputFile,
        });

        await assert.rejects(
            plugin.buildStart.call({ addWatchFile: () => {} }),
        );
        await assert.rejects(readFile(outputFile, 'utf8'));
    });
});

test('Vite regenerates the file and reloads when the canonical JSON changes', async () => {
    await withTempDir(async (dir) => {
        const outputFile = join(dir, GENERATED_CSS_FILENAME);
        const plugin = oncamTokenRuntimeBridge({ outputFile });
        const messages = [];

        const result = await plugin.handleHotUpdate({
            file: decodeURIComponent(tokenFile.pathname).replace(
                /^\/(?:[A-Z]:)/,
                (path) => path.slice(1),
            ),
            server: { ws: { send: (message) => messages.push(message) } },
        });

        const css = await readFile(outputFile, 'utf8');

        assert.deepEqual(result, []);
        assert.match(css, /^:root \{/);
        assert.deepEqual(messages, [{ type: 'full-reload' }]);
    });
});

test('registers the generated CSS import exactly once in the required Tailwind/Vite order', async () => {
    const [appCss, viteConfig] = await Promise.all([
        readFile(appCssFile, 'utf8'),
        readFile(viteConfigFile, 'utf8'),
    ]);
    const bridgeImport = `@import './${GENERATED_CSS_FILENAME}';`;

    assert.equal(appCss.split(bridgeImport).length - 1, 1);
    assert.ok(
        appCss.indexOf("@import 'tw-animate-css';") <
            appCss.indexOf(bridgeImport),
    );
    assert.ok(appCss.indexOf(bridgeImport) < appCss.indexOf('@source'));
    assert.equal(viteConfig.split('oncamTokenRuntimeBridge()').length - 1, 1);
    assert.ok(
        viteConfig.indexOf('oncamTokenRuntimeBridge()') <
            viteConfig.indexOf('tailwindcss()'),
    );
});

test('resolves the generated token CSS through a nested @import, not just a top-level entry', async () => {
    // Regression test for the frontend fixture harness bug (2026-09-21):
    // @tailwindcss/vite resolves `@import` internally via its own
    // filesystem-based resolver, which never runs Vite's resolveId/load/
    // transform plugin hooks for files reached through a *nested* @import
    // (only the literal top-level entry id Vite dispatches a transform for
    // gets that treatment). A fixture whose CSS entry imports app.css
    // (rather than being app.css itself) reproduces that nesting exactly.
    // This must fail with a virtual-module-based bridge and pass with a
    // real-file-based one.
    await withTempDir(async (dir) => {
        const outputFile = join(dir, GENERATED_CSS_FILENAME);
        await writeFile(
            join(dir, 'app.css'),
            `@import './${GENERATED_CSS_FILENAME}';\n`,
        );
        await writeFile(join(dir, 'entry.css'), "@import './app.css';\n");

        const result = await build({
            root: dir,
            configFile: false,
            logLevel: 'silent',
            plugins: [oncamTokenRuntimeBridge({ outputFile }), tailwindcss()],
            build: {
                write: false,
                cssMinify: false,
                rollupOptions: { input: join(dir, 'entry.css') },
            },
        });

        const [{ output }] = [].concat(result);
        const cssAsset = output.find((chunk) =>
            chunk.fileName.endsWith('.css'),
        );

        assert.ok(cssAsset, 'expected Tailwind to emit a CSS asset');
        assert.match(cssAsset.source, /--oncam-/);
    });
});

test('generated runtime CSS contains definitions only', async () => {
    const { css } = transformOncamTokens(await canonicalTokens());
    const withoutBlocks = css
        .replace(/:root \{[\s\S]*?\n\}/, '')
        .replace(/\.dark \{[\s\S]*?\n\}/, '')
        .replace(/@theme inline static \{[\s\S]*?\n\}/, '')
        .trim();

    assert.equal(withoutBlocks, '');
    assert.doesNotMatch(
        css,
        /(^|\n)\s*(body|html|\*|@layer|@media|@custom-variant)\b/,
    );
    assert.equal(
        css
            .split('\n')
            .filter((line) => line.trim().endsWith(';'))
            .every((line) => line.trim().startsWith('--')),
        true,
    );
});
