// Playwright CLI run-code --filename entrypoint; uses an isolated browser profile.
// See tests/Frontend/PsychologistReview/browser.test.mjs (and PapiRunner's/RmibRunner's
// own copies of this same note) for the guard below: the CLI splices this file's raw
// content into `await (<content>)(page);`, so a trailing `;` after the closing `}`
// (which a formatting run would otherwise add) breaks the splice with a SyntaxError.
// The bare `prettier-ignore` line (nothing else on that line, or Prettier won't
// recognize it) keeps that from happening even under a broad `prettier --write`.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8017'
    const results = []
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message)
        }
    }

    await page.goto(origin)
    await page.getByRole('radiogroup').waitFor()

    // --- Resume rehydration: item 1 (SE) is pre-answered 'b' by the
    // fixture's fetchResumeAnswers -- must render pre-selected on load. ---
    const optionB = page.getByRole('radio').nth(1)
    assert(
        (await optionB.getAttribute('aria-checked')) === 'true',
        'item 1 must render pre-selected from the resumed answer (item_no 1, value "b")',
    )
    results.push('resume rehydration: SE item 1 renders pre-selected from fetchResumeAnswers')

    // --- remainingSeconds is displayed exactly as given, static. ---
    const bodyText = await page.evaluate(() => document.body.innerText)
    assert(
        bodyText.includes('Sisa waktu: 30:00'),
        'remainingSeconds (1800) must be shown as a static 30:00, not computed',
    )
    results.push('remaining_seconds: shown exactly as given by the caller')

    // --- No horizontal overflow at 320/375/1280px. ---
    for (const width of [320, 375, 1280]) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto(origin)
        await page.getByRole('radiogroup').waitFor()
        const geometry = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            scrollWidth: Math.max(
                document.documentElement.scrollWidth,
                document.body.scrollWidth,
            ),
        }))
        assert(
            geometry.scrollWidth === geometry.viewport,
            `${width}: page overflow (scrollWidth ${geometry.scrollWidth} != viewport ${geometry.viewport})`,
        )
        results.push(`${width}: no overflow`)
    }

    await page.setViewportSize({ width: 375, height: 900 })
    await page.goto(origin)
    await page.getByRole('radiogroup').waitFor()

    // --- Changing an answer and navigating away flushes autosave with
    // the correct {item_no, value} shape, immediately (not waiting for
    // the 2s debounce). ---
    const optionC = page.getByRole('radio').nth(2)
    await optionC.click()
    assert(
        (await optionC.getAttribute('aria-checked')) === 'true',
        'option c was not recorded as selected after clicking it',
    )

    await page.getByRole('button', { name: 'Berikutnya' }).click()
    const afterFirstNav = await page.evaluate(() => window.__istAutosaveCalls)
    const item1Batches = afterFirstNav.filter((batch) =>
        batch.some((entry) => entry.item_no === 1),
    )
    assert(item1Batches.length > 0, 'changing item 1 and navigating away must flush a batch')
    const item1Value = item1Batches[item1Batches.length - 1].find(
        (entry) => entry.item_no === 1,
    ).value
    assert(
        item1Value === 'c',
        `item 1's autosaved value must be "c", got ${JSON.stringify(item1Value)}`,
    )
    results.push('autosave: multiple_choice change reached send() as {item_no: 1, value: "c"}')

    // --- Fill the rest, reach the summary, confirm the lock/unlock. ---
    await page.getByRole('radio').first().click() // item 2
    await page.getByRole('button', { name: 'Berikutnya' }).click()
    await page.getByRole('radio').first().click() // item 3

    await page.getByText('Lihat ringkasan').click()
    const completeButton = page.getByRole('button', { name: 'Selesai' })
    await completeButton.waitFor()
    assert(!(await completeButton.isDisabled()), 'submit must unlock once all 3 SE items are answered')
    results.push('submit unlocked: enabled once every item in the subtest is answered')

    await completeButton.click()
    const completed = await page.evaluate(() => window.__istCompletedSubtests)
    assert(completed.includes('SE'), 'onComplete must fire with the subtest complete')
    results.push('onComplete: fires once the subtest is fully answered')

    // --- Switch to the fill-in subtest (GE): free-text answers autosave
    // with the correct item numbers (61+), proving the fill-in path
    // independently of multiple_choice. ---
    await page.getByRole('button', { name: /Ganti ke subtes GE/ }).click()
    const fillInInput = page.locator('#ist-fill-in-1')
    await fillInInput.waitFor()
    await fillInInput.fill('bunga')

    await page.getByRole('button', { name: 'Berikutnya' }).click()
    const afterGeNav = await page.evaluate(() => window.__istAutosaveCalls)
    const item61Batches = afterGeNav.filter((batch) =>
        batch.some((entry) => entry.item_no === 61),
    )
    assert(item61Batches.length > 0, 'a fill-in answer must also flush on navigation')
    const item61Value = item61Batches[item61Batches.length - 1].find(
        (entry) => entry.item_no === 61,
    ).value
    assert(
        item61Value === 'bunga',
        `item 61's autosaved value must be "bunga", got ${JSON.stringify(item61Value)}`,
    )
    results.push('autosave: fill_in_word change reached send() as {item_no: 61, value: "bunga"}')

    // --- IstAssetImage: a real onError (a genuinely broken first URL,
    // 404 from this fixture's own dev server) triggers a real reload(),
    // which then succeeds -- proving the whole path in a real browser,
    // not just the pure loader logic. ---
    await page.waitForFunction(
        () => {
            const img = document.querySelector('img[alt="Contoh gambar aset"]')

            return img !== null && img.getAttribute('src')?.startsWith('data:image/svg+xml')
        },
        { timeout: 10000 },
    )
    results.push('IstAssetImage: a broken first URL (real 404) triggers onError -> reload() -> the second URL renders')

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`)

    return results
}
