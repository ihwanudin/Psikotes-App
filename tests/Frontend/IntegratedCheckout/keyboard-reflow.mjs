/** Synthetic Playwright Page checks; DOM evaluation only reads state/geometry. */
export async function verifyCheckoutKeyboardAndReflow(page) {
    const results = [];
    const role = (type, name) => page.getByRole(type, { name, exact: true });
    const check = (condition, message) => {
        if (!condition) {
            throw new Error(message);
        }
    };
    const active = (locator) =>
        locator.evaluate((element) => document.activeElement === element);
    const key = async (value) => {
        // The fixture observer is on #root, so Tab from body has no React capture.
        const captured = await page.evaluate(() =>
            Boolean(document.activeElement?.closest('#root')),
        );
        await page.keyboard.press(value);
        check(
            !captured ||
                (
                    await role(
                        'status',
                        'Input keyboard terakhir',
                    ).textContent()
                ).includes('trusted=true'),
            `Untrusted ${value}`,
        );
    };
    // Reach controls by native Tab only: no focus(), DOM assignment or dispatchEvent.
    const tabTo = async (locator) => {
        for (let attempt = 0; attempt < 45; attempt++) {
            if (await active(locator)) {
                return;
            }

            await key('Tab');
        }

        throw new Error(`Not reachable by Tab: ${await locator.textContent()}`);
    };
    const type = async (locator, value) => {
        await tabTo(locator);
        await key('Control+A');
        await key('Backspace');
        await page.keyboard.type(value);
    };
    const toggle = async (name) => {
        await tabTo(role('checkbox', name));
        await key('Space');
    };
    const button = async (name) => {
        await tabTo(role('button', name));
        await key('Enter');
    };
    const email = () => role('textbox', 'Email (opsional)');
    const phone = () => role('textbox', 'Nomor WhatsApp *');
    const submit = () => role('button', 'Konfirmasi data dan persetujuan');
    const psychName =
        'Saya telah membaca dan menyetujui persetujuan psikotes utama. *';
    const psych = () => role('checkbox', psychName);
    const dassName = 'Saya telah membaca dan menyetujui persetujuan DASS-21. *';
    const dass = () => role('checkbox', dassName);
    const count = async () =>
        Number(await role('status', 'Jumlah konfirmasi').textContent());
    const payload = async () => {
        const text = await role(
            'status',
            'Hasil callback simulasi',
        ).textContent();

        return JSON.parse(
            text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1),
        );
    };
    const select = (name) =>
        role('combobox', 'Skenario').selectOption({ label: name });
    await page.goto('http://127.0.0.1:8012');
    await select('Email opsional · consent tercatat');
    await toggle('Legal review pending (fixture)');
    await tabTo(email());
    await key('Space');
    await key('Enter');
    check(
        (await count()) === 0 && (await submit().count()) === 0,
        'Blank/space optional submitted',
    );
    await type(email(), 'keyboard@example.test');
    await tabTo(submit());
    await key('Enter');
    check(
        (await count()) === 1 &&
            JSON.stringify(await payload()) ===
                JSON.stringify({
                    missingProfile: { email: 'keyboard@example.test' },
                }),
        'Keyboard email payload',
    );
    await type(email(), '');
    await key('Enter');
    check(
        (await count()) === 1 && (await submit().count()) === 0,
        'Cleared email submitted',
    );
    results.push(
        'native Tab/Space/Enter: optional blank, typed, cleared; exact email-only callback',
    );

    await type(email(), 'invalid-email');
    await tabTo(submit());
    await key('Enter');
    check(
        (await count()) === 1 &&
            (await active(email())) &&
            (await email().evaluate(
                (element) => element.validity.typeMismatch,
            )),
        'Native invalid email focus/guard',
    );
    results.push(
        'native Enter rejects invalid email and moves focus from submit to email',
    );

    await type(email(), 'keyboard@example.test');
    await toggle('Callback simulasi');
    check(await submit().isDisabled(), 'Callback absent submit enabled');
    await tabTo(email());
    await key('Enter');
    check((await count()) === 1, 'Callback absent keyboard submitted');
    await toggle('Callback simulasi');
    await toggle('Legal review pending (fixture)');
    await type(email(), 'keyboard@example.test');
    check(await submit().isDisabled(), 'Legal pending submit enabled');
    await key('Enter');
    check((await count()) === 1, 'Legal pending keyboard submitted');
    await toggle('Legal review pending (fixture)');
    await type(email(), 'keyboard@example.test');
    await toggle('Sedang menyimpan');
    check(
        (await email().isDisabled()) &&
            (await role('button', 'Menyimpan…').isDisabled()),
        'Busy controls enabled',
    );

    for (let step = 0; step < 22; step++) {
        await key('Tab');
        check(
            !(await active(email())) &&
                !(await active(role('button', 'Menyimpan…'))),
            'Busy control in tab order',
        );
    }

    check((await count()) === 1, 'Busy keyboard submitted');
    await toggle('Sedang menyimpan');
    await button('Ganti formKey fixture');
    check(
        (await email().inputValue()) === '' && (await submit().count()) === 0,
        'Form key did not clear optional',
    );
    await tabTo(email());
    await key('Enter');
    check((await count()) === 1, 'Reset email submitted');
    results.push(
        'native keyboard guards: callback absent/legal pending/disabled busy tab order/formKey reset',
    );

    await select('Phone wajib + email opsional');
    await tabTo(submit());
    await key('Enter');
    check(
        (await count()) === 1 &&
            (await active(phone())) &&
            (await phone().evaluate(
                (element) => element.validity.valueMissing,
            )),
        'Required phone validation focus',
    );
    await type(phone(), '080000000003');
    await type(email(), 'invalid-email');
    await tabTo(submit());
    await key('Enter');
    check(
        (await count()) === 1 && (await active(email())),
        'Mixed invalid email focus',
    );
    await type(email(), '');
    await key('Space');
    await tabTo(submit());
    await key('Enter');
    check(
        (await count()) === 2 &&
            JSON.stringify(await payload()) ===
                JSON.stringify({ missingProfile: { phone: '080000000003' } }),
        'Mixed payload must omit blank email',
    );
    await button('Simulasikan error validasi');
    check(
        await active(role('alert', '')),
        'Error summary did not receive focus',
    );
    await key('Tab');
    check(
        await active(
            role(
                'link',
                'Nomor WhatsApp: Gunakan nomor WhatsApp yang dapat dihubungi.',
            ),
        ),
        'Error link unreachable',
    );
    await key('Enter');
    check(await active(phone()), 'Error anchor did not focus phone');
    results.push(
        'mixed required: native validation focus, phone-only payload, error summary Tab/Enter link',
    );

    await select('Email opsional · consent belum');
    await type(email(), 'keyboard@example.test');
    await key('Enter');
    check(
        (await count()) === 2 && (await submit().isDisabled()),
        'Unchecked consent submitted',
    );
    await toggle(psychName);
    await tabTo(dass());
    await key('Space');
    check(
        (await dass().isChecked()) && (await psych().isChecked()),
        'Native DASS acceptance changed main consent',
    );
    await tabTo(submit());
    await key('Enter');
    check(
        (await count()) === 3 &&
            (await payload()).dass.accepted === true &&
            (await payload()).psychotest.accepted === true,
        'Separate consent payload',
    );
    results.push(
        'native Space/Enter: main consent separate from mandatory DASS acceptance',
    );

    for (const reset of [
        'Ganti formKey fixture',
        'Revisi versi psikotes fixture',
        'Revisi versi DASS fixture',
    ]) {
        await button(reset);
        check(
            (await email().inputValue()) === '' &&
                !(await psych().isChecked()) &&
                !(await dass().isChecked()),
            `${reset}: not reset`,
        );
        check(await submit().isDisabled(), `${reset}: submit enabled`);
        await tabTo(email());
        await key('Enter');
        check((await count()) === 3, `${reset}: premature callback`);
        await type(email(), 'keyboard@example.test');
        await toggle(psychName);
        await tabTo(dass());
        await key('Space');
    }

    await type(email(), '');
    await tabTo(submit());
    await key('Enter');
    check(
        (await count()) === 4 &&
            JSON.stringify((await payload()).missingProfile) === '{}',
        'Reset then consent-only submit',
    );
    results.push(
        'native Enter activates formKey/both version resets; choices clear and consent-only resubmit',
    );

    const captures = [];

    for (const width of [320, 390, 1280]) {
        await page.setViewportSize({ width, height: 900 });

        for (const [name, scenario] of [
            ['optional', 'Email opsional · consent tercatat'],
            ['mixed', 'Phone wajib + email opsional'],
        ]) {
            await select(scenario);
            await page.locator('main h1').waitFor();
            const geometry = await page.evaluate(() => {
                const main = document.querySelector('main');
                const content = document.querySelector('#checkout-content');
                const card = document.querySelector('form > div > fieldset');
                const labels = [
                    ...main.querySelectorAll(
                        'h1,h2,h3,p,dt,dd,label,legend,button',
                    ),
                ].filter((element) => !element.classList.contains('sr-only'));
                const issues = [];

                for (const element of labels) {
                    const range = document.createRange();
                    range.selectNodeContents(element);
                    const lines = [...range.getClientRects()];
                    const outside = lines.some(
                        (line) =>
                            line.width &&
                            (line.left < -1 ||
                                line.right >
                                    document.documentElement.clientWidth + 1),
                    );
                    let ancestor = element;
                    let clipped = false;

                    while (ancestor && ancestor !== document.documentElement) {
                        const style = getComputedStyle(ancestor);
                        const rect = ancestor.getBoundingClientRect();
                        const clips = ['hidden', 'clip', 'auto', 'scroll'];
                        clipped ||= lines.some(
                            (line) =>
                                line.width &&
                                ((clips.includes(style.overflowX) &&
                                    (line.left < rect.left - 1 ||
                                        line.right > rect.right + 1)) ||
                                    (clips.includes(style.overflowY) &&
                                        (line.top < rect.top - 1 ||
                                            line.bottom > rect.bottom + 1))),
                        );
                        ancestor = ancestor.parentElement;
                    }

                    if (outside || clipped) {
                        issues.push({
                            text: element.textContent.trim(),
                            outside,
                            clipped,
                        });
                    }
                }

                // TEMP DIAGNOSTIC: find the widest element anywhere in the
                // document (not just <main>) to identify what's actually
                // pushing scrollWidth past clientWidth.
                let widest = null;
                let widestRight = 0;
                for (const element of document.body.querySelectorAll('*')) {
                    const rect = element.getBoundingClientRect();
                    if (rect.right > widestRight && rect.width > 0) {
                        widestRight = rect.right;
                        widest = element;
                    }
                }

                const widestInfo = widest
                    ? {
                          tag: widest.tagName,
                          text: widest.textContent?.trim().slice(0, 80),
                          className:
                              typeof widest.className === 'string'
                                  ? widest.className
                                  : null,
                          right: widestRight,
                      }
                    : null;

                return {
                    width: innerWidth,
                    clientWidth: document.documentElement.clientWidth,
                    scrollWidth: document.documentElement.scrollWidth,
                    contentPadding: getComputedStyle(content).paddingLeft,
                    headingSize: getComputedStyle(main.querySelector('h1'))
                        .fontSize,
                    cardPadding: getComputedStyle(card).paddingLeft,
                    cardRadius: getComputedStyle(card).borderRadius,
                    issues,
                    widestInfo,
                };
            });
            check(
                geometry.width === width &&
                    geometry.scrollWidth <= geometry.clientWidth &&
                    geometry.issues.length === 0,
                `${width}/${name}: overflow/clipping ${JSON.stringify(geometry)}`,
            );
            check(
                geometry.headingSize === '30px' &&
                    geometry.contentPadding ===
                        (width < 640 ? '16px' : '32px') &&
                    geometry.cardPadding === (width < 640 ? '20px' : '28px') &&
                    // app.css --radius is 0.625rem; rounded-lg uses that token.
                    geometry.cardRadius === '10px',
                `Unstyled fixture ${JSON.stringify(geometry)}`,
            );
            const screenshot = `output/playwright/checkout-combined/${width}-${name}.png`;
            await page.locator('main').screenshot({ path: screenshot });
            captures.push({ name, screenshot, ...geometry });
        }
    }

    return { keyboard: results, captures };
}

/** Inject the two existing exports so CLI can concatenate sources without imports. */
export async function verifyCombinedCheckout(page, runExisting, runOptional) {
    const origin = 'http://127.0.0.1:8012';
    const errors = [];
    const requests = [];
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await page.route('**/*', (route) => {
        const url = route.request().url();

        if (!url.startsWith(`${origin}/`) || url.startsWith(`${origin}/api/`)) {
            errors.push(`Unexpected request: ${url}`);

            return route.abort();
        }

        // No favicon in the isolated Vite fixture: explicit empty synthetic asset.
        if (url === `${origin}/favicon.ico`) {
            return route.fulfill({ status: 204, body: '' });
        }

        return route.continue();
    });
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' || message.type() === 'warning') {
            errors.push(message.text());
        }
    });
    page.on('request', (request) => {
        const url = request.url();
        requests.push(url);

        if (!url.startsWith(`${origin}/`) || url.startsWith(`${origin}/api/`)) {
            errors.push(`Unexpected request: ${url}`);
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 400) {
            errors.push(`HTTP ${response.status()}: ${response.url()}`);
        }
    });
    page.on('requestfailed', (request) => {
        errors.push(`Failed request: ${request.url()}`);
    });
    await page.goto(origin);
    const existing = await runExisting(page);
    const optional = await runOptional(page);
    const keyboardReflow = await verifyCheckoutKeyboardAndReflow(page);

    if (existing.length !== 10 || optional.length !== 9 || errors.length) {
        throw new Error(JSON.stringify({ existing, optional, errors }));
    }

    return {
        existing,
        optional,
        ...keyboardReflow,
        requests: requests.length,
        errors,
    };
}
