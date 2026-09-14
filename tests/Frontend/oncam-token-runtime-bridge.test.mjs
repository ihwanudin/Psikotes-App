import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
    VIRTUAL_ONCAM_CSS_ID,
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

test('Vite plugin serves virtual CSS and watches the canonical JSON', async () => {
    const plugin = oncamTokenRuntimeBridge();
    const watched = [];
    const resolved = plugin.resolveId(VIRTUAL_ONCAM_CSS_ID);
    const css = await plugin.load.call(
        { addWatchFile: (file) => watched.push(file) },
        resolved,
    );

    assert.equal(plugin.name, 'oncam-token-runtime-bridge');
    assert.equal(plugin.enforce, 'pre');
    assert.equal(resolved, `\0${VIRTUAL_ONCAM_CSS_ID}`);
    assert.match(css, /^:root \{/);
    assert.equal(watched.length, 1);
    assert.match(
        watched[0].replaceAll('\\', '/'),
        /resources\/design-tokens\/oncam\.tokens\.json$/,
    );
});

test('Vite pre-transform expands the CSS import before Tailwind resolves it', async () => {
    const plugin = oncamTokenRuntimeBridge();
    const watched = [];
    const source = [
        "@import 'tailwindcss';",
        "@import 'virtual:oncam-design-tokens.css';",
        "@source '../views';",
    ].join('\n');
    const result = await plugin.transform.call(
        { addWatchFile: (file) => watched.push(file) },
        source,
        'resources/css/app.css',
    );

    assert.doesNotMatch(result.code, /virtual:oncam-design-tokens\.css/);
    assert.match(result.code, /:root \{/);
    assert.match(result.code, /@theme inline static \{/);
    assert.match(result.code, /@source '\.\.\/views';/);
    assert.equal(watched.length, 1);
});

test('Vite invalidates and reloads the virtual CSS when canonical JSON changes', () => {
    const plugin = oncamTokenRuntimeBridge();
    const resolved = plugin.resolveId(VIRTUAL_ONCAM_CSS_ID);
    const module = { id: resolved };
    const invalidated = [];
    const messages = [];
    const watched = plugin.handleHotUpdate({
        file: decodeURIComponent(tokenFile.pathname).replace(
            /^\/(?:[A-Z]:)/,
            (path) => path.slice(1),
        ),
        server: {
            moduleGraph: {
                getModuleById: (id) => (id === resolved ? module : undefined),
                invalidateModule: (value) => invalidated.push(value),
            },
            ws: { send: (message) => messages.push(message) },
        },
    });

    assert.deepEqual(watched, [module]);
    assert.deepEqual(invalidated, [module]);
    assert.deepEqual(messages, [{ type: 'full-reload' }]);
});

test('registers the virtual CSS exactly once in the required Tailwind/Vite order', async () => {
    const [appCss, viteConfig] = await Promise.all([
        readFile(appCssFile, 'utf8'),
        readFile(viteConfigFile, 'utf8'),
    ]);
    const bridgeImport = "@import 'virtual:oncam-design-tokens.css';";

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
