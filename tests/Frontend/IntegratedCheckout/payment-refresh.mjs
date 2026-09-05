/** CLI runner: copy this function expression without its final semicolon. */
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- Playwright CLI entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8011';
    const errors = [];
    const results = [];
    const check = (condition, message) => {
        if (!condition) {
            throw new Error(message);
        }
    };
    await page.route('**/*', (route) => {
        const url = route.request().url();

        if (!url.startsWith(`${origin}/`) || url.startsWith(`${origin}/api/`)) {
            errors.push(`Unexpected request ${url}`);

            return route.abort();
        }

        if (url === `${origin}/favicon.ico`) {
            return route.fulfill({ status: 204, body: '' });
        }

        return route.continue();
    });
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) {
            errors.push(message.text());
        }
    });
    page.on('requestfailed', (request) => errors.push(request.url()));
    page.on('response', (response) => {
        if (response.status() >= 400) {
            errors.push(`HTTP ${response.status()}`);
        }
    });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(origin);
    const role = (type, name) => page.getByRole(type, { name, exact: true });
    const phone = () => role('textbox', 'Nomor WhatsApp *');
    const psych = () =>
        role(
            'checkbox',
            'Saya telah membaca dan menyetujui persetujuan psikotes utama. *',
        );
    const dass = () =>
        role(
            'checkbox',
            'Saya telah membaca dan menyetujui persetujuan DASS-21. *',
        );
    const payment = () => role('region', 'Pembayaran');
    const paymentCalls = async () =>
        Number(
            await role('status', 'Jumlah callback pembayaran').textContent(),
        );
    const active = (locator) =>
        locator.evaluate((node) => document.activeElement === node);
    const tabTo = async (locator) => {
        for (let step = 0; step < 55; step++) {
            if (await active(locator)) {
                return;
            }

            await page.keyboard.press('Tab');
        }

        throw new Error('Target not reachable with native Tab');
    };
    const key = async (value) => {
        await page.keyboard.press(value);
        check(
            (
                await role('status', 'Input keyboard terakhir').textContent()
            ).includes('trusted=true'),
            `Untrusted ${value}`,
        );
    };
    await role('combobox', 'Skenario').selectOption({
        label: 'Mandiri · profil kurang',
    });
    await tabTo(role('checkbox', 'Legal review pending (fixture)'));
    await key('Space');
    await tabTo(phone());
    await page.keyboard.type('080000000004');
    await tabTo(psych());
    await key('Space');
    await tabTo(dass());
    await key('Space');
    const originalForm = await role(
        'form',
        'Konfirmasi checkout',
    ).elementHandle();
    const originalPhone = await phone().elementHandle();
    check(originalForm && originalPhone, 'Mounted form required');
    const assertRetained = async () => {
        check(
            await role('form', 'Konfirmasi checkout').evaluate(
                (node, original) => node === original,
                originalForm,
            ),
            'Form remounted on payment refresh',
        );
        check(
            await phone().evaluate(
                (node, original) => node === original,
                originalPhone,
            ),
            'Phone remounted on payment refresh',
        );
        check(
            (await phone().inputValue()) === '080000000004',
            'Missing profile edit lost',
        );
        check(
            (await psych().isChecked()) && (await dass().isChecked()),
            'Consent choices lost',
        );
        check(
            (await role('status', 'Jumlah konfirmasi').textContent()) === '0',
            'Automatic confirmation',
        );
        check(
            (await role('heading', 'Akses tes belum dibuka').count()) === 1,
            'Payment refresh opened access',
        );
    };
    let staleButton;
    let expectedCalls = 0;

    for (const [state, text, actionable] of [
        ['unselected-null', 'Pembayar belum dipilih', false],
        ['self-pending', 'Menunggu pembayaran Anda', true],
        ['organization-pending', 'Menunggu pembayaran lembaga', false],
        ['paid', 'Biaya Anda sudah lunas', false],
        ['free', 'Gratis · dikonfirmasi server', false],
        ['unselected-zero', 'Pembayar belum dipilih', false],
    ]) {
        await tabTo(role('button', 'Refresh payment fixture'));
        await key('Enter');
        await assertRetained();
        check(
            (await payment().textContent()).includes(text),
            `Latest props not rendered ${state}`,
        );
        check(
            (await payment().getByRole('button').count()) ===
                Number(actionable),
            `Stale CTA ${state}`,
        );
        check(
            (await paymentCalls()) === expectedCalls,
            `Automatic payment ${state}`,
        );

        if (actionable) {
            const target = role('button', 'Lanjutkan pembayaran yang sama');
            staleButton = await target.elementHandle();
            await tabTo(target);
            await key('Enter');
            expectedCalls++;
            check(
                (await paymentCalls()) === expectedCalls,
                'Self positive callback',
            );
            await assertRetained();
        }

        if (state === 'organization-pending' || state === 'unselected-zero') {
            check(
                staleButton &&
                    !(await staleButton.evaluate((node) => node.isConnected)),
                'Old self button still connected',
            );
            let rejected = false;

            try {
                // Native actionability attempt on the old DOM handle, NOT JS handler invocation.
                await staleButton.click({ timeout: 300 });
            } catch {
                rejected = true;
            }

            check(
                rejected && (await paymentCalls()) === expectedCalls,
                `Detached self button usable ${state}`,
            );
        }

        if (state === 'unselected-zero') {
            const text = await payment().textContent();
            check(
                /Rp\s*0/.test(text) && !/Gratis|sudah lunas/.test(text),
                'Zero amount inferred settlement',
            );
        }

        results.push({
            state,
            sameForm: true,
            sameInput: true,
            retainedConsent: true,
            paymentCalls: expectedCalls,
        });
    }

    await page
        .locator('main')
        .screenshot({ path: 'output/playwright/payment-refresh/retained.png' });

    for (const visibility of ['expired', 'loading']) {
        // Each hidden state must remove a populated summary, not inherit an empty state.
        await role('combobox', 'Visibilitas fixture').selectOption('ready');
        await tabTo(phone());
        await key('Control+A');
        await key('Backspace');
        await page.keyboard.type('080000000004');
        const visibleForm = await role(
            'form',
            'Konfirmasi checkout',
        ).elementHandle();
        check(
            (await page.locator('main').textContent()).includes('Nadia') &&
                (await phone().inputValue()) === '080000000004',
            `${visibility}: populated precondition`,
        );
        await role('combobox', 'Visibilitas fixture').selectOption(visibility);
        const main = page.locator('main');
        check(
            (await main.locator('form,input').count()) === 0,
            `${visibility}: form/inputs retained`,
        );
        check(
            !/Nadia|nadia@example|080000000004|Cabang Bandung|175.000/.test(
                await main.textContent(),
            ),
            `${visibility}: PII retained`,
        );
        check(
            visibleForm &&
                !(await visibleForm.evaluate((node) => node.isConnected)),
            `${visibility}: old form still connected`,
        );
        check(
            (await paymentCalls()) === expectedCalls,
            `${visibility}: automatic payment`,
        );
        await main.screenshot({
            path: `output/playwright/payment-refresh/${visibility}.png`,
        });
        results.push({
            visibility,
            privateSummaryRemoved: true,
            paymentCalls: expectedCalls,
        });
    }

    check(errors.length === 0, errors.join('; '));

    return { results, errors };
};
