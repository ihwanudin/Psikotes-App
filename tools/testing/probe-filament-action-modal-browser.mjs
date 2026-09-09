import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, realpathSync, writeFileSync } from 'node:fs';
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
    !cli ||
    !existsSync(cli)
) {
    throw new Error(
        'Disposable fixture and existing Playwright CLI are required.',
    );
}

const artifacts = join(
    root,
    'output/playwright',
    basename(directory),
    'filament-action-probe',
);
mkdirSync(artifacts, { recursive: true });
const session = `oncam-filament-probe-${basename(directory).slice(-8)}`;
const run = (...args) => {
    const result = spawnSync(
        process.execPath,
        [cli, `-s=${session}`, ...args],
        {
            cwd: artifacts,
            encoding: 'utf8',
            timeout: 120000,
            maxBuffer: 8 * 1024 * 1024,
        },
    );
    const output = `${result.stdout ?? ''}${result.stderr ?? ''}`;
    writeFileSync(join(artifacts, `${args[0]}.txt`), output);

    if (result.error || result.status !== 0 || output.includes('### Error')) {
        throw new Error(output, { cause: result.error });
    }

    return output;
};

try {
    run('open', 'about:blank', '--browser', 'chrome');
    const output = run(
        'run-code',
        `async (page) => (${probe.toString()})(page)`,
    );
    const match = output.match(
        /### Result\s*\n([\s\S]*?)\n### Ran Playwright code/,
    );

    if (!match) {
        throw new Error('Missing probe result.');
    }

    const report = JSON.parse(match[1]);
    const expectedEvents = ['sync-action-modals', 'open-modal'];

    if (
        !report.focusVisible ||
        report.openStatus !== 200 ||
        report.submitStatus !== 200 ||
        !report.modalWindowVisible ||
        !report.submitted ||
        report.openState.wrapper?.actionNestingIndex !== 0 ||
        !expectedEvents.every((type) =>
            report.browser.probe.events.some((event) => event.type === type),
        ) ||
        !report.browser.probe.native.some(
            (event) => event.type === 'keydown' && event.trusted,
        ) ||
        Object.values(report.diagnostics).some((items) => items.length > 0)
    ) {
        throw new Error(`Minimal Filament action probe failed: ${match[1]}`);
    }

    writeFileSync(
        join(artifacts, 'report.json'),
        JSON.stringify(report, null, 2),
    );
    console.log(JSON.stringify(report));
} finally {
    run('close');
}

async function probe(page) {
    const diagnostics = { console: [], failures: [], blocked: [] };
    page.on(
        'console',
        (message) =>
            ['error', 'warning'].includes(message.type()) &&
            diagnostics.console.push(`${message.type()}: ${message.text()}`),
    );
    page.on('pageerror', (error) =>
        diagnostics.console.push(`pageerror: ${error.message}`),
    );
    page.on('requestfailed', (request) =>
        diagnostics.failures.push(request.url()),
    );
    await page.context().route('**/*', (route) => {
        if (route.request().url().startsWith('http://127.0.0.1:8012/')) {
            return route.continue();
        }

        diagnostics.blocked.push(route.request().url());

        return route.abort();
    });
    await page.addInitScript(() => {
        window.filamentProbe = { alpineInit: 0, events: [], native: [] };
        document.addEventListener('alpine:init', () => {
            window.filamentProbe.alpineInit++;
        });

        for (const type of [
            'sync-action-modals',
            'open-modal',
            'close-modal',
            'modal-closed',
        ]) {
            document.addEventListener(type, (event) =>
                window.filamentProbe.events.push({
                    type,
                    id: event.detail?.id ?? null,
                    newActionNestingIndex:
                        event.detail?.newActionNestingIndex ?? null,
                    trusted: event.isTrusted,
                }),
            );
        }

        for (const type of ['keydown', 'keyup', 'click', 'input', 'change']) {
            document.addEventListener(
                type,
                (event) =>
                    window.filamentProbe.native.push({
                        type,
                        key: event.key ?? null,
                        trusted: event.isTrusted,
                    }),
                true,
            );
        }
    });
    await page.goto('http://127.0.0.1:8012/action-probe');
    await page.waitForLoadState('networkidle');
    const button = page.getByRole('button', {
        name: 'Buka modal minimal',
        exact: true,
    });

    for (let index = 0; index < 50; index++) {
        if (
            await button.evaluate(
                (element) => element === document.activeElement,
            )
        ) {
            break;
        }

        await page.keyboard.press('Tab');
    }

    const focusVisible = await button.evaluate(
        (element) =>
            element === document.activeElement &&
            element.matches(':focus-visible'),
    );
    const update = page.waitForResponse((response) =>
        response.url().endsWith('/fixture-update'),
    );
    await page.keyboard.press('Space');
    const openStatus = (await update).status();
    const dialog = page.getByRole('dialog', { name: 'Buka modal minimal' });
    const modalWindow = dialog.locator('.fi-modal-window');
    await modalWindow.waitFor({ state: 'visible' });
    const wrapper = page.locator('[wire\\:partial="action-modals"]');
    const component = page
        .locator('[wire\\:id]')
        .filter({ has: button })
        .first();
    const componentId = await component.getAttribute('wire:id');
    const openState = {
        modalWindowVisible: await modalWindow.isVisible(),
        modalId: await dialog.getAttribute('id'),
        dialog: await dialog.evaluate((element) => ({
            className: element.className,
            display: getComputedStyle(element).display,
            visibility: getComputedStyle(element).visibility,
            initialized: Array.isArray(element._x_dataStack),
            isOpen: element._x_dataStack?.[0]?.isOpen ?? null,
        })),
        mountedActions: componentId
            ? await page.evaluate(
                  (id) => window.Livewire.find(id)?.get('mountedActions'),
                  componentId,
              )
            : null,
        wrapper: (await wrapper.count())
            ? await wrapper.evaluate((element) => ({
                  initialized: Array.isArray(element._x_dataStack),
                  actionNestingIndex:
                      element._x_dataStack?.[0]?.actionNestingIndex ?? null,
              }))
            : null,
    };
    const input = page.getByRole('textbox', { name: 'Nilai sintetis' });
    await input.waitFor({ state: 'visible' });

    for (let index = 0; index < 20; index++) {
        if (
            await input.evaluate(
                (element) => element === document.activeElement,
            )
        ) {
            break;
        }

        await page.keyboard.press('Tab');
    }

    await page.keyboard.type('uji native');
    const submit = page.getByRole('button', { name: 'Submit' });

    for (let index = 0; index < 20; index++) {
        if (
            await submit.evaluate(
                (element) => element === document.activeElement,
            )
        ) {
            break;
        }

        await page.keyboard.press('Tab');
    }

    const submitUpdate = page.waitForResponse((response) =>
        response.url().endsWith('/fixture-update'),
    );
    await page.keyboard.press('Enter');
    const submitStatus = (await submitUpdate).status();
    await page.getByRole('status').waitFor({ state: 'visible' });
    const browserState = await page.evaluate(() => ({
        alpine: Boolean(window.Alpine),
        livewire: Boolean(window.Livewire),
        probe: window.filamentProbe,
        actionAsset: performance
            .getEntriesByType('resource')
            .some((entry) =>
                entry.name.includes('/js/filament/actions/actions.js'),
            ),
    }));

    return {
        focusVisible,
        openStatus,
        submitStatus,
        modalWindowVisible: openState.modalWindowVisible,
        submitted: await page.getByRole('status').isVisible(),
        openState,
        browser: browserState,
        diagnostics,
    };
}
