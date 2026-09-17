import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
import {
    existsSync,
    mkdirSync,
    mkdtempSync,
    realpathSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const directory = process.env.ONCAM_COLLECTIVE_PREVIEW_DIRECTORY;

if (
    !directory ||
    realpathSync(dirname(directory)) !== realpathSync(tmpdir()) ||
    !/^oncam-collective-[a-f0-9]{32}$/.test(basename(directory)) ||
    !existsSync(join(directory, 'manifest.json')) ||
    existsSync(join(directory, '.env'))
) {
    throw new Error(
        'Use the initialized disposable collective fixture directory.',
    );
}

const artifacts = join(root, 'output/playwright', basename(directory));
mkdirSync(artifacts, { recursive: true });

if (process.argv[2] === 'assets') {
    const { build } = await import('vite');
    const entry = join(artifacts, 'fixture.css');
    writeFileSync(
        entry,
        `@import '../../../vendor/filament/filament/resources/css/theme.css';
@source '../../../tests/Support/views/collective-bill-preview.blade.php';
@source '../../../vendor/filament/support/resources/views/components/button';
`,
    );
    const built = await build(
        await fixtureAssetConfig(root, entry, join(artifacts, 'css-build')),
    );
    const css = (Array.isArray(built) ? built : [built])
        .flatMap((result) => result.output)
        .filter(
            (asset) =>
                asset.type === 'asset' && asset.fileName.endsWith('.css'),
        );

    if (css.length !== 1) {
        throw new Error('Expected exactly one local fixture stylesheet.');
    }

    writeFileSync(join(directory, 'fixture.css'), css[0].source);
    console.log(`Local fixture CSS built: ${directory}`);
} else if (process.argv[2] === 'probe-assets') {
    const { build, resolveConfig } = await import('vite');
    const probeRoot = mkdtempSync(join(directory, 'vite-isolation-'));
    const envFiles = [
        '.env',
        '.env.local',
        '.env.production',
        '.env.production.local',
    ];
    const markers = envFiles.map(
        (_, index) => `VITE_ONCAM_ISOLATION_PROBE_${index}`,
    );

    for (const [index, file] of envFiles.entries()) {
        assert.equal(
            process.env[markers[index]],
            undefined,
            'Probe marker must not already exist',
        );
        writeFileSync(
            join(probeRoot, file),
            `${markers[index]}=synthetic-only\n`,
        );
    }

    for (const file of ['vite.config.cjs', 'postcss.config.cjs']) {
        writeFileSync(
            join(probeRoot, file),
            'throw new Error("Synthetic project config must never execute");\n',
        );
    }

    const control = await resolveConfig(
        { root: probeRoot, configFile: false },
        'build',
        'production',
    );
    assert.ok(
        markers.every((key) => control.env[key] === 'synthetic-only'),
        'Control must detect all synthetic env files',
    );
    const entry = join(probeRoot, 'input.css');
    writeFileSync(entry, '.synthetic-probe { color: #123456; }\n');
    const config = await fixtureAssetConfig(
        probeRoot,
        entry,
        join(probeRoot, 'output'),
    );
    let observed = false;
    config.plugins.push({
        name: 'oncam-isolation-probe',
        configResolved(resolved) {
            assert.ok(
                markers.every((key) => !(key in resolved.env)),
                'Fixture build loaded synthetic workspace env',
            );
            assert.equal(resolved.envDir, false);
            assert.equal(resolved.configFile, undefined);
            assert.ok(
                !resolved.plugins.some((plugin) =>
                    plugin.name.includes('laravel'),
                ),
            );
            observed = true;
        },
    });
    await build(config);
    assert.ok(observed, 'Probe must observe the actual build configuration');
    const report = {
        passed: true,
        syntheticEnvFiles: envFiles.length,
        projectConfigTraps: 2,
        envDir: false,
        configFile: false,
    };
    writeFileSync(
        join(artifacts, 'asset-isolation-probe.json'),
        JSON.stringify(report, null, 2),
    );
    console.log(JSON.stringify(report));
} else if (process.argv[2] === 'verify') {
    const cli = process.env.ONCAM_PLAYWRIGHT_CLI;

    if (!cli || !existsSync(cli)) {
        throw new Error(
            'ONCAM_PLAYWRIGHT_CLI must point to an existing Playwright CLI; no install is performed.',
        );
    }

    const session = `oncam-collective-${basename(directory).slice(-8)}`;
    const run = (...args) => {
        const result = spawnSync(
            process.execPath,
            [cli, `-s=${session}`, ...args],
            {
                cwd: artifacts,
                encoding: 'utf8',
                timeout: 180000,
                maxBuffer: 8 * 1024 * 1024,
            },
        );
        const output = `${result.stdout ?? ''}${result.stderr ?? ''}`;
        writeFileSync(join(artifacts, `${args[0]}-${Date.now()}.txt`), output);

        if (
            result.error ||
            result.status !== 0 ||
            output.includes('### Error')
        ) {
            throw new Error(`${args[0]} failed: ${output}`, {
                cause: result.error,
            });
        }

        return output;
    };

    try {
        run('open', 'about:blank', '--browser', 'chrome');
        run('run-code', prepareBrowser.toString());
        run('snapshot');
        const output = run('run-code', verifyNative.toString());
        const result = output.match(
            /### Result\s*\n([\s\S]*?)\n### Ran Playwright code/,
        );

        if (!result) {
            throw new Error('Browser report missing from CLI output.');
        }

        const report = JSON.parse(result[1]);
        writeFileSync(
            join(artifacts, 'report.json'),
            JSON.stringify(report, null, 2),
        );
        console.log(
            JSON.stringify({
                checks: report.checks,
                geometry: report.geometry,
                nativeActions: report.native.length,
                trustedEvents: report.events.length,
                networkResponses: report.network.requests.length,
                errors: report.network.errors,
            }),
        );
        console.log(run('console', 'warning'));
        run('requests');
    } finally {
        run('close');
    }
} else {
    throw new Error(
        'Use assets, probe-assets or verify. The PHP server must be started separately on 127.0.0.1:8012.',
    );
}

async function fixtureAssetConfig(assetRoot, entry, outDir) {
    const { default: tailwindcss } = await import('@tailwindcss/vite');

    return {
        root: assetRoot,
        // Vite config, env files and PostCSS discovery are independent switches.
        configFile: false,
        envDir: false,
        css: { postcss: { plugins: [] } },
        plugins: [tailwindcss()],
        build: {
            outDir,
            emptyOutDir: false,
            rolldownOptions: { input: entry },
            cssCodeSplit: true,
        },
    };
}

async function prepareBrowser(page) {
    page.collectiveEvidence = {
        errors: [],
        requests: [],
        failures: [],
        blocked: [],
    };
    page.on('pageerror', (error) =>
        page.collectiveEvidence.errors.push(error.message),
    );
    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) {
            page.collectiveEvidence.errors.push(message.text());
        }
    });
    page.on('response', (response) =>
        page.collectiveEvidence.requests.push({
            url: response.url(),
            status: response.status(),
        }),
    );
    page.on('requestfailed', (request) =>
        page.collectiveEvidence.failures.push(request.url()),
    );
    await page.context().route('**/*', (route) => {
        if (route.request().url().startsWith('http://127.0.0.1:8012/')) {
            return route.continue();
        }

        page.collectiveEvidence.blocked.push(route.request().url());

        return route.abort();
    });
    await page.addInitScript(() => {
        window.collectiveNativeEvents = [];

        for (const type of ['keydown', 'keyup', 'click', 'change']) {
            document.addEventListener(
                type,
                (event) => {
                    window.collectiveNativeEvents.push({
                        type,
                        key: event.key ?? null,
                        target: event.target.id || event.target.tagName,
                        trusted: event.isTrusted,
                    });
                },
                true,
            );
        }
    });
    await page.goto('http://127.0.0.1:8012/preview');
    await page.waitForFunction(() => Boolean(window.Livewire));

    return { ready: true, url: page.url() };
}

async function verifyNative(page) {
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message);
        }
    };
    const report = { native: [], geometry: [], checks: [] };
    const active = () =>
        page.evaluate(() => ({
            id: document.activeElement.id,
            button: document.activeElement.tagName === 'BUTTON',
            visible: document.activeElement.matches(':focus-visible'),
        }));
    const tabTo = async (target) => {
        for (let i = 0; i < 60; i++) {
            const focus = await active();

            if (target === 'review' ? focus.button : focus.id === target) {
                assert(focus.visible, `No focus-visible at ${target}`);

                return;
            }

            await page.keyboard.press('Tab');
        }

        throw new Error(`Native Tab could not reach ${target}`);
    };
    const requestKey = async (key, target) => {
        await tabTo(target);
        const responsePromise = page.waitForResponse(
            (response) => response.url().endsWith('/fixture-update'),
            { timeout: 30000 },
        );
        await page.keyboard.press(key);
        const response = await responsePromise;
        assert(
            response.status() === 200,
            `Livewire returned ${response.status()}`,
        );
        await response.finished();
        await page.waitForFunction(
            () => !document.querySelector('fieldset').hasAttribute('aria-busy'),
        );
        const focus = await active();
        assert(
            target === 'review' ? focus.button : focus.id === target,
            `Focus lost after ${key} at ${target}`,
        );
        assert(focus.visible, `Focus no longer visible after ${key}`);
        report.native.push({ key, target, focus });
    };
    const body = () => page.locator('main').innerText();
    const hasResult = async () =>
        (await body()).includes('Hasil tinjauan sementara');
    const measure = async (state) => {
        for (const width of [320, 390, 1280]) {
            await page.setViewportSize({ width, height: 800 });
            const geometry = await page.evaluate(() => {
                const clipped = [];

                for (const element of document.querySelectorAll('main *')) {
                    const rect = element.getBoundingClientRect();

                    if (!rect.width || !rect.height) {
                        continue;
                    }

                    if (
                        rect.left < -1 ||
                        rect.right > innerWidth + 1 ||
                        (element.clientWidth > 0 &&
                            element.scrollWidth > element.clientWidth + 1)
                    ) {
                        clipped.push({
                            tag: element.tagName,
                            id: element.id,
                            className: element.className,
                            width: rect.width,
                            scroll: element.scrollWidth,
                            client: element.clientWidth,
                        });
                    }
                }

                return {
                    viewport: innerWidth,
                    document: document.documentElement.scrollWidth,
                    clipped,
                    checkboxMinimum: Math.min(
                        ...Array.from(
                            document.querySelectorAll('input[type="checkbox"]'),
                            (input) => {
                                const rect = input.getBoundingClientRect();

                                return Math.min(rect.width, rect.height);
                            },
                        ),
                    ),
                };
            });
            report.geometry.push({ state, ...geometry });
            await page.screenshot({
                path: `${state}-${width}.png`,
                fullPage: true,
            });
            assert(
                geometry.document <= width + 1 &&
                    geometry.clipped.length === 0 &&
                    geometry.checkboxMinimum >= 15.5,
                `Reflow failed ${state}/${width}: ${JSON.stringify(geometry)}`,
            );
        }
    };
    await measure('empty');
    assert(
        (await page.locator('input[id^="attempt-"]').count()) === 11,
        'Expected 10 mixed + one invalid fixture',
    );

    for (let id = 1; id <= 10; id++) {
        await requestKey('Space', `attempt-${id}`);

        if ([2, 4, 6, 10].includes(id)) {
            await requestKey('Space', `consultation-${id}`);
        }
    }

    await requestKey('Enter', 'review');
    const mixed = await body();
    assert(
        mixed.includes('Total: IDR 1.140') &&
            mixed.includes('Berbiaya: 8 · Gratis: 2'),
        'Mixed server result mismatch',
    );
    assert(
        (await page.locator('section li').count()) === 10,
        'Expected 10 projected rows',
    );
    report.checks.push('10 mixed: IDR 1140, paid 8/free 2, 10 result rows');
    await measure('mixed');
    await requestKey('Space', 'consultation-2');
    assert(!(await hasResult()), 'Consultation change retained stale preview');
    await requestKey('Enter', 'review');
    assert(
        (await body()).includes('Total: IDR 1.110'),
        'Consultation change was not repriced on server',
    );
    await requestKey('Space', 'attempt-11');
    assert(!(await hasResult()), 'Selection change retained stale preview');
    await requestKey('Enter', 'review');
    const invalid = await page.locator('section').innerText();
    assert(
        invalid.includes('Total belum tersedia') &&
            invalid.includes('PAYER_POLICY_UNCONFIGURED') &&
            !invalid.includes('Total: IDR'),
        'Invalid selection retained old total',
    );
    await measure('invalid');
    const events = await page.evaluate(() => window.collectiveNativeEvents);
    assert(
        events
            .filter((event) => event.type === 'keydown')
            .every((event) => event.trusted),
        'Untrusted keyboard evidence',
    );
    assert(
        events.some((event) => event.type === 'change' && event.trusted),
        'No trusted native checkbox changes',
    );
    report.events = events;
    await page.reload();
    await page.waitForFunction(() => Boolean(window.Livewire));
    assert(
        !(await hasResult()) &&
            (await page.locator('input:checked').count()) === 0,
        'Reload resumed stale state',
    );
    await requestKey('Enter', 'review');
    assert(
        !(await hasResult()) && (await body()).includes('Pilihan tidak valid.'),
        'Empty review retained old result',
    );
    await measure('empty-error');
    report.checks.push(
        'consultation change clears; selection change clears; invalid has null total; reload/empty do not resume',
    );
    assert(
        page.collectiveEvidence.errors.length === 0,
        `Console errors: ${JSON.stringify(page.collectiveEvidence.errors)}`,
    );
    assert(
        page.collectiveEvidence.blocked.length === 0,
        'Page attempted an external request',
    );
    assert(
        page.collectiveEvidence.failures.length === 0,
        'Network requests failed',
    );
    assert(
        page.collectiveEvidence.requests.every(
            (response) => response.status < 400,
        ),
        'HTTP error in browser flow',
    );

    return { ...report, network: page.collectiveEvidence };
}
