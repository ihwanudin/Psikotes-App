// Playwright CLI run-code --filename entrypoint; uses an isolated browser profile.
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8013';
    const results = [];
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message);
        }
    };

    // --- No horizontal overflow at 320/375/1280px (Lead's 2026-09-21
    // instruction, same geometry check as ParticipantLobby's harness). ---
    for (const width of [320, 375, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(origin);
        await page.getByRole('radiogroup').waitFor();
        const geometry = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            scrollWidth: Math.max(
                document.documentElement.scrollWidth,
                document.body.scrollWidth,
            ),
        }));
        assert(
            geometry.scrollWidth === geometry.viewport,
            `${width}: page overflow (scrollWidth ${geometry.scrollWidth} != viewport ${geometry.viewport})`,
        );
        results.push(`${width}: no overflow (viewport ${geometry.viewport}px)`);
    }

    // --- A/B choice is recorded (mobile width, matching the real usage). ---
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto(origin);
    await page.getByRole('radiogroup').waitFor();

    const optionA = page.getByRole('radio').first();
    assert(
        (await optionA.getAttribute('aria-checked')) === 'false',
        'option A must start unselected',
    );
    await optionA.click();
    assert(
        (await optionA.getAttribute('aria-checked')) === 'true',
        'option A was not recorded as selected after clicking it',
    );
    results.push('choice recorded: clicking option A sets aria-checked=true');

    // --- Submit is locked while any item is unanswered. ---
    await page.getByText('Lihat ringkasan').click();
    const submitButton = page.getByRole('button', { name: 'Kirim jawaban' });
    await submitButton.waitFor();
    assert(
        await submitButton.isDisabled(),
        'submit must be locked while 89 of 90 items remain unanswered',
    );
    results.push('submit locked: disabled with 1/90 items answered');

    // --- Submit unlocks once every item is answered. ---
    await page.getByText('Kembali ke soal').click();
    await page.getByRole('radiogroup').waitFor();

    for (let itemIndex = 0; itemIndex < 90; itemIndex++) {
        await page.getByRole('radio').first().click();

        if (itemIndex < 89) {
            await page.getByRole('button', { name: 'Berikutnya' }).click();
        }
    }

    await page.getByText('Lihat ringkasan').click();
    const submitAfterAll = page.getByRole('button', { name: 'Kirim jawaban' });
    await submitAfterAll.waitFor();
    assert(
        !(await submitAfterAll.isDisabled()),
        'submit must unlock once all 90 items are answered',
    );
    results.push('submit unlocked: enabled with 90/90 items answered');

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`);

    return results;
};
