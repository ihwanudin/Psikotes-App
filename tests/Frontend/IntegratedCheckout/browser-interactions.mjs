import assert from 'node:assert/strict';

/** Run through the documented Browser skill tab API on the test-only 8011 preview.
 * Click/check/fill and programmatic requestSubmit probes are NOT native keyboard proof.
 * Native Tab/Space/arrows/Enter are audited separately with the visible trusted flag.
 */
export async function verifyCheckoutInteractions(tab) {
    assert.equal(new URL(await tab.url()).origin, 'http://127.0.0.1:8011');
    await tab.reload();
    assert.match(await tab.playwright.domSnapshot(), /PREVIEW INTERNAL/);
    const role = (type, name) =>
        tab.playwright.getByRole(type, { name, exact: true });
    const confirm = () => role('button', 'Konfirmasi data dan persetujuan');
    const mainConsent = () =>
        role(
            'checkbox',
            'Saya telah membaca dan menyetujui persetujuan psikotes utama. *',
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
    await role('radio', 'Saya tidak ingin mengikuti DASS-21.').check();
    await confirm().click();
    assert.equal(await counter(), '1');
    assert.match(
        await receipt(),
        /"dass":\{"version":"contoh-dass-v1:0","accepted":false\}/,
    );
    assert.match(
        await role('region', 'Pembayaran').innerText(),
        /Menunggu pembayaran lembaga/,
    );
    results.push(
        'explicit DASS decline confirms once without changing payment/access',
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
    await role('radio', 'Saya tidak ingin mengikuti DASS-21.').check();
    await role('button', 'Simulasikan error validasi').click();
    let snapshot = await tab.playwright.domSnapshot();
    assert.match(snapshot, /alert \[active\]/);
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
        snapshot = await tab.playwright.domSnapshot();
        assert.doesNotMatch(
            snapshot,
            /checkbox "Saya telah membaca[^\n]*\[checked\]/,
        );
        assert.doesNotMatch(snapshot, /radio "Saya[^\n]*\[checked\]/);
        assert.equal(await confirm().isEnabled(), false);
        await mainConsent().check();
        assert.equal(await confirm().isEnabled(), true);
        const previous = await counter();
        await confirm().click();
        assert.equal(
            await counter(),
            previous,
            'required phone must be empty after reset',
        );
        assert.match(
            await tab.playwright.domSnapshot(),
            /textbox "Nomor WhatsApp \*" \[active\]/,
        );
        await role('textbox', 'Nomor WhatsApp *').fill('080000000002');
        await confirm().click();
        assert.equal(Number(await counter()), Number(previous) + 1);
        assert.match(await receipt(), /"phone":"080000000002"/);
        assert.doesNotMatch(await receipt(), /"dass":/);
        await role('radio', 'Saya tidak ingin mengikuti DASS-21.').check();
        results.push(
            `${reset}: profile and both choices reset; null DASS omitted after refill`,
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
