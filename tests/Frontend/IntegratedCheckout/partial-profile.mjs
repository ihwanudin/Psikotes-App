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
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto(origin);
    const role = (type, name) => page.getByRole(type, { name, exact: true });
    const field = (name) => page.locator(`[name="${name}"]`);
    const submit = () => role('button', 'Konfirmasi data dan persetujuan');
    const count = async () =>
        Number(await role('status', 'Jumlah konfirmasi').textContent());
    const active = (locator) =>
        locator.evaluate((node) => document.activeElement === node);
    const tabTo = async (locator) => {
        for (let step = 0; step < 70; step++) {
            if (await active(locator)) {
                return;
            }

            await page.keyboard.press('Tab');
        }

        throw new Error('Native Tab target unreachable');
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
        label: 'Profil seluruhnya missing',
    });
    const keys = [
        'fullName',
        'birthDate',
        'gender',
        'educationLevel',
        'intendedField',
        'email',
        'phone',
    ];

    for (const name of keys) {
        check(
            (await field(name).inputValue()) === '',
            `Default value inserted into ${name}`,
        );
        check(
            (await field(name).evaluate((node) => node.required)) ===
                (name !== 'email'),
            `Required metadata ${name}`,
        );
    }

    check(
        (await field('intendedField')
            .locator('option[value="UMUM"]')
            .count()) === 1,
        'Existing UMUM option absent',
    );
    await role('region', 'Identitas Anda').screenshot({
        path: 'output/playwright/partial-profile/blank.png',
    });
    results.push(
        'seven empty fields; six required; email optional; no default name/date/UMUM',
    );
    await tabTo(role('checkbox', 'Legal review pending (fixture)'));
    await key('Space');
    await tabTo(
        role(
            'checkbox',
            'Saya telah membaca dan menyetujui persetujuan psikotes utama. *',
        ),
    );
    await key('Space');
    await tabTo(role('radio', 'Saya setuju mengikuti DASS-21.'));
    await key('Space');
    await key('ArrowDown');
    check(
        await role('radio', 'Saya tidak ingin mengikuti DASS-21.').isChecked(),
        'Explicit DASS decline',
    );

    for (const [name, value] of [
        ['fullName', 'Nadia Sintetis Lengkap'],
        ['birthDate', '2000-02-12'],
        ['gender', 'female'],
        ['educationLevel', 'SMA sintetis'],
        ['intendedField', 'KAIGO'],
        ['phone', '080000000005'],
    ]) {
        await tabTo(submit());
        await key('Enter');
        check(
            (await count()) === 0 && (await active(field(name))),
            `Empty required ${name} did not block/focus`,
        );
        check(
            await field(name).evaluate((node) => node.validity.valueMissing),
            `Missing validity ${name}`,
        );
        results.push(`native required rejection and focus: ${name}`);

        if (name === 'birthDate') {
            // Chrome desktop en-US date segments; no fill() or DOM value assignment.
            await key('ArrowLeft');
            await key('ArrowLeft');
            // Two digits auto-advance month/day in this native Chrome control.
            await page.keyboard.type('02122000');
            await key('Tab');
        } else if (name === 'gender' || name === 'intendedField') {
            await key('Home');
            await key('ArrowDown');
            await key('Tab');
        } else {
            await page.keyboard.type(value);
            await key('Tab');
        }

        check(
            (await field(name).inputValue()) === value,
            `Native entry ${name}: ${await field(name).inputValue()}`,
        );
    }

    check(
        (await field('email').inputValue()) === '',
        'Optional email no longer blank',
    );
    await tabTo(submit());
    await key('Enter');
    check((await count()) === 1, 'Expected one native confirmation');
    const receipt = await role(
        'status',
        'Hasil callback simulasi',
    ).textContent();
    const payload = JSON.parse(
        receipt.slice(receipt.indexOf('{'), receipt.lastIndexOf('}') + 1),
    );
    const expected = {
        missingProfile: {
            fullName: 'Nadia Sintetis Lengkap',
            birthDate: '2000-02-12',
            gender: 'female',
            educationLevel: 'SMA sintetis',
            intendedField: 'KAIGO',
            phone: '080000000005',
        },
        psychotest: { version: 'contoh-v1:0', accepted: true },
        dass: { version: 'contoh-dass-v1:0', accepted: false },
    };
    check(
        JSON.stringify(payload) === JSON.stringify(expected),
        `Unexpected callback payload ${JSON.stringify(payload)}`,
    );
    check(
        (await role('heading', 'Akses tes belum dibuka').count()) === 1,
        'Callback opened access',
    );
    check(
        (await role('status', 'Jumlah callback pembayaran').textContent()) ===
            '0',
        'Unexpected payment callback',
    );
    await role('region', 'Identitas Anda').screenshot({
        path: 'output/playwright/partial-profile/filled.png',
    });
    results.push(
        'exact missing-only payload, blank email omitted, versioned main consent and DASS decline; access locked',
    );

    await role('combobox', 'Skenario').selectOption({
        label: 'Lembaga · menunggu',
    });
    const profile = role('region', 'Identitas Anda');
    check(
        (await profile.locator('input,select').count()) === 0 &&
            (await profile.locator('dd').count()) === 7,
        'Complete profile became editable',
    );
    check(
        (await profile.textContent()).includes(
            'Data sudah lengkap. Anda tidak perlu mendaftar ulang.',
        ),
        'Complete profile copy changed',
    );
    check(
        (await profile.textContent()).includes('Nadia Peserta Contoh'),
        'Complete profile values lost',
    );
    check(
        (await role('heading', 'Akses tes belum dibuka').count()) === 1,
        'Complete profile opened access',
    );
    results.push(
        'complete profile stays seven locked values with no registration repeat',
    );
    check(errors.length === 0, errors.join('; '));

    return {
        results,
        language: await page.evaluate(() => navigator.language),
        payload,
        errors,
    };
};
