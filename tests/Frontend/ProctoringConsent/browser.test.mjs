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
    // Camera is mandatory for every participant, no exception (product
    // owner decision, PR #81 item 11) — there is no "proceed without a
    // camera" action anywhere on this screen. Checked after every
    // scenario below, not just once, since a regression could plausibly
    // reintroduce it for only one camera status.
    const assertNoSkipAction = async (scenarioLabel) => {
        assert(
            (await page.getByRole('button', { name: /tanpa kamera/i }).count()) ===
                0,
            `${scenarioLabel}: unexpected "proceed without camera" action`,
        );
    };
    const proceededCount = async () =>
        Number(await page.getByLabel('Jumlah proceed').textContent());

    async function setScenario({ outcome }) {
        await page.getByLabel('Hasil kamera simulasi').selectOption(outcome);
    }

    await page.goto(origin);
    await page
        .getByRole('heading', {
            name: 'Sebelum memulai: persetujuan pemantauan',
        })
        .waitFor();

    // granted: no notice up front, primary action activates the camera
    // and proceeds exactly once (regression coverage for the
    // onProceed-identity infinite-loop bug this session found and fixed
    // in proctoring-consent-screen.tsx).
    await setScenario({ outcome: 'granted' });
    assert(
        (await notice().count()) === 0,
        'granted: unexpected notice before starting',
    );
    assert(
        (await proceededCount()) === 0,
        'granted: proceeded before starting',
    );
    await assertNoSkipAction('granted');
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
    results.push('granted: PASS (no notice, proceeds once)');

    // denied: factual notice, no accusation, no way to skip.
    await setScenario({ outcome: 'denied' });
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
    await assertNoSkipAction('denied');
    assert(
        (await primaryButton().textContent()) === 'Coba lagi',
        'denied: primary action label did not switch to retry',
    );
    results.push('denied: PASS (factual notice, no skip path)');

    // unavailable: distinct non-accusatory heading, device guidance, and
    // — the specific regression this fixture exists to guard — no
    // invented LPK/alternative-supervision pathway, only a neutral
    // pointer to the test organizer (product owner correction,
    // 2026-09-21).
    await setScenario({ outcome: 'unavailable' });
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
    await assertNoSkipAction('unavailable');
    results.push('unavailable: PASS (no LPK claim, neutral organizer pointer)');

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
