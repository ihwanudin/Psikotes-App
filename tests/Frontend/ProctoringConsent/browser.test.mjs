// Playwright CLI run-code --filename entrypoint; uses an isolated browser profile.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    // Playwright CLI's run-code sandbox does not expose `process`, so this
    // can't read PROCTORING_CONSENT_FIXTURE_PORT (see vite.config.ts) the
    // way an orchestrator process could. Running the dev server on a
    // non-default port (e.g. because 8015 collides with another concurrent
    // session, as ParticipantLobby's 8011 and IntegratedCheckout's 8012
    // sometimes do) means editing this literal to match.
    const origin = 'http://127.0.0.1:8015';
    const results = [];
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message);
        }
    };

    const notice = () => page.getByRole('alert');
    const primaryButton = () =>
        page.getByRole('button', { name: /Izinkan kamera|Coba lagi/ });
    const secondaryButton = () =>
        page.getByRole('button', { name: 'Lanjutkan tanpa kamera' });
    const proceededCount = async () =>
        Number(await page.getByLabel('Jumlah proceed').textContent());

    async function setScenario({ outcome, cameraMandatory }) {
        await page.getByLabel('Hasil kamera simulasi').selectOption(outcome);
        await page
            .getByLabel('Kamera wajib (cameraMandatory)')
            .setChecked(cameraMandatory);
    }

    await page.goto(origin);
    await page
        .getByRole('heading', {
            name: 'Sebelum memulai: persetujuan pemantauan',
        })
        .waitFor();

    // granted + mandatory: no notice up front, primary action activates
    // the camera and proceeds exactly once (regression coverage for the
    // onProceed-identity infinite-loop bug this session found and fixed
    // in proctoring-consent-screen.tsx).
    await setScenario({ outcome: 'granted', cameraMandatory: true });
    assert(
        (await notice().count()) === 0,
        'granted: unexpected notice before starting',
    );
    assert(
        (await proceededCount()) === 0,
        'granted: proceeded before starting',
    );
    await primaryButton().click();
    await page.waitForFunction(
        () =>
            document.querySelector('[aria-label="Jumlah proceed"]')
                ?.textContent === '1',
    );
    assert(
        (await proceededCount()) === 1,
        'granted: onProceed did not fire exactly once',
    );
    results.push('granted + mandatory: PASS (no notice, proceeds once)');

    // denied + mandatory: factual notice, no accusation, no way to skip.
    await setScenario({ outcome: 'denied', cameraMandatory: true });
    await primaryButton().click();
    await notice().waitFor();
    const deniedHeading = await notice().locator('p').first().textContent();
    const deniedBody = await notice().locator('p').nth(1).textContent();
    assert(
        !deniedHeading.includes('Anda menolak'),
        'denied: heading blames the participant',
    );
    assert(
        deniedBody.includes('address bar'),
        'denied: missing desktop guidance',
    );
    assert(deniedBody.includes('HP'), 'denied: missing mobile guidance');
    assert(
        (await secondaryButton().count()) === 0,
        'denied + mandatory: unexpected "proceed without camera" action',
    );
    assert(
        (await primaryButton().textContent()) === 'Coba lagi',
        'denied: primary action label did not switch to retry',
    );
    results.push('denied + mandatory: PASS (factual notice, no skip path)');

    // unavailable + mandatory: distinct non-accusatory heading, device
    // guidance, and — the specific regression this fixture exists to
    // guard — no invented LPK/alternative-supervision pathway, only a
    // neutral pointer to the test organizer (product owner correction,
    // 2026-09-21).
    await setScenario({ outcome: 'unavailable', cameraMandatory: true });
    await primaryButton().click();
    await notice().waitFor();
    const unavailableHeading = await notice()
        .locator('p')
        .first()
        .textContent();
    const unavailableBody = await notice().locator('p').nth(1).textContent();
    assert(
        unavailableHeading.includes('bukan berarti Anda menolak'),
        'unavailable: missing the "not a refusal" reassurance',
    );
    assert(
        unavailableHeading !== deniedHeading,
        'unavailable: heading identical to denied — a broken camera must not read like a refusal',
    );
    assert(
        unavailableBody.includes('Tutup aplikasi lain'),
        'unavailable: missing device-check guidance',
    );
    assert(
        unavailableBody.includes('perangkat lain'),
        'unavailable: missing device-replacement guidance',
    );
    assert(
        unavailableBody.includes('penyelenggara tes'),
        'unavailable: missing the neutral test-organizer pointer',
    );
    assert(
        !unavailableBody.includes('LPK'),
        'unavailable: regressed — reintroduced the retracted LPK/alternative-supervision claim',
    );
    assert(
        (await secondaryButton().count()) === 0,
        'unavailable + mandatory: unexpected "proceed without camera" action',
    );
    results.push(
        'unavailable + mandatory: PASS (no LPK claim, neutral organizer pointer)',
    );

    // cameraMandatory: false offers an explicit way to proceed without a
    // camera, and clicking it actually proceeds without ever granting one.
    await setScenario({ outcome: 'unavailable', cameraMandatory: false });
    await primaryButton().click();
    await notice().waitFor();
    assert(
        (await proceededCount()) === 0,
        'optional: proceeded before clicking the skip action',
    );
    await secondaryButton().click();
    await page.waitForFunction(
        () =>
            document.querySelector('[aria-label="Jumlah proceed"]')
                ?.textContent === '1',
    );
    assert(
        (await proceededCount()) === 1,
        'optional: skip action did not proceed',
    );
    results.push(
        'cameraMandatory: false: PASS (explicit skip action proceeds once)',
    );

    // The honesty note (detection, not prevention) is present regardless
    // of scenario — spot-check on whatever scenario is currently mounted.
    const honestyText = await page
        .locator('p', { hasText: 'mendeteksi dan mencatat' })
        .textContent();
    assert(
        honestyText.includes('bukan mencegah'),
        'honesty note missing "not prevention"',
    );
    results.push(
        'honesty note: PASS (present, states detection not prevention)',
    );

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`);

    return results;
}
