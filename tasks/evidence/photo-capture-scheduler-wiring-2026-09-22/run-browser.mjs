// Playwright CLI run-code entrypoint: proves the photo-capture scheduler
// (PR #87, photo-capture-scheduler.ts) is actually wired to the live
// camera (PR #91's captureFrame()/reactivation) through
// use-periodic-photo-capture.ts and SessionRunnerShell, per Lead's ask
// (2026-09-22): the scheduler must be genuinely running only while
// camera.status === 'active', must stop when the camera goes away, and
// captureFrame() must actually be called with the caller-injected
// size/quality (321x241, 0.42 in this fixture -- deliberately not the
// 480x360/0.6 pair the manual capture buttons use), not embedded
// constants. Runs against tests/Frontend/CameraCapture/ (a real
// MediaStream from canvas.captureStream(), no camera hardware needed).
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression.
async (page) => {
    const origin = 'http://127.0.0.1:8099';
    const evidenceDir = 'D:/LSI/Web/Psikotes-worktrees/fe-proctoring-photo-capture-wiring/tasks/evidence/photo-capture-scheduler-wiring-2026-09-22';
    const checks = [];
    const check = (name, passed, detail = '') => {
        checks.push({ name, passed: Boolean(passed), detail });
    };

    const logCount = async () => {
        const text = await page
            .getByLabel('Log capture periodik')
            .getAttribute('data-periodic-log-count');

        return Number(text);
    };
    const logEntries = async () =>
        page.getByLabel('Log capture periodik').locator('li').allTextContents();

    await page.setViewportSize({ width: 1024, height: 900 });
    await page.goto(origin);

    // Before activation: scheduler must not be running and nothing must
    // have been captured -- constructing SessionRunnerShell alone must
    // never start anything (same "mounting never acts" guarantee every
    // other proctoring piece in this codebase makes).
    check(
        'Before camera activation: photo-capture log is empty',
        (await logCount()) === 0,
    );
    check(
        'Before camera activation: scheduler status reads BERHENTI',
        (await page.getByText('Penjadwal foto periodik:').textContent()).includes('BERHENTI'),
    );

    await page.getByRole('button', { name: 'Aktifkan kamera' }).click();
    await page.getByText('Status kamera: active', { exact: true }).waitFor();

    // Give the scheduler time to fire at least 2 periodic ticks (fixture
    // interval is a randomized 1.5-2s, so ~4.5s covers 2 ticks with
    // margin) and re-render (isRunning() display is read at render time,
    // not itself reactive -- a capture landing forces a fresh render).
    await page.waitForTimeout(4500);

    const countWhileActive = await logCount();
    check(
        'While camera active: scheduler ran at least 2 periodic captures',
        countWhileActive >= 2,
        `count=${countWhileActive}`,
    );
    check(
        'While camera active: scheduler status reads AKTIF',
        (await page.getByText('Penjadwal foto periodik:').textContent()).includes('AKTIF'),
    );

    const entriesWhileActive = await logEntries();
    const sizePattern = /periodic: 321x181, type=image\/jpeg/;
    check(
        'Every periodic capture used the injected 321x241 bound (fit to 321x181, not the manual buttons\' 480x360/100x100) and image/jpeg type',
        entriesWhileActive.length > 0 && entriesWhileActive.every((line) => sizePattern.test(line)),
        JSON.stringify(entriesWhileActive),
    );

    // Prove the manual capture path (still 480x360, unrelated config)
    // produces a DIFFERENT size than the periodic scheduler's captures,
    // showing the two aren't secretly sharing one hardcoded value.
    await page.getByRole('button', { name: 'Capture (480x360)' }).click();
    await page.getByLabel('Hasil capture').getByText(/Hasil: \d+x\d+/).waitFor();
    const manualResult = await page.getByLabel('Hasil capture').textContent();
    check(
        'Manual capture button (480x360 bound) produces 480x270, distinct from the periodic scheduler\'s 321x181',
        Boolean(manualResult && manualResult.includes('480x270')),
        manualResult ?? '',
    );

    // Deactivate the camera and confirm the scheduler actually stops --
    // not just "stops capturing successfully" but genuinely halts, so
    // no further log entries appear even after waiting past another
    // full interval.
    await page.getByRole('button', {
        name: 'Putuskan kamera (simulasikan track berakhir)',
    }).click();
    await page.getByText('Status kamera: interrupted', { exact: true }).waitFor();

    const countAtInterruption = await logCount();
    await page.waitForTimeout(3500);
    const countAfterWaitWhileInterrupted = await logCount();

    check(
        'After the camera is interrupted: scheduler status reads BERHENTI',
        (await page.getByText('Penjadwal foto periodik:').textContent()).includes('BERHENTI'),
    );
    check(
        'After the camera is interrupted: no new periodic captures happen even after waiting past a full interval',
        countAfterWaitWhileInterrupted === countAtInterruption,
        `at_interruption=${countAtInterruption} after_wait=${countAfterWaitWhileInterrupted}`,
    );

    // Reactivate (focus event -> automatic reactivate(), per PR #91).
    // This fixture's scripted getUserMedia fails on its 2nd call
    // (simulating a busy device), so the first focus lands on
    // reactivation_failed, not active -- an extra chance to prove the
    // scheduler stays off through that status too, not just
    // 'interrupted'.
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.getByText('Status kamera: reactivation_failed', { exact: true }).waitFor();
    await page.waitForTimeout(3500);
    const countAfterFailedReactivation = await logCount();

    check(
        'After a failed reactivation attempt (reactivation_failed): scheduler status still reads BERHENTI and no new captures happen',
        (await page.getByText('Penjadwal foto periodik:').textContent()).includes('BERHENTI')
            && countAfterFailedReactivation === countAfterWaitWhileInterrupted,
        `after_wait_interrupted=${countAfterWaitWhileInterrupted} after_failed_reactivation=${countAfterFailedReactivation}`,
    );

    // A second focus event retries from reactivation_failed (this
    // fixture's 3rd scripted getUserMedia call succeeds) and should
    // resume the scheduler automatically, with no extra wiring needed
    // beyond tracking camera.status.
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.getByText('Status kamera: active', { exact: true }).waitFor();
    await page.waitForTimeout(4500);
    const countAfterResume = await logCount();

    check(
        'After the camera successfully reactivates: the scheduler resumes on its own and captures more photos',
        countAfterResume > countAfterFailedReactivation,
        `after_failed_reactivation=${countAfterFailedReactivation} after_resume=${countAfterResume}`,
    );

    await page.screenshot({
        path: `${evidenceDir}/photo-capture-scheduler-wiring.png`,
        fullPage: true,
    });

    const result = { checks, countWhileActive, countAtInterruption, countAfterWaitWhileInterrupted, countAfterFailedReactivation, countAfterResume, entriesWhileActive };
    await page.evaluate((payload) => {
        localStorage.setItem('photo-capture-scheduler-wiring-result', JSON.stringify(payload));
    }, result);

    return result;
}
