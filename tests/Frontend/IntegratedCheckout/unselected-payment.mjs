/** Playwright CLI run-code entrypoint; synthetic existing preview only. */
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression.
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
            errors.push(`Unexpected request: ${url}`);

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
    await page.goto(origin);
    const role = (type, name) => page.getByRole(type, { name, exact: true });
    const payment = () => role('region', 'Pembayaran');
    const receipt = () =>
        role('status', 'Hasil callback simulasi').textContent();
    const select = (label) =>
        role('combobox', 'Skenario').selectOption({ label });
    const active = (locator) =>
        locator.evaluate((element) => document.activeElement === element);
    const tabTo = async (locator, readonlyPayment = true) => {
        for (let step = 0; step < 35; step++) {
            if (await active(locator)) {
                return;
            }

            await page.keyboard.press('Tab');
            check(
                !readonlyPayment ||
                    !(await payment().evaluate((element) =>
                        element.contains(document.activeElement),
                    )),
                'Readonly payment gained focus',
            );
        }

        throw new Error('Keyboard target unreachable');
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
    await select('Pembayar belum dipilih');
    check(
        (await receipt()) === 'Belum ada callback.',
        'Render called callback',
    );
    await tabTo(
        role(
            'checkbox',
            'Saya telah membaca dan menyetujui persetujuan psikotes utama. *',
        ),
    );
    await key('Space');
    await tabTo(role('button', 'Periksa status'));
    check(
        (await receipt()) === 'Belum ada callback.',
        'Keyboard invoked hidden payment',
    );
    await key('Enter');
    check(
        (await receipt()).includes('callback periksa status'),
        'Refresh callback unavailable',
    );
    check(
        !(await receipt()).includes('callback pembayaran'),
        'Unselected payment callback',
    );
    results.push(
        'native Tab/Space/Enter: readonly payment skipped, consent/refresh still reachable, no payment callback',
    );

    for (const [scenario, label, actionable] of [
        ['Mandiri · pending', 'Menunggu pembayaran Anda', true],
        ['Lembaga · menunggu', 'Menunggu pembayaran lembaga', false],
        ['Mandiri · lunas', 'Biaya Anda sudah lunas', false],
        ['Gratis · consent belum', 'Gratis · dikonfirmasi server', false],
        ['Pembayar belum dipilih · nol', 'Pembayar belum dipilih', false],
    ]) {
        await select(scenario);
        check(
            (await payment().textContent()).includes(label),
            `Props state ${scenario}`,
        );
        check(
            (await receipt()) === 'Belum ada callback.',
            `Props caused callback ${scenario}`,
        );
        check(
            (await payment().getByRole('button').count()) ===
                Number(actionable),
            `Payment CTA ${scenario}`,
        );

        if (actionable) {
            // Positive control: the injected payment callback actually works for self.
            await tabTo(
                role('button', 'Lanjutkan pembayaran yang sama'),
                false,
            );
            await key('Enter');
            check(
                (await receipt()).includes('callback pembayaran'),
                'Self callback not injected',
            );
        }

        check(
            (await role('heading', 'Akses tes belum dibuka').count()) === 1,
            `Access changed ${scenario}`,
        );
    }

    results.push(
        'fixture props transitions: unselected → self → organization → paid → free → unselected; access stays locked',
    );

    const captures = [];

    for (const width of [320, 390, 1280]) {
        await page.setViewportSize({ width, height: 900 });

        for (const [scenario, name, amount] of [
            ['Pembayar belum dipilih', 'null', 'Belum tersedia'],
            ['Pembayar belum dipilih · nol', 'zero', 'Rp'],
        ]) {
            await select(scenario);
            check(
                (await payment().locator('button,a,input,select').count()) ===
                    0,
                'Unselected payment is interactive',
            );
            const text = await payment().textContent();
            check(
                text.includes('Pembayar belum dipilih') &&
                    text.includes(amount),
                `Missing unselected label/amount ${name}`,
            );
            check(
                !/Gratis|sudah lunas|Bayar sendiri|Dibayar lembaga|undefined/i.test(
                    text,
                ),
                'Unselected inferred a payer/settlement',
            );
            check(
                (await receipt()) === 'Belum ada callback.',
                'Unselected callback',
            );
            const geometry = await payment().evaluate((element) => {
                const rect = element.getBoundingClientRect();
                const range = document.createRange();
                range.selectNodeContents(element);
                const lines = [...range.getClientRects()];

                return {
                    clientWidth: document.documentElement.clientWidth,
                    scrollWidth: document.documentElement.scrollWidth,
                    padding: getComputedStyle(element).paddingLeft,
                    radius: getComputedStyle(element).borderRadius,
                    heading: getComputedStyle(element.querySelector('h2'))
                        .fontSize,
                    clipped: lines.some(
                        (line) =>
                            line.width &&
                            (line.left < rect.left ||
                                line.right > rect.right ||
                                line.top < rect.top ||
                                line.bottom > rect.bottom),
                    ),
                };
            });
            check(
                geometry.scrollWidth <= geometry.clientWidth &&
                    !geometry.clipped,
                `${width}/${name}: overflow ${JSON.stringify(geometry)}`,
            );
            check(
                geometry.padding === '20px' &&
                    geometry.radius === '10px' &&
                    geometry.heading === '20px',
                'Fixture styling absent',
            );
            const screenshot = `output/playwright/checkout-unselected/${width}-${name}.png`;
            await payment().screenshot({ path: screenshot });
            captures.push({ width, name, screenshot, ...geometry });
        }
    }

    check(errors.length === 0, errors.join('; '));

    return { results, captures, errors };
};
