// Playwright CLI run-code --filename entrypoint; uses an isolated browser profile.
// See tests/Frontend/PsychologistReview/browser.test.mjs for the same guard: the CLI
// splices this file's raw content into `await (<content>)(page);`, so a trailing `;`
// after the closing `}` (which a formatting run would otherwise add) breaks the splice
// with a SyntaxError. The bare `prettier-ignore` line below (nothing else on that line,
// or Prettier won't recognize it) keeps that from happening even under a broad
// `prettier --write`, without forcing the body itself to stay semicolon-free.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8013'
    const results = []
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message)
        }
    }

    // PapiItemsRunner always shows the instructions screen first (PR #71:
    // instructions now travel through /items alongside the 90 items).
    // Every scenario below needs to get past it to reach the item flow.
    const startFlow = async () => {
        await page.goto(origin)
        const startButton = page.getByRole('button', { name: 'Mulai' })
        await startButton.waitFor()
        await startButton.click()
        await page.getByRole('radiogroup').waitFor()
    }

    // --- Instructions screen shows the real /items instructions content
    // before any item is shown (not hardcoded client text). ---
    await page.goto(origin)
    await page.getByRole('button', { name: 'Mulai' }).waitFor()
    const instructionsText = await page.evaluate(() => document.body.innerText)
    assert(
        instructionsText.includes('sejumlah pasangan pernyataan'),
        'instructions intro text from /items was not rendered',
    )
    assert(
        instructionsText.includes('Contoh cara menjawab'),
        'answer_sheet_demo label from /items was not rendered',
    )
    assert(
        (await page.getByRole('radiogroup').count()) === 0,
        'the item view must not render before Mulai is clicked',
    )
    results.push(
        'instructions screen: real /items instructions text rendered, item view not shown yet',
    )

    // --- No horizontal overflow at 320/375/1280px, both on the
    // instructions screen and the item view (Lead's 2026-09-21
    // instruction, same geometry check as ParticipantLobby's harness). ---
    for (const width of [320, 375, 1280]) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto(origin)
        await page.getByRole('button', { name: 'Mulai' }).waitFor()
        const instructionsGeometry = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            scrollWidth: Math.max(
                document.documentElement.scrollWidth,
                document.body.scrollWidth,
            ),
        }))
        assert(
            instructionsGeometry.scrollWidth === instructionsGeometry.viewport,
            `${width}/instructions: page overflow (scrollWidth ${instructionsGeometry.scrollWidth} != viewport ${instructionsGeometry.viewport})`,
        )

        await startFlow()
        const itemGeometry = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            scrollWidth: Math.max(
                document.documentElement.scrollWidth,
                document.body.scrollWidth,
            ),
        }))
        assert(
            itemGeometry.scrollWidth === itemGeometry.viewport,
            `${width}/item: page overflow (scrollWidth ${itemGeometry.scrollWidth} != viewport ${itemGeometry.viewport})`,
        )
        results.push(`${width}: no overflow (instructions and item view)`)
    }

    // --- Resume rehydration: the fixture's fetchResumeAnswers pre-answers
    // item 1 as 'a' -- the runner must render that on load, not start
    // blank (proves useResumeAnswers is really wired, not just fetched
    // and ignored). ---
    await page.setViewportSize({ width: 375, height: 900 })
    await startFlow()

    const optionA = page.getByRole('radio').first()
    const optionB = page.getByRole('radio').last()
    assert(
        (await optionA.getAttribute('aria-checked')) === 'true',
        'item 1 must render pre-selected from the resumed answer (item_no 1, value "a")',
    )
    results.push('resume rehydration: item 1 renders pre-selected from fetchResumeAnswers')

    // --- A/B choice change is recorded and reaches the autosave
    // transport (mobile width, matching the real usage). Changing item 1
    // away from its resumed value also proves this isn't just displaying
    // the resumed value once and never wiring new edits back out. ---
    await optionB.click()
    assert(
        (await optionB.getAttribute('aria-checked')) === 'true',
        'option B was not recorded as selected after clicking it',
    )
    results.push('choice recorded: clicking option B sets aria-checked=true')

    // --- Autosave flush on navigation: leaving item 1 (without waiting
    // for the 2s debounce) must immediately send item 1's new value
    // through the injected `send` transport, mapped to the real wire
    // shape {item_no, value} (Lead's 2026-09-21 review requirement). ---
    await page.getByRole('button', { name: 'Berikutnya' }).click()
    const afterFirstNav = await page.evaluate(() => window.__papiAutosaveCalls)
    const item1Batches = afterFirstNav.filter((batch) =>
        batch.some((entry) => entry.item_no === 1),
    )
    assert(
        item1Batches.length > 0,
        'changing item 1 and navigating away must flush a batch containing item_no 1',
    )
    const item1LatestValue = item1Batches[item1Batches.length - 1].find(
        (entry) => entry.item_no === 1,
    ).value
    assert(
        item1LatestValue === 'b',
        `item 1's autosaved value must be "b" after the change, got ${JSON.stringify(item1LatestValue)}`,
    )
    results.push('autosave: item 1 change reached send() as {item_no: 1, value: "b"} on Berikutnya flush')

    // --- Submit is locked while any item is unanswered. ---
    await page.getByText('Lihat ringkasan').click()
    const submitButton = page.getByRole('button', { name: 'Kirim jawaban' })
    await submitButton.waitFor()
    assert(
        await submitButton.isDisabled(),
        'submit must be locked while 89 of 90 items remain unanswered',
    )
    results.push('submit locked: disabled with 1/90 items answered')

    // --- Submit unlocks once every item is answered, and every one of
    // those 90 answers has reached the autosave transport by then (not
    // just item 1) -- the real proof that useAutosave is wired for the
    // whole runner, not only demonstrated on one item. ---
    await page.getByText('Kembali ke soal').click()
    await page.getByRole('radiogroup').waitFor()

    // "Kembali ke soal" returns to item 2 (where Berikutnya left off
    // above), not item 1 -- back up once so the fill loop below starts
    // at item 1 and actually touches all 90, not 89.
    await page.getByRole('button', { name: 'Sebelumnya' }).click()

    for (let itemIndex = 0; itemIndex < 90; itemIndex++) {
        await page.getByRole('radio').first().click()

        if (itemIndex < 89) {
            await page.getByRole('button', { name: 'Berikutnya' }).click()
        }
    }

    // The 90th item's change has no Berikutnya click after it (it's the
    // last item), so it's still sitting in the debounce window at this
    // point -- entering the summary screen is exactly what must flush it
    // (onReviewAnswers -> onAfterNavigate), same as submitting must. This
    // is checked BEFORE reading window.__papiAutosaveCalls below, so the
    // completeness assertion actually exercises that flush rather than
    // one triggered by something else.
    await page.getByText('Lihat ringkasan').click()

    const allCalls = await page.evaluate(() => window.__papiAutosaveCalls)
    const lastValueByItemNo = new Map()

    for (const batch of allCalls) {
        for (const entry of batch) {
            lastValueByItemNo.set(entry.item_no, entry.value)
        }
    }

    assert(
        lastValueByItemNo.size === 90,
        `expected all 90 items to have reached send(), got ${lastValueByItemNo.size}`,
    )

    for (let itemNo = 1; itemNo <= 90; itemNo++) {
        assert(
            lastValueByItemNo.get(itemNo) === 'a',
            `item ${itemNo}'s last autosaved value must be "a" (the first radio option), got ${JSON.stringify(lastValueByItemNo.get(itemNo))}`,
        )
    }

    results.push('autosave: all 90 items reached send() with the correct {item_no, value} shape')

    const submitAfterAll = page.getByRole('button', { name: 'Kirim jawaban' })
    await submitAfterAll.waitFor()
    assert(
        !(await submitAfterAll.isDisabled()),
        'submit must unlock once all 90 items are answered',
    )
    results.push('submit unlocked: enabled with 90/90 items answered')

    // --- Real HTTP transport scenario (Lead's 2026-09-21 instruction):
    // a second PapiItemsRunner on the same page, wired through the real
    // createHttpTransport() against a fake HTTP endpoint returning the
    // real wire shapes, proving papiItemsOutcomeFromGeneric is actually
    // exercised inside a rendered runner. Safe to run here, at the very
    // end of the script, without colliding with the section above: that
    // section has moved off its item view (radiogroup/Mulai/Sebelumnya/
    // Berikutnya) onto its ringkasan screen by this point, so this
    // section's own "Mulai" and radio options are the only ones on the
    // page when these locators run.
    const httpDemoStart = page.getByRole('button', { name: 'Mulai' })
    await httpDemoStart.waitFor()
    await httpDemoStart.click()

    const httpDemoOptionA = page.getByRole('radio').first()
    await httpDemoOptionA.waitFor()
    const httpDemoOptionText = await httpDemoOptionA.textContent()
    assert(
        httpDemoOptionText.includes('(transport HTTP)'),
        `the real-transport scenario's item text must come from the fake HTTP endpoint's response, got: ${httpDemoOptionText}`,
    )
    results.push('http transport: papiItemsOutcomeFromGeneric-mapped item text rendered from the real createHttpTransport() + fake endpoint, not the synthetic fixture data')

    await httpDemoOptionA.click()
    await page.waitForFunction(
        () =>
            window.__papiHttpTransportCalls.some(
                (call) => call.method === 'POST' && call.url.endsWith('/answers'),
            ),
        { timeout: 5000 },
    )
    results.push('http transport: selecting an answer reached autosaveSend() -> a real POST /answers call')

    const httpDemoUnauthorizedButton = page.getByRole('button', {
        name: 'Uji token kedaluwarsa (401, uji)',
    })
    await httpDemoUnauthorizedButton.click()
    await page.waitForFunction(
        () => window.__papiHttpTransportUnauthorizedFired === true,
        { timeout: 5000 },
    )
    await page.getByText('Sesi psikotes telah berakhir.').waitFor()
    results.push('http transport: a 401 fires onUnauthorized() exactly once and the page shows the session-ended message, same pattern as lobby.tsx')

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`)

    return results
}
