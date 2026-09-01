import { spawnSync } from 'node:child_process';
import {
    existsSync,
    mkdirSync,
    readFileSync,
    realpathSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const directory = process.env.ONCAM_COLLECTIVE_PAGE_DIRECTORY;

if (
    !directory ||
    realpathSync(dirname(directory)) !== realpathSync(tmpdir()) ||
    !/^oncam-collective-page-[a-f0-9]{32}$/.test(basename(directory)) ||
    !existsSync(join(directory, 'manifest.json')) ||
    existsSync(join(directory, '.env'))
) {
    throw new Error('Use the initialized disposable collective page fixture.');
}

const artifacts = join(root, 'output/playwright', basename(directory));
mkdirSync(artifacts, { recursive: true });

if (process.argv[2] === 'assets') {
    const { build } = await import('vite');
    const { default: tailwindcss } = await import('@tailwindcss/vite');
    const entry = join(artifacts, 'fixture.css');
    writeFileSync(
        entry,
        `@import '../../../vendor/filament/filament/resources/css/theme.css';\n@source '../../../resources/views/filament/resources/assessment-participants/pages/create-collective-bill.blade.php';\n@source '../../../vendor/filament/support/resources/views/components/button';\n`,
    );
    const built = await build({
        root,
        configFile: false,
        envDir: false,
        css: { postcss: { plugins: [] } },
        plugins: [tailwindcss()],
        build: {
            outDir: join(artifacts, 'css-build'),
            emptyOutDir: false,
            rolldownOptions: { input: entry },
            cssCodeSplit: true,
        },
    });
    const outputs = (Array.isArray(built) ? built : [built])
        .flatMap((value) => value.output)
        .filter(
            (asset) =>
                asset.type === 'asset' && asset.fileName.endsWith('.css'),
        );

    if (outputs.length !== 1) {
        throw new Error('Expected one fixture stylesheet.');
    }

    writeFileSync(join(directory, 'fixture.css'), outputs[0].source);
} else if (process.argv[2] === 'verify') {
    const cli = process.env.ONCAM_PLAYWRIGHT_CLI;

    if (!cli || !existsSync(cli)) {
        throw new Error('Existing Playwright CLI required.');
    }

    const manifest = JSON.parse(
        readFileSync(join(directory, 'manifest.json'), 'utf8'),
    );
    const session = `oncam-p12b-${basename(directory).slice(-8)}`;
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
            throw new Error(output, { cause: result.error });
        }

        return output;
    };

    try {
        run('open', 'about:blank', '--browser', 'chrome');
        run(
            'run-code',
            `async (page) => (${prepare.toString()})(page, ${JSON.stringify(manifest)})`,
        );
        const phases = {};

        for (const phase of ['selection-preview', 'confirm', 'authorization']) {
            const output = run(
                'run-code',
                `async (page) => (${verifyPhase.toString()})(page, ${JSON.stringify(phase)})`,
            );
            writeFileSync(join(artifacts, `phase-${phase}.txt`), output);
            const match = output.match(
                /### Result\s*\n([\s\S]*?)\n### Ran Playwright code/,
            );

            if (!match) {
                throw new Error(`Missing browser result for ${phase}.`);
            }

            phases[phase] = JSON.parse(match[1]);
        }

        const report = { phases };
        writeFileSync(
            join(artifacts, 'p12b-report.json'),
            JSON.stringify(report, null, 2),
        );
        console.log(JSON.stringify(report));
        run('console', 'warning');
        run('requests');
    } finally {
        run('close');
    }
} else {
    throw new Error('Use assets or verify.');
}

async function prepare(page, manifest) {
    page.p12b = {
        control: manifest.control,
        foreignBill: manifest.foreignBill,
        errors: [],
        responses: [],
        blocked: [],
        failures: [],
        native: [],
        geometry: [],
        trustedEvents: [],
    };
    page.on(
        'console',
        (message) =>
            ['error', 'warning'].includes(message.type()) &&
            page.p12b.errors.push(message.text()),
    );
    page.on('pageerror', (error) => page.p12b.errors.push(error.message));
    page.on('response', (response) =>
        page.p12b.responses.push({
            url: response.url(),
            status: response.status(),
        }),
    );
    page.on('requestfailed', (request) =>
        page.p12b.failures.push(request.url()),
    );
    await page.context().route('**/*', (route) => {
        if (route.request().url().startsWith('http://127.0.0.1:8012/')) {
            return route.continue();
        }

        page.p12b.blocked.push(route.request().url());

        return route.abort();
    });
    await page.addInitScript(() => {
        window.p12bEvents = [];

        for (const type of ['keydown', 'keyup', 'click', 'change']) {
            document.addEventListener(
                type,
                (event) =>
                    window.p12bEvents.push({
                        type,
                        key: event.key ?? null,
                        trusted: event.isTrusted,
                    }),
                true,
            );
        }
    });
    await page.goto('http://127.0.0.1:8012/preview');
    await page.waitForFunction(() => Boolean(window.Livewire));
    page.p12b.bootstrapDiagnostics = {
        errors: page.p12b.errors.slice(),
        failures: page.p12b.failures.slice(),
    };
    page.p12b.errors = [];
    page.p12b.failures = [];

    return {
        ready: true,
        bootstrapDiagnostics: page.p12b.bootstrapDiagnostics,
    };
}

async function verifyPhase(page, phase) {
    const ok = (condition, message) => {
        if (!condition) {
            throw new Error(message);
        }
    };
    const tabTo = async (locator) => {
        for (let i = 0; i < 80; i++) {
            if (
                await locator.evaluate(
                    (element) => element === document.activeElement,
                )
            ) {
                ok(
                    await locator.evaluate((element) =>
                        element.matches(':focus-visible'),
                    ),
                    'Focus not visible',
                );

                return;
            }

            await page.keyboard.press('Tab');
        }

        throw new Error('Native Tab did not reach target');
    };
    const pressRequest = async (locator, key) => {
        await tabTo(locator);
        const response = page.waitForResponse((item) =>
            item.url().endsWith('/fixture-update'),
        );
        await page.keyboard.press(key);
        ok((await response).status() === 200, 'Livewire request failed');
        page.p12b.native.push(key);
    };
    const control = async (action) => {
        const response = await page.request.post(
            'http://127.0.0.1:8012/fixture-control',
            {
                headers: { 'X-Oncam-Fixture': page.p12b.control },
                form: { action },
            },
        );
        ok(response.status() === 200, `Control ${action} failed`);
    };
    const reviewSelection = async (button) => {
        await pressRequest(button, 'Enter');
        await page
            .getByRole('heading', { name: 'Konfirmasi tinjauan' })
            .waitFor({ state: 'visible' });
    };
    const measure = async (state) => {
        for (const width of [320, 390, 1280]) {
            await page.setViewportSize({ width, height: 800 });
            const result = await page.evaluate(() => ({
                width: innerWidth,
                scroll: document.documentElement.scrollWidth,
                clipped: Array.from(document.querySelectorAll('main *')).filter(
                    (element) => {
                        const rect = element.getBoundingClientRect();

                        return (
                            rect.width > 0 &&
                            (rect.left < -1 || rect.right > innerWidth + 1)
                        );
                    },
                ).length,
            }));
            ok(
                result.scroll <= width + 1 && result.clipped === 0,
                `Overflow ${state}/${width}`,
            );
            page.p12b.geometry.push({ state, ...result });
            await page.screenshot({
                path: `${state}-${width}.png`,
                fullPage: true,
            });
        }
    };
    const body = () => page.locator('main').innerText();

    if (phase === 'selection-preview') {
        await measure('selection');
        const disabled = page.locator('input[type=checkbox]:disabled');
        ok(
            (await disabled.count()) === 4,
            'Expected legacy/free/self/claimed disabled rows',
        );
        ok(
            (
                (await body()).match(
                    /Tidak tersedia untuk tagihan kolektif\./g,
                ) ?? []
            ).length === 4,
            'Unsafe disabled reason',
        );

        for (let i = 0; i < 10; i++) {
            const attempt = page.locator(`#attempt-${i + 1}`);
            await pressRequest(attempt, 'Space');

            if ([1, 3, 5, 9].includes(i)) {
                await pressRequest(
                    page.locator(`#consultation-${i + 1}`),
                    'Space',
                );
            }
        }

        const review = page.getByRole('button', { name: 'Tinjau tagihan' });
        await reviewSelection(review);
        const previewText = await body();
        ok(
            previewText.includes('10 attempt · IDR 2.040'),
            `Server total mismatch: ${previewText}`,
        );
        await measure('preview');

        return {
            phase,
            nativeActions: page.p12b.native.length,
            geometry: page.p12b.geometry,
        };
    }

    if (phase === 'confirm') {
        const review = page.getByRole('button', { name: 'Tinjau tagihan' });
        const consultationTwo = page.locator('#consultation-2');
        await pressRequest(consultationTwo, 'Space');
        await page
            .getByRole('heading', { name: 'Konfirmasi tinjauan' })
            .waitFor({ state: 'detached' });
        ok(
            !(await body()).includes('Konfirmasi tinjauan'),
            'Consultation retained stale preview',
        );
        await pressRequest(consultationTwo, 'Space');
        await reviewSelection(review);
        page.p12b.trustedEvents = await page.evaluate(
            () => window.p12bEvents ?? [],
        );
        ok(
            page.p12b.trustedEvents.some(
                (event) => event.type === 'keydown' && event.trusted,
            ) &&
                page.p12b.trustedEvents.some(
                    (event) => event.type === 'change' && event.trusted,
                ),
            'Native keyboard/change evidence missing',
        );
        await control('price-up');
        const method = page.locator('select');
        await tabTo(method);
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        const confirm = page.getByRole('button', {
            name: 'Konfirmasi tagihan',
        });
        await pressRequest(confirm, 'Enter');
        await page
            .getByText('Tinjauan berubah atau tidak lagi tersedia', {
                exact: false,
            })
            .waitFor({ state: 'visible' });
        ok(
            (await body()).includes(
                'Tinjauan berubah atau tidak lagi tersedia',
            ),
            'Stale price not generic',
        );
        await control('price-restore');
        await reviewSelection(review);
        await tabTo(method);
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        await tabTo(confirm);
        page.p12b.preDetailDiagnostics = {
            errors: page.p12b.errors.length,
            blocked: page.p12b.blocked.length,
            failures: page.p12b.failures.length,
        };
        await Promise.all([
            page.waitForURL(/\/admin\/organization-bills\/[0-9]+$/),
            page.keyboard
                .press('Enter')
                .then(() => page.keyboard.press('Enter')),
        ]);
        const detailUrl = page.url();
        page.p12b.detailUrl = detailUrl;
        const detailText = await page.locator('body').innerText();

        ok(
            detailText.includes('2,040'),
            `Detail total mismatch: ${detailText}`,
        );
        await page.reload();
        ok(page.url() === detailUrl, 'Reload changed canonical bill');
        await measure('detail');

        return {
            phase,
            detailUrl,
            nativeActions: page.p12b.native.length,
            trustedEvents: page.p12b.trustedEvents.length,
        };
    }

    if (phase !== 'authorization') {
        throw new Error(`Unknown browser verification phase: ${phase}`);
    }

    const secretText = await page.locator('body').innerText();

    for (const forbidden of [
        'PRIVATE-SENTINEL',
        'proof_object_key',
        'gateway_ref',
        page.p12b.control,
    ]) {
        ok(
            !secretText.includes(forbidden),
            `Secret/clinical leak: ${forbidden}`,
        );
    }

    const ownDetail = page.p12b.detailUrl;
    const ownReference = secretText.match(/AB_[A-Z0-9]+/)?.[0] ?? '';
    const guestLogout = await page.request.get(
        'http://127.0.0.1:8012/preview?as=guest',
        { failOnStatusCode: false },
    );
    ok(guestLogout.status() === 403, 'Guest fixture logout failed');
    const guestDetail = await page.request.get(ownDetail, {
        failOnStatusCode: false,
        maxRedirects: 0,
    });
    ok(
        [302, 403, 404].includes(guestDetail.status()) &&
            !(await guestDetail.text()).includes(ownReference),
        'Guest detail leaked',
    );
    await page.goto('http://127.0.0.1:8012/preview');
    await control('role-off');
    const wrongRoleDetail = await page.request.get(ownDetail, {
        failOnStatusCode: false,
        maxRedirects: 0,
    });
    ok(
        [302, 403, 404].includes(wrongRoleDetail.status()) &&
            !(await wrongRoleDetail.text()).includes(ownReference),
        'Wrong role detail leaked',
    );
    await control('role-on');
    await page.goto('http://127.0.0.1:8012/preview');
    await control('tenant-off');
    const oldTenantDetail = await page.request.get(ownDetail, {
        failOnStatusCode: false,
        maxRedirects: 0,
    });
    ok(
        [302, 403, 404].includes(oldTenantDetail.status()) &&
            !(await oldTenantDetail.text()).includes(ownReference),
        'Old tenant detail leaked',
    );
    await control('tenant-on');
    await page.goto('http://127.0.0.1:8012/preview');
    const foreignDetail = await page.request.get(
        `http://127.0.0.1:8012/admin/organization-bills/${page.p12b.foreignBill}`,
        { failOnStatusCode: false, maxRedirects: 0 },
    );
    ok(
        [302, 403, 404].includes(foreignDetail.status()) &&
            !(await foreignDetail.text()).includes('FOREIGN'),
        'Foreign bill detail leaked',
    );
    const unexpectedErrors = page.p12b.errors.filter(
        (error) => !/status of (?:403|404)/.test(error),
    );
    ok(
        page.p12b.trustedEvents
            .filter((event) => event.type === 'keydown')
            .every((event) => event.trusted),
        'Untrusted keyboard event',
    );
    ok(
        Object.values(page.p12b.preDetailDiagnostics).every(
            (count) => count === 0,
        ) &&
            unexpectedErrors.length === 0 &&
            page.p12b.blocked.length === 0 &&
            page.p12b.failures.length === 0,
        `Console/network failure: ${JSON.stringify({ preDetail: page.p12b.preDetailDiagnostics, unexpectedErrors, blocked: page.p12b.blocked, failures: page.p12b.failures })}`,
    );

    return {
        nativeActions: page.p12b.native.length,
        trustedEvents: page.p12b.trustedEvents.length,
        geometry: page.p12b.geometry,
        responses: page.p12b.responses.length,
        detailUrl: page.p12b.detailUrl,
        phase,
        errorSamples: page.p12b.errors.slice(0, 5),
        detailAndDenialDiagnostics: {
            errors: page.p12b.errors.length,
            blocked: page.p12b.blocked.length,
            failures: page.p12b.failures.length,
        },
    };
}
