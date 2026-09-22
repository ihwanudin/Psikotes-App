// Playwright CLI run-code --filename entrypoint; uses an isolated browser profile.
// See tests/Frontend/PsychologistReview/browser.test.mjs (and every other fixture's
// own copy of this same note) for the guard below: the CLI splices this file's raw
// content into `await (<content>)(page);`, so a trailing `;` after the closing `}`
// (which a formatting run would otherwise add) breaks the splice with a SyntaxError.
// The bare `prettier-ignore` line (nothing else on that line, or Prettier won't
// recognize it) keeps that from happening even under a broad `prettier --write`.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8019'
    const results = []
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message)
        }
    }

    await page.goto(origin)

    // --- Items load fails once (real network_error). Verified live
    // (browser pane, 2026-09-22): with the test browser reporting online
    // (navigator.onLine), useOfflineQueue's real queueRetry fires the retry
    // immediately rather than waiting for a manual click -- the "Coba lagi"
    // button is real (ist-assessment-runner.tsx's reconnecting branch) but
    // resolves too fast to reliably assert on here, so this only waits for
    // the state it recovers into, same as a real participant would
    // experience it under normal connectivity. ---
    // --- SE's reading gap: instructions + the exact "Mulai mengerjakan"
    // label (not "Mulai subtes" -- psikolog's P8 requirement). ---
    const startButton = page.getByRole('button', { name: 'Mulai mengerjakan' })
    await startButton.waitFor()
    const bodyBeforeStart = await page.evaluate(() => document.body.innerText)
    assert(
        bodyBeforeStart.includes('Pilih kata yang paling tepat melengkapi kalimat.'),
        'SE instructions must render during the reading gap',
    )
    results.push('reading gap: SE instructions render with the exact "Mulai mengerjakan" button label')

    // --- Confirming calls the real subtestNextSend(); the segment then
    // becomes current for real (server-driven, no client timer), and
    // IstSubtestScreen's real items/autosave wiring takes over. ---
    await startButton.click()
    await page.getByRole('radiogroup').waitFor()
    results.push('subtestNextSend: confirming the reading gap starts the real subtest screen')

    // --- Answer both SE items and reach the "Selesai" gate. ---
    await page.getByRole('radio').first().click()
    await page.getByRole('button', { name: 'Berikutnya' }).click()
    await page.getByRole('radio').first().click()
    await page.getByText('Lihat ringkasan').click()
    const completeButton = page.getByRole('button', { name: 'Selesai' })
    await completeButton.waitFor()
    assert(!(await completeButton.isDisabled()), 'Selesai must unlock once every SE item is answered')

    // --- allow_early_finish is false for every real segment today:
    // clicking Selesai calls the real subtestNextSend(), which the fixture's
    // fake server (mirroring TimedSegmentTransitionPolicy) rejects with
    // invalid_transition while SE's own window is still open -- surfaced as
    // a wait message, not an error. ---
    await completeButton.click()
    await page.getByText(/tunggu hingga waktu subtes ini berakhir/).waitFor({ timeout: 5000 })
    results.push('early finish: Selesai mid-window calls the real endpoint, which rejects it (invalid_transition), surfaced as a wait message')

    // --- No client-side timer/sequencing: the fixture's fake server (never
    // this component) decides when SE's window elapses and the sequence
    // auto-advances through WA/AN/GE/RA/ZR, purely via the next
    // GET /sessions/:id poll's current_segment. Eventually it reaches
    // ME_MEMORIZE, which IstItemContentReader (and this fixture's own
    // ITEM_SUBTESTS) deliberately does not build -- the "belum didukung"
    // message must appear instead of any fixture content standing in for
    // real ME behaviour. ---
    await page
        .getByText(/belum didukung sistem/)
        .waitFor({ timeout: 20000 })
    results.push('sequencing: SE -> WA -> AN -> GE -> RA -> ZR auto-advances via server polls alone, ending at ME_MEMORIZE\'s "belum didukung" state, never a fixture standing in for real ME content')

    // --- No horizontal overflow at 320/375px on the reading-gap screen
    // (the state most likely to overflow: full instructions + a button). ---
    await page.goto(origin)

    for (const width of [320, 375]) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto(origin)
        const retryOrStart = page.getByRole('button', { name: /Coba lagi|Mulai mengerjakan/ })
        await retryOrStart.waitFor()
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
        results.push(`${width}: no overflow on the reading-gap/loading screen`)
    }

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`)

    return results
}
