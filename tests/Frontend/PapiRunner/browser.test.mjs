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

    // --- A/B choice is recorded (mobile width, matching the real usage). ---
    await page.setViewportSize({ width: 375, height: 900 })
    await startFlow()

    const optionA = page.getByRole('radio').first()
    assert(
        (await optionA.getAttribute('aria-checked')) === 'false',
        'option A must start unselected',
    )
    await optionA.click()
    assert(
        (await optionA.getAttribute('aria-checked')) === 'true',
        'option A was not recorded as selected after clicking it',
    )
    results.push('choice recorded: clicking option A sets aria-checked=true')

    // --- Submit is locked while any item is unanswered. ---
    await page.getByText('Lihat ringkasan').click()
    const submitButton = page.getByRole('button', { name: 'Kirim jawaban' })
    await submitButton.waitFor()
    assert(
        await submitButton.isDisabled(),
        'submit must be locked while 89 of 90 items remain unanswered',
    )
    results.push('submit locked: disabled with 1/90 items answered')

    // --- Submit unlocks once every item is answered. ---
    await page.getByText('Kembali ke soal').click()
    await page.getByRole('radiogroup').waitFor()

    for (let itemIndex = 0; itemIndex < 90; itemIndex++) {
        await page.getByRole('radio').first().click()

        if (itemIndex < 89) {
            await page.getByRole('button', { name: 'Berikutnya' }).click()
        }
    }

    await page.getByText('Lihat ringkasan').click()
    const submitAfterAll = page.getByRole('button', { name: 'Kirim jawaban' })
    await submitAfterAll.waitFor()
    assert(
        !(await submitAfterAll.isDisabled()),
        'submit must unlock once all 90 items are answered',
    )
    results.push('submit unlocked: enabled with 90/90 items answered')

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`)

    return results
}
