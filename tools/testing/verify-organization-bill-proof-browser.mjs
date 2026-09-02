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
const cli = process.env.ONCAM_PLAYWRIGHT_CLI;

if (
    !directory ||
    realpathSync(dirname(directory)) !== realpathSync(tmpdir()) ||
    !/^oncam-collective-page-[a-f0-9]{32}$/.test(basename(directory)) ||
    !existsSync(join(directory, 'manifest.json')) ||
    existsSync(join(directory, '.env')) ||
    !cli ||
    !existsSync(cli)
) {
    throw new Error('Disposable fixture and existing Playwright CLI required.');
}

const manifest = JSON.parse(
    readFileSync(join(directory, 'manifest.json'), 'utf8'),
);
const files = Object.fromEntries(
    ['proof.jpg', 'proof.png', 'proof.pdf', 'invalid.txt', 'oversize.pdf'].map(
        (name) => [
            name,
            join(directory, 'uploads', name).replaceAll('\\', '/'),
        ],
    ),
);
const artifacts = join(
    root,
    'output/playwright',
    basename(directory),
    'p12c-acceptance',
);
mkdirSync(artifacts, { recursive: true });
const session = `oncam-p12c-${basename(directory).slice(-8)}`;
const run = (...args) => {
    const result = spawnSync(
        process.execPath,
        [cli, `-s=${session}`, ...args],
        {
            cwd: artifacts,
            encoding: 'utf8',
            timeout: 900000,
            maxBuffer: 16 * 1024 * 1024,
        },
    );
    const output = `${result.stdout ?? ''}${result.stderr ?? ''}`;
    writeFileSync(join(artifacts, `${args[0]}-${Date.now()}.txt`), output);

    if (result.error || result.status !== 0 || output.includes('### Error')) {
        throw new Error(output, { cause: result.error });
    }

    return output;
};

try {
    run('open', 'about:blank', '--browser', 'chrome');
    const output = run(
        'run-code',
        `async (page) => (${verify.toString()})(page, ${JSON.stringify(manifest)}, ${JSON.stringify(files)})`,
    );
    const match = output.match(
        /### Result\s*\n([\s\S]*?)\n### Ran Playwright code/,
    );
    if (!match) throw new Error('Missing P12c browser result.');
    const report = JSON.parse(match[1]);
    writeFileSync(
        join(artifacts, 'p12c-report.json'),
        JSON.stringify(report, null, 2),
    );
    console.log(JSON.stringify(report));
} finally {
    run('close');
}

async function verify(page, manifest, files) {
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(60000);
    const ok = (condition, message) => {
        if (!condition) throw new Error(message);
    };
    const state = {
        responses: [],
        errors: [],
        failures: [],
        blocked: [],
        native: [],
        geometry: [],
        denials: [],
        uploads: [],
        proofUrls: [],
    };
    page.on('response', (response) =>
        state.responses.push({
            url: response.url(),
            status: response.status(),
        }),
    );
    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) {
            state.errors.push(`${message.type()}: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) =>
        state.errors.push(`pageerror: ${error.message}`),
    );
    page.on('requestfailed', (request) => state.failures.push(request.url()));
    await page.context().route('**/*', (route) => {
        const url = route.request().url();
        if (
            url.startsWith('http://127.0.0.1:8012/') ||
            url.startsWith('blob:')
        ) {
            return route.continue();
        }
        state.blocked.push(url);

        return route.abort();
    });
    await page.exposeFunction('recordP12cNative', (event) => {
        state.native.push(event);
    });
    await page.addInitScript(() => {
        window.p12cNative = [];
        for (const type of ['keydown', 'keyup', 'click', 'change']) {
            document.addEventListener(
                type,
                (event) => {
                    const record = {
                        type,
                        key: event.key ?? null,
                        trusted: event.isTrusted,
                    };
                    window.p12cNative.push(record);
                    window.recordP12cNative(record);
                },
                true,
            );
        }
    });

    const base = 'http://127.0.0.1:8012';
    const detail = `${base}/admin/organization-bills/${manifest.baselineBill}`;
    const tabTo = async (locator, limit = 100) => {
        for (let index = 0; index < limit; index++) {
            if (
                await locator.evaluate(
                    (element) => element === document.activeElement,
                )
            ) {
                ok(
                    await locator.evaluate((element) =>
                        element.matches(':focus-visible'),
                    ),
                    'Focused control lacks :focus-visible.',
                );

                return;
            }
            await page.keyboard.press('Tab');
        }
        throw new Error('Native Tab did not reach target.');
    };
    const control = async (action) => {
        const response = await page.request.post(`${base}/fixture-control`, {
            headers: { 'X-Oncam-Fixture': manifest.control },
            form: { action },
        });
        ok(response.status() === 200, `Fixture control ${action} failed.`);

        return response.json();
    };
    const body = () => page.locator('body').innerText();
    const reload = async (checkpoint) => {
        const before = page.url();
        try {
            await page.reload();
        } catch (error) {
            throw new Error(
                `Reload failed at ${checkpoint} from ${before}: ${error.message}`,
            );
        }
    };
    const openUpload = async () => {
        const button = page.getByRole('button', {
            name: /^(?:Unggah|Ganti) bukti$/,
        });
        await tabTo(button);
        const update = page.waitForResponse((response) =>
            response.url().endsWith('/fixture-update'),
        );
        await page.keyboard.press('Space');
        ok((await update).status() === 200, 'Opening upload action failed.');
        const dialog = page.getByRole('dialog', {
            name: /^(?:Unggah|Ganti) bukti$/,
        });
        await dialog.locator('.fi-modal-window').waitFor({ state: 'visible' });
        await dialog.locator('.filepond--root').waitFor({ state: 'visible' });
        ok(
            await dialog.locator('.fi-modal-window').isVisible(),
            'Upload modal window is not visible.',
        );

        return dialog;
    };
    const stageFile = async (dialog, path) => {
        const uploadResponse = page.waitForResponse((response) =>
            response.url().includes('/upload-file'),
        );
        await dialog.locator('input[type=file]').setInputFiles(path);
        ok((await uploadResponse).status() === 200, 'Temporary upload failed.');
        await dialog.getByText('Upload complete', { exact: true }).waitFor({
            state: 'visible',
        });
    };
    const submitUpload = async (
        dialog,
        expectedTitle,
        expectedStatus = 200,
    ) => {
        const submit = dialog.getByRole('button', { name: 'Submit' });
        await tabTo(submit, 20);
        const update = page.waitForResponse((response) =>
            response.url().endsWith('/fixture-update'),
        );
        await page.keyboard.press('Enter');
        const status = (await update).status();
        const expectedStatuses = Array.isArray(expectedStatus)
            ? expectedStatus
            : [expectedStatus];
        ok(
            expectedStatuses.includes(status),
            `Upload action returned ${status}.`,
        );
        if (expectedTitle) {
            await page.getByText(expectedTitle, { exact: true }).waitFor({
                state: 'visible',
            });
        }

        return status;
    };
    const upload = async (name, title) => {
        const dialog = await openUpload();
        await stageFile(dialog, files[name]);
        await submitUpload(dialog, title);
        state.uploads.push(name);
    };
    const summary = () => control('proof-summary');
    const measure = async (name, modal = false) => {
        for (const width of [320, 390, 1280]) {
            await page.setViewportSize({ width, height: 800 });
            const result = await page.evaluate((checkModal) => {
                const targets = checkModal
                    ? [...document.querySelectorAll('.fi-modal-window *')]
                    : [...document.querySelectorAll('#fi-main-content *')];
                const clippedElements = targets.filter((element) => {
                    const rect = element.getBoundingClientRect();
                    const style = getComputedStyle(element);

                    return (
                        rect.width > 0 &&
                        rect.height > 0 &&
                        style.display !== 'none' &&
                        style.visibility !== 'hidden' &&
                        (rect.left < -1 || rect.right > innerWidth + 1)
                    );
                });

                return {
                    width: innerWidth,
                    scrollWidth: document.documentElement.scrollWidth,
                    clipped: clippedElements.length,
                    clippedSamples: clippedElements
                        .slice(0, 5)
                        .map((element) => ({
                            tag: element.tagName,
                            className: element.className,
                            text: element.textContent?.trim().slice(0, 80),
                            rect: element.getBoundingClientRect().toJSON(),
                        })),
                };
            }, modal);
            ok(
                result.scrollWidth <= width + 1 && result.clipped === 0,
                `Overflow in ${name} at ${width}px: ${JSON.stringify(result)}`,
            );
            state.geometry.push({ name, ...result });
            await page.screenshot({
                path: `${name}-${width}.png`,
                fullPage: true,
            });
        }
    };
    const assertNoUpload = async (stateName) => {
        await reload(`state-${stateName}`);
        ok(
            (await page
                .getByRole('button', { name: /^(?:Unggah|Ganti) bukti$/ })
                .count()) === 0,
            `Upload remained visible for ${stateName}.`,
        );
    };

    await page.goto(`${base}/preview`);
    await page.goto(detail);
    await page.waitForLoadState('networkidle');
    await measure('detail');

    let dialog = await openUpload();
    await measure('upload-modal', true);
    await page.keyboard.press('Escape');
    await dialog.locator('.fi-modal-window').waitFor({ state: 'hidden' });
    dialog = await openUpload();
    const cancel = dialog.getByRole('button', { name: 'Cancel' });
    await tabTo(cancel, 20);
    await page.keyboard.press('Enter');
    await dialog.locator('.fi-modal-window').waitFor({ state: 'hidden' });

    await upload('proof.jpg', 'Bukti disimpan.');
    let proof = await summary();
    ok(
        proof.status === 'pending' &&
            proof.proofMime === 'image/jpeg' &&
            proof.proofFiles === 1 &&
            proof.entitlements === 0 &&
            proof.outbox === 0 &&
            proof.orders === 0 &&
            proof.settledItems === 0,
        `JPEG state invalid: ${JSON.stringify(proof)}`,
    );
    await reload('after-jpeg');
    const openProof = page.getByRole('button', { name: 'Buka bukti' });
    await tabTo(openProof);
    const proofResponse = page.waitForResponse((response) =>
        response.url().includes('/fixture-proof/'),
    );
    await page.keyboard.press('Enter');
    const firstProof = await proofResponse;
    await page.waitForURL(/\/fixture-proof\/[1-9][0-9]*$/);
    ok(firstProof.status() === 200, 'Opening proof failed.');
    ok(
        firstProof.headers()['cache-control']?.includes('no-store') &&
            firstProof.headers()['cache-control']?.includes('private') &&
            firstProof.headers()['referrer-policy']?.includes('no-referrer') &&
            firstProof.headers()['x-content-type-options'] === 'nosniff',
        `Proof response headers are incomplete: ${JSON.stringify(firstProof.headers())}`,
    );
    state.proofUrls.push(firstProof.url());
    await page.goto(detail);

    await upload('proof.png', 'Bukti diganti.');
    proof = await summary();
    ok(
        proof.proofMime === 'image/png' && proof.proofFiles === 1,
        'PNG replacement did not remain canonical.',
    );
    const oldProofStatus = await page.evaluate(async (url) => {
        const response = await fetch(url, { credentials: 'same-origin' });

        return response.status;
    }, state.proofUrls[0]);
    ok(oldProofStatus === 404, 'Old proof URL survived replacement.');

    await reload('after-png');
    await upload('proof.pdf', 'Bukti diganti.');
    proof = await summary();
    ok(
        proof.proofMime === 'application/pdf' && proof.proofFiles === 1,
        'PDF replacement did not remain canonical.',
    );

    await reload('after-pdf');
    dialog = await openUpload();
    await stageFile(dialog, files['proof.jpg']);
    await control('replace-proof-outside');
    await submitUpload(
        dialog,
        'Bukti tidak dapat disimpan. Muat ulang dan coba kembali.',
    );
    proof = await summary();
    ok(
        proof.proofMime === 'image/png' && proof.proofFiles === 1,
        'Stale modal overwrote the external replacement.',
    );

    for (const name of ['invalid.txt', 'oversize.pdf']) {
        await reload(`before-${name}`);
        await upload(
            name,
            'Bukti tidak dapat disimpan. Muat ulang dan coba kembali.',
        );
        const afterInvalid = await summary();
        ok(
            afterInvalid.proofMime === 'image/png' &&
                afterInvalid.proofFiles === 1,
            `${name} left a partial replacement.`,
        );
    }

    await reload('before-double-submit');
    dialog = await openUpload();
    await stageFile(dialog, files['proof.jpg']);
    const submit = dialog.getByRole('button', { name: 'Submit' });
    await tabTo(submit, 20);
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await page.getByText('Bukti diganti.', { exact: true }).waitFor({
        state: 'visible',
    });
    proof = await summary();
    ok(proof.proofFiles === 1, 'Double submit created duplicate proof files.');
    await reload('after-double-submit');
    ok(
        (await page.getByRole('button', { name: 'Buka bukti' }).count()) === 1,
        'Reload duplicated the open-proof action.',
    );

    for (const [controlName, stateName] of [
        ['bill-rejected', 'rejected'],
        ['bill-expired', 'expired'],
        ['bill-paid', 'paid'],
        ['bill-nonmanual', 'nonmanual'],
    ]) {
        await control(controlName);
        await assertNoUpload(stateName);
        await control('bill-pending');
    }

    for (const [off, on, name] of [
        ['role-off', 'role-on', 'role'],
        ['tenant-off', 'tenant-on', 'tenant'],
        ['deleted-off', 'deleted-on', 'deleted'],
    ]) {
        await page.goto(`${base}/preview`);
        await page.goto(detail);
        dialog = await openUpload();
        await stageFile(dialog, files['proof.png']);
        await control(off);
        const status = await submitUpload(dialog, null, [302, 403, 404]);
        state.denials.push({ name, status });
        await control(on);
    }

    await page.goto(`${base}/preview`);
    const [foreignStatus, missingStatus] = await page.evaluate(
        async ([foreignUrl, missingUrl]) => {
            const request = async (url) =>
                (
                    await fetch(url, {
                        credentials: 'same-origin',
                        redirect: 'manual',
                    })
                ).status;

            return Promise.all([request(foreignUrl), request(missingUrl)]);
        },
        [
            `${base}/admin/organization-bills/${manifest.foreignBill}`,
            `${base}/admin/organization-bills/999999999`,
        ],
    );
    ok(
        [302, 403, 404].includes(foreignStatus) &&
            [302, 403, 404].includes(missingStatus),
        'Foreign or missing direct URL was not denied.',
    );

    await page.goto(detail);
    const text = await body();
    const forbidden = [
        'PRIVATE-SENTINEL',
        'proof_object_key',
        'proof_checksum',
        'gateway_ref',
        'invoice_url',
        manifest.control,
    ];
    ok(
        forbidden.every(
            (value) => !text.includes(value) && !page.url().includes(value),
        ),
        'Secret or clinical field leaked into DOM/URL.',
    );
    proof = await summary();
    ok(
        proof.auditSecretLeak === false &&
            proof.proofFiles === 1 &&
            proof.entitlements === 0 &&
            proof.outbox === 0 &&
            proof.orders === 0 &&
            proof.settledItems === 0,
        `Final read-only boundary failed: ${JSON.stringify(proof)}`,
    );
    ok(
        state.native.some(
            (event) => event.type === 'keydown' && event.trusted,
        ) &&
            state.native
                .filter((event) => event.type === 'keydown')
                .every((event) => event.trusted),
        'Native keyboard evidence is missing or untrusted.',
    );
    const unexpectedResponses = state.responses.filter(
        (response) =>
            response.status >= 400 &&
            ![403, 404, 422].includes(response.status),
    );
    const unexpectedErrors = state.errors.filter(
        (error) =>
            !/status of (?:403|404|422)/i.test(error) &&
            !/source image could not be decoded/i.test(error),
    );
    ok(
        unexpectedResponses.length === 0 &&
            unexpectedErrors.length === 0 &&
            state.failures.length === 0 &&
            state.blocked.length === 0,
        `Console/network failure: ${JSON.stringify({ unexpectedResponses, unexpectedErrors, failures: state.failures, blocked: state.blocked })}`,
    );

    return {
        uploads: state.uploads,
        proofUrls: state.proofUrls.length,
        denials: state.denials,
        geometry: state.geometry,
        nativeEvents: state.native.length,
        trustedEvents: state.native.filter((event) => event.trusted).length,
        responses: state.responses.length,
        expectedHttpErrors: state.responses.filter(
            (response) => response.status >= 400,
        ).length,
        consoleSamples: state.errors.slice(0, 5),
        finalSummary: proof,
    };
}
