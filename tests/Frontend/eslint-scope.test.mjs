import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { ESLint } from 'eslint';

const eslint = new ESLint({
    cwd: fileURLToPath(new URL('../../', import.meta.url)),
});

test('generated verification and browser artifacts are not lint inputs', async () => {
    for (const filePath of [
        'storage/app/private/verification/frontend-test/checkout.test.js',
        'storage/app/private/verification/frontend-preview/assets/index.js',
        'storage/app/private/verification/participant-lobby/assets/index.js',
        'output/playwright/checkout-combined/run.js',
        '.playwright-cli/artifacts/run.js',
    ]) {
        assert.equal(await eslint.isPathIgnored(filePath), true, filePath);
        assert.deepEqual(
            await eslint.lintText('if (true) console.log("synthetic");', {
                filePath,
                warnIgnored: false,
            }),
            [],
            filePath,
        );
    }
});

test('app and tests still enforce source rules, including adjacent directories', async () => {
    for (const filePath of [
        'resources/js/components/integrated-checkout/checkout-form.tsx',
        'resources/js/pages/participant/lobby.tsx',
        'tests/Frontend/IntegratedCheckout/checkout.test.tsx',
        'tests/Frontend/IntegratedCheckout/browser-interactions.mjs',
        'tests/Frontend/ParticipantLobby/browser.test.mjs',
        'tests/Frontend/eslint-scope.test.mjs',
        'storage/app/private/source.js',
        'storage/app/private/verification-source/probe.js',
        'output/source.js',
        'output/playwright-source/probe.js',
        '.playwright-cli-source/probe.js',
        'tests/Frontend/fixtures/output/playwright/probe.mjs',
    ]) {
        assert.equal(await eslint.isPathIgnored(filePath), false, filePath);
        const [result] = await eslint.lintText(
            'if (true) console.log("synthetic");',
            { filePath },
        );
        assert.ok(
            result.messages.some(
                (message) =>
                    message.ruleId === 'curly' && message.severity === 2,
            ),
            `Source rule must still reject invalid code at ${filePath}`,
        );
    }
});
