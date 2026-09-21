// Portable assertions keep the same cases callable from Playwright CLI run-code.
const assert = {
    equal(actual, expected, message = 'Values differ') {
        if (actual !== expected) {
            throw new Error(`${message}: ${actual} !== ${expected}`);
        }
    },
    match(actual, pattern) {
        if (!pattern.test(actual)) {
            throw new Error(`Expected ${pattern}: ${actual}`);
        }
    },
    doesNotMatch(actual, pattern) {
        if (pattern.test(actual)) {
            throw new Error(`Unexpected ${pattern}: ${actual}`);
        }
    },
};

/** Mounted Playwright CLI check on the existing synthetic preview; no backend. */
export async function verifyOptionalEmail(page) {
    const origin = 'http://127.0.0.1:8011';
    const results = [];
    const errors = [];
    const check = (condition, message) => {
        if (!condition) {
            throw new Error(message);
        }
    };
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await page.route('**/*', (route) => {
        if (
            !route.request().url().startsWith(`${origin}/`) ||
            route.request().url().startsWith(`${origin}/api/`)
        ) {
            errors.push('Unexpected external/API request');

            return route.abort();
        }

        if (route.request().url() === `${origin}/favicon.ico`) {
            return route.fulfill({ status: 204, body: '' });
        }

        return route.continue();
    });
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    await page.goto(origin);
    const role = (type, name) => page.getByRole(type, { name, exact: true });
    const email = () => role('textbox', 'Email (opsional)');
    const submit = () => role('button', 'Konfirmasi data dan persetujuan');
    const count = async () =>
        Number(await role('status', 'Jumlah konfirmasi').textContent());
    const probe = () =>
        role('button', 'Uji handler requestSubmit (bukan keyboard)').click();
    const select = (label) =>
        role('combobox', 'Skenario').selectOption({ label });
    const payload = async () => {
        const receipt = await role(
            'status',
            'Hasil callback simulasi',
        ).textContent();

        return JSON.parse(
            receipt.slice(receipt.indexOf('{'), receipt.lastIndexOf('}') + 1),
        );
    };
    await select('Email opsional · consent tercatat');
    await role('checkbox', 'Legal review pending (fixture)').uncheck();
    check(
        (
            await page
                .getByRole('region', { name: 'Identitas Anda' })
                .textContent()
        ).includes('Data wajib sudah lengkap'),
        'Optional-only copy',
    );
    check(
        (await email().count()) === 1 && (await submit().count()) === 0,
        'Keep optional input without mandatory submit',
    );
    await probe();
    check(
        (await count()) === 0,
        'Empty optional-only handler must not confirm',
    );
    results.push(
        'optional blank: correct copy, input retained, no CTA/empty callback',
    );

    await email().fill('contoh@example.test');
    await submit().click();
    check((await count()) === 1, 'Optional email callback');
    check(
        JSON.stringify(await payload()) ===
            JSON.stringify({
                missingProfile: { email: 'contoh@example.test' },
            }),
        'Email-only payload',
    );
    await email().fill('');
    await probe();
    await email().fill('   ');
    await probe();
    check(
        (await submit().count()) === 0 && (await count()) === 1,
        'Cleared/whitespace optional must not update',
    );
    results.push(
        'typed email only, then empty/whitespace cannot send empty update',
    );

    await email().fill('not-an-email');
    await submit().click();
    check((await count()) === 1, 'Invalid native email must not call callback');
    check(
        await email().evaluate(
            (input) =>
                input.validity.typeMismatch && document.activeElement === input,
        ),
        'Native email validity and focus',
    );
    results.push(
        'invalid type=email: native validation prevents callback and focuses input',
    );

    await email().fill('contoh@example.test');

    for (const [name, blockedValue] of [
        ['Sedang menyimpan', true],
        ['Callback simulasi', false],
    ]) {
        await role('checkbox', name).setChecked(blockedValue);
        check(
            await role(
                'button',
                name === 'Sedang menyimpan'
                    ? 'Menyimpan…'
                    : 'Konfirmasi data dan persetujuan',
            ).isDisabled(),
            `${name}: CTA guard`,
        );
        await probe();
        check((await count()) === 1, `${name}: handler guard`);
        await role('checkbox', name).setChecked(!blockedValue);
    }

    await role('checkbox', 'Legal review pending (fixture)').check();
    await email().fill('contoh@example.test');
    check(await submit().isDisabled(), 'Legal pending CTA');
    await probe();
    check((await count()) === 1, 'Legal pending handler');
    await role('checkbox', 'Legal review pending (fixture)').uncheck();
    results.push(
        'busy, callback absent, legal pending: UI and handler remain guarded',
    );

    await email().fill('contoh@example.test');
    await role('button', 'Ganti formKey fixture').click();
    check(
        (await email().inputValue()) === '' && (await submit().count()) === 0,
        'formKey clears optional edit',
    );
    await probe();
    check((await count()) === 1, 'formKey reset has no empty callback');
    results.push('formKey reset clears optional edit and removes submit');

    await select('Phone wajib + email opsional');
    check(
        (
            await page
                .getByRole('region', { name: 'Identitas Anda' })
                .textContent()
        ).includes('Lengkapi hanya data'),
        'Required-missing copy',
    );
    await submit().click();
    check((await count()) === 1, 'Native required phone blocks');
    await role('textbox', 'Nomor WhatsApp *').fill('080000000001');
    await email().fill('   ');
    await submit().click();
    check((await count()) === 2, 'Required profile callback');
    check(
        JSON.stringify(await payload()) ===
            JSON.stringify({ missingProfile: { phone: '080000000001' } }),
        'Empty optional omitted in mixed payload',
    );
    results.push(
        'mixed required/optional: phone required and blank email omitted',
    );

    await select('Email opsional · consent belum');
    await email().fill('contoh@example.test');
    check(await submit().isDisabled(), 'Psychotest consent still required');
    await probe();
    check((await count()) === 2, 'Consent handler guard');
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
    await psych().check();
    await email().fill('');
    check(await submit().isDisabled(), 'DASS consent still required');
    await probe();
    check((await count()) === 2, 'DASS handler guard');
    await dass().check();
    await submit().click();
    check((await count()) === 3, 'Consent-only confirmation remains possible');
    check(
        JSON.stringify(await payload()) ===
            JSON.stringify({
                missingProfile: {},
                psychotest: { version: 'contoh-v1:0', accepted: true },
                dass: { version: 'contoh-dass-v1:0', accepted: true },
            }),
        'Blank email omitted; both mandatory consents accepted',
    );
    results.push('consent confirmation requires both mandatory acceptances');

    for (const revision of [
        'Revisi versi psikotes fixture',
        'Revisi versi DASS fixture',
    ]) {
        await email().fill('contoh@example.test');
        await dass().check();
        await role('button', revision).click();
        check(
            (await email().inputValue()) === '' && !(await psych().isChecked()),
            `${revision}: reset fields/consent`,
        );
        check(!(await dass().isChecked()), `${revision}: reset DASS`);
        check(await submit().isDisabled(), `${revision}: still guarded`);
        await probe();
        check((await count()) === 3, `${revision}: no callback`);
        await psych().check();
        await dass().check();
    }

    await submit().click();
    check(
        (await count()) === 4 && (await payload()).dass.accepted === true,
        'DASS mandatory acceptance preserved',
    );
    results.push(
        'both consent version resets preserved; DASS acceptance remains mandatory and separate',
    );

    await select('Mandiri · pending');
    await role('button', 'Lanjutkan pembayaran yang sama').click();
    check(
        (
            await role('status', 'Hasil callback simulasi').textContent()
        ).includes('callback pembayaran'),
        'Payment callback unchanged',
    );
    await role('button', 'Periksa status').click();
    check(
        (
            await role('status', 'Hasil callback simulasi').textContent()
        ).includes('callback periksa status'),
        'Refresh callback unchanged',
    );
    check(
        (await role('region', 'Pembayaran').textContent()).includes(
            'Menunggu pembayaran Anda',
        ),
        'Payment status unchanged',
    );
    check(
        (await page
            .getByRole('heading', { name: 'Akses tes belum dibuka' })
            .count()) === 1,
        'Access unchanged',
    );
    results.push(
        'payment and refresh callbacks preserve server payment/access state',
    );
    check(errors.length === 0, errors.join('; '));

    return results;
}

/** Run the original ten groups through Playwright Page on the test-only 8011 preview.
 * Click/check/fill and programmatic requestSubmit probes are NOT native keyboard proof.
 * Native Tab/Space/arrows/Enter are audited separately with the visible trusted flag.
 */
export async function verifyCheckoutInteractions(page) {
    assert.equal(
        page.url().split('/').slice(0, 3).join('/'),
        'http://127.0.0.1:8011',
    );
    await page.reload();
    assert.match(await page.locator('body').ariaSnapshot(), /PREVIEW INTERNAL/);
    const role = (type, name) => page.getByRole(type, { name, exact: true });
    const confirm = () => role('button', 'Konfirmasi data dan persetujuan');
    const mainConsent = () =>
        role(
            'checkbox',
            'Saya telah membaca dan menyetujui persetujuan psikotes utama. *',
        );
    const dassConsent = () =>
        role(
            'checkbox',
            'Saya telah membaca dan menyetujui persetujuan DASS-21. *',
        );
    const counter = () => role('status', 'Jumlah konfirmasi').innerText();
    const receipt = () => role('status', 'Hasil callback simulasi').innerText();
    const probe = () =>
        role('button', 'Uji handler requestSubmit (bukan keyboard)').click();
    const results = [];

    await mainConsent().check();
    assert.equal(await confirm().isEnabled(), false);
    await probe();
    assert.equal(await counter(), '0');
    results.push(
        'pending legal review blocks both CTA and requestSubmit handler',
    );

    await role('checkbox', 'Legal review pending (fixture)').uncheck();
    await mainConsent().check();
    await dassConsent().check();
    await confirm().click();
    assert.equal(await counter(), '1');
    assert.match(
        await receipt(),
        /"dass":\{"version":"contoh-dass-v1:0","accepted":true\}/,
    );
    assert.match(
        await role('region', 'Pembayaran').innerText(),
        /Menunggu pembayaran lembaga/,
    );
    results.push(
        'mandatory DASS acceptance confirms once without changing payment/access',
    );

    await role('checkbox', 'Sedang menyimpan').check();
    assert.equal(await role('button', 'Menyimpan…').isEnabled(), false);
    await probe();
    assert.equal(await counter(), '1');
    await role('checkbox', 'Sedang menyimpan').uncheck();
    results.push('busy blocks CTA and requestSubmit handler');

    await role('checkbox', 'Callback simulasi').uncheck();
    assert.equal(await confirm().isEnabled(), false);
    await probe();
    assert.equal(await counter(), '1');
    await role('checkbox', 'Callback simulasi').check();
    results.push('missing callback stays disabled and cannot confirm');

    await role('checkbox', 'Legal review pending (fixture)').check();
    await mainConsent().check();
    await probe();
    assert.equal(await counter(), '1');
    assert.equal(await confirm().isEnabled(), false);
    await role('checkbox', 'Legal review pending (fixture)').uncheck();
    results.push(
        'return to legal pending blocks again after a prior confirmation',
    );

    await role('combobox', 'Skenario').selectOption({
        label: 'Mandiri · profil kurang',
    });
    await role('textbox', 'Nomor WhatsApp *').fill('080000000001');
    await mainConsent().check();
    await dassConsent().check();
    await role('button', 'Simulasikan error validasi').click();
    let snapshot = await page.locator('body').ariaSnapshot();
    assert.equal(
        await role('alert', '').evaluate(
            (element) => document.activeElement === element,
        ),
        true,
    );
    assert.match(
        snapshot,
        /link "Nomor WhatsApp: Gunakan nomor WhatsApp yang dapat dihubungi\./,
    );
    assert.equal(
        await role('textbox', 'Nomor WhatsApp *').getAttribute('aria-invalid'),
        'true',
    );
    await confirm().click();
    assert.equal(await counter(), '2');
    assert.match(
        await receipt(),
        /"missingProfile":\{"phone":"080000000001"\}/,
    );
    assert.doesNotMatch(
        await receipt(),
        /"(?:branch|payer|amount|paid|ready)":/,
    );
    results.push(
        'validation focuses summary; input retained and callback projects only missing profile',
    );

    for (const reset of [
        'Ganti formKey fixture',
        'Revisi versi psikotes fixture',
        'Revisi versi DASS fixture',
    ]) {
        await role('button', reset).click();
        snapshot = await page.locator('body').ariaSnapshot();
        assert.doesNotMatch(
            snapshot,
            /checkbox "Saya telah membaca[^\n]*\[checked\]/,
        );
        assert.equal(await confirm().isEnabled(), false);
        await mainConsent().check();
        assert.equal(await confirm().isEnabled(), false);
        await dassConsent().check();
        const previous = await counter();
        await confirm().click();
        assert.equal(
            await counter(),
            previous,
            'required phone must be empty after reset',
        );
        assert.equal(
            await role('textbox', 'Nomor WhatsApp *').evaluate(
                (element) => document.activeElement === element,
            ),
            true,
        );
        await role('textbox', 'Nomor WhatsApp *').fill('080000000002');
        // Both consents were already accepted above (needed to make the
        // native-validation click at :424 reach the browser instead of
        // hanging on a disabled button); the phone number is now the only
        // previously-missing requirement, so confirm is enabled here.
        assert.equal(await confirm().isEnabled(), true);
        await dassConsent().check();
        await confirm().click();
        assert.equal(Number(await counter()), Number(previous) + 1);
        assert.match(await receipt(), /"phone":"080000000002"/);
        assert.match(
            await receipt(),
            /"dass":\{"version":"contoh-dass-v1:[0-9]+","accepted":true\}/,
        );
        results.push(
            `${reset}: profile and both mandatory consents reset; DASS must be accepted again`,
        );
    }

    await role('combobox', 'Skenario').selectOption({
        label: 'Mandiri · pending',
    });
    await role('button', 'Lanjutkan pembayaran yang sama').click();
    assert.match(await receipt(), /callback pembayaran dipanggil/);
    assert.match(
        await role('region', 'Pembayaran').innerText(),
        /Menunggu pembayaran Anda/,
    );
    results.push('self pending payment callback preserves pending status');

    return results;
}
