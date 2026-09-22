// Playwright CLI run-code --filename entrypoint; uses an isolated browser profile.
// See tests/Frontend/PsychologistReview/browser.test.mjs (and PapiRunner's own copy
// of this same note) for the guard below: the CLI splices this file's raw content
// into `await (<content>)(page);`, so a trailing `;` after the closing `}` (which a
// formatting run would otherwise add) breaks the splice with a SyntaxError. The bare
// `prettier-ignore` line (nothing else on that line, or Prettier won't recognize it)
// keeps that from happening even under a broad `prettier --write`.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8014'
    const results = []
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message)
        }
    }

    const startFlow = async () => {
        await page.goto(origin)
        const startButton = page.getByRole('button', { name: 'Mulai' })
        await startButton.waitFor()
        await startButton.click()
        await page.getByRole('heading', { name: /Kelompok/ }).waitFor()
    }

    // --- Instructions screen shows the real /items instructions content
    // before any group is shown. ---
    await page.goto(origin)
    await page.getByRole('button', { name: 'Mulai' }).waitFor()
    const instructionsText = await page.evaluate(() => document.body.innerText)
    assert(
        instructionsText.includes('sejumlah kelompok pekerjaan'),
        'instructions text from /items was not rendered',
    )
    assert(
        (await page.getByRole('heading', { name: /Kelompok/ }).count()) === 0,
        'the group view must not render before Mulai is clicked',
    )
    results.push('instructions screen: real /items instructions text rendered, group view not shown yet')

    // --- No horizontal overflow at 320/375/1280px, both on the
    // instructions screen and the group view. ---
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
        const groupGeometry = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            scrollWidth: Math.max(
                document.documentElement.scrollWidth,
                document.body.scrollWidth,
            ),
        }))
        assert(
            groupGeometry.scrollWidth === groupGeometry.viewport,
            `${width}/group: page overflow (scrollWidth ${groupGeometry.scrollWidth} != viewport ${groupGeometry.viewport})`,
        )
        results.push(`${width}: no overflow (instructions and group view)`)
    }

    await page.setViewportSize({ width: 375, height: 900 })
    await startFlow()

    // --- Resume rehydration: the fixture's fetchResumeAnswers pre-confirms
    // group 1 with a reversed order (position 12 ranked first). The
    // runner must render that on load (no "Simpan urutan ini" button,
    // since it's already confirmed), not start blank. ---
    const headingText = await page.evaluate(
        () => document.querySelector('h2')?.textContent ?? '',
    )
    assert(headingText.includes('Kelompok A'), 'must start on group 1 (Kelompok A)')
    assert(
        (await page.getByRole('button', { name: 'Simpan urutan ini' }).count()) === 0,
        'a resumed, already-confirmed group must not show "Simpan urutan ini"',
    )
    assert(
        (await page.getByText('Urutan kelompok ini tersimpan.').count()) === 1,
        'a resumed, already-confirmed group must show the confirmed status text',
    )
    const firstRowJobResumed = await page.evaluate(
        () => document.querySelectorAll('li')[0]?.textContent ?? '',
    )
    assert(
        firstRowJobResumed.includes('Pekerjaan 1.12'),
        `group 1's resumed rank 1 must be position 12 (Pekerjaan 1.12), got: ${firstRowJobResumed}`,
    )
    results.push('resume rehydration: group 1 renders pre-confirmed, reversed order, from fetchResumeAnswers')

    // --- Keyboard/screen-reader path: the up/down buttons reorder without
    // any pointer/touch drag, and are disabled at the edges. ---
    await page.getByRole('button', { name: 'Berikutnya' }).click() // group 2
    await page.getByRole('heading', { name: 'Kelompok B' }).waitFor()

    const firstRowJobBefore = await page.evaluate(
        () => document.querySelectorAll('li')[0]?.textContent ?? '',
    )
    assert(firstRowJobBefore.includes('Pekerjaan 2.1'), 'group 2 must start in document order')

    const firstUpButton = page.getByRole('button', { name: /^Naikkan peringkat/ }).first()
    assert(
        await firstUpButton.isDisabled(),
        'the rank-1 row\'s "up" button must be disabled (already at the top)',
    )

    await page.getByRole('button', { name: /^Turunkan peringkat: Pekerjaan 2\.1$/ }).click()
    const firstRowJobAfter = await page.evaluate(
        () => document.querySelectorAll('li')[0]?.textContent ?? '',
    )
    assert(
        firstRowJobAfter.includes('Pekerjaan 2.2'),
        `moving position 1 down must promote position 2 to rank 1, got: ${firstRowJobAfter}`,
    )
    assert(
        (await page.getByRole('button', { name: 'Simpan urutan ini' }).count()) === 0,
        'a button-based reorder must auto-confirm the group (no "Simpan urutan ini" left to click)',
    )
    results.push('keyboard buttons: turunkan/naikkan reorder the list and auto-confirm; edge button is disabled')

    // --- Autosave: leaving group 2 must flush all 12 of its positions
    // through send(), mapped to the real wire shape {item_no, value}. ---
    await page.getByRole('button', { name: 'Berikutnya' }).click() // group 3
    await page.getByRole('heading', { name: 'Kelompok C' }).waitFor()

    const afterGroup2Nav = await page.evaluate(() => window.__rmibAutosaveCalls)
    const group2Batches = afterGroup2Nav.filter((batch) =>
        batch.some((entry) => entry.item_no >= 13 && entry.item_no <= 24),
    )
    assert(group2Batches.length > 0, 'reordering group 2 and navigating away must flush a batch for it')
    const lastGroup2Batch = group2Batches[group2Batches.length - 1]
    assert(
        lastGroup2Batch.length === 12,
        `a group's flush must carry all 12 of its positions, got ${lastGroup2Batch.length}`,
    )
    const rank1Entry = lastGroup2Batch.find((entry) => entry.value === '1')
    assert(
        rank1Entry !== undefined && rank1Entry.item_no === 13 + 1,
        `item_no 14 (group 2 position 2) must be the one autosaved with value "1" (rank 1), got ${JSON.stringify(rank1Entry)}`,
    )
    results.push('autosave: group 2\'s full 12-position batch reached send() with the correct {item_no, value} shape')

    // --- Drag-and-drop: dragging the first row's handle down past the
    // second row reorders the list, proving @dnd-kit is really wired
    // (not just the button path above). ---
    const dragHandle = page.getByRole('button', { name: /^Seret untuk mengurutkan ulang/ }).first()
    const beforeDragFirstRow = await page.evaluate(
        () => document.querySelectorAll('li')[0]?.textContent ?? '',
    )
    assert(beforeDragFirstRow.includes('Pekerjaan 3.1'), 'group 3 must start in document order')

    // Chromium restores scroll position on a repeat page.goto() to a URL
    // already in this session's history (startFlow() above has called it
    // several times by now) -- without this, boundingBox() below can
    // return a stale, viewport-mismatched (even negative-y) box, and the
    // mouse sequence drags over nothing. Found by actually running this
    // against the live fixture: the first version of this test failed
    // with a suspiciously silent no-op, and a standalone debug script
    // showed window.scrollY was 913 at exactly this point.
    await dragHandle.scrollIntoViewIfNeeded()
    const handleBox = await dragHandle.boundingBox()
    assert(handleBox !== null, 'drag handle must have a bounding box')
    await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2)
    await page.mouse.down()
    await page.waitForTimeout(50)
    await page.mouse.move(
        handleBox.x + handleBox.width / 2,
        handleBox.y + handleBox.height / 2 + 90,
        { steps: 12 },
    )
    await page.waitForTimeout(50)
    await page.mouse.up()

    const afterDragFirstRow = await page.evaluate(
        () => document.querySelectorAll('li')[0]?.textContent ?? '',
    )
    assert(
        !afterDragFirstRow.includes('Pekerjaan 3.1'),
        `dragging the first row down must move a different position to rank 1, still: ${afterDragFirstRow}`,
    )
    assert(
        (await page.getByRole('button', { name: 'Simpan urutan ini' }).count()) === 0,
        'a drag reorder must auto-confirm the group too',
    )
    results.push('drag-and-drop: dragging the handle reorders the list via @dnd-kit and auto-confirms')

    // --- "Simpan urutan ini": confirms a group WITHOUT requiring any
    // reorder (Lead's 2026-09-21 correction) -- the participant who
    // genuinely wants to keep the shown order must be able to. ---
    await page.getByRole('button', { name: 'Berikutnya' }).click() // group 4
    await page.getByRole('heading', { name: 'Kelompok D' }).waitFor()

    const group4RowBefore = await page.evaluate(
        () => document.querySelectorAll('li')[0]?.textContent ?? '',
    )
    assert(group4RowBefore.includes('Pekerjaan 4.1'), 'group 4 must start in document order, untouched')
    assert(
        (await page.getByRole('button', { name: 'Simpan urutan ini' }).count()) === 1,
        'an untouched group must show "Simpan urutan ini"',
    )

    await page.getByRole('button', { name: 'Simpan urutan ini' }).click()
    const group4RowAfter = await page.evaluate(
        () => document.querySelectorAll('li')[0]?.textContent ?? '',
    )
    assert(
        group4RowAfter.includes('Pekerjaan 4.1'),
        '"Simpan urutan ini" must confirm without changing the order',
    )
    assert(
        (await page.getByText('Urutan kelompok ini tersimpan.').count()) === 1,
        'the group must show as confirmed right after "Simpan urutan ini"',
    )
    results.push('"Simpan urutan ini": confirms the shown order as the real answer without any reorder')

    // --- An untouched, unconfirmed group is still recorded as
    // unanswered (Lead's explicit test requirement). ---
    await page.getByRole('button', { name: 'Berikutnya' }).click() // group 5
    await page.getByRole('heading', { name: 'Kelompok E' }).waitFor()
    await page.getByText('Lihat ringkasan').click()
    const summaryAfterFive = await page.evaluate(() => document.body.innerText)
    assert(
        summaryAfterFive.includes('4 dari 9 kelompok terjawab'),
        `expected 4/9 confirmed (1 resumed + 3 confirmed above), got: ${summaryAfterFive}`,
    )
    assert(
        (await page
            .getByRole('button', { name: /^Kelompok E$/ })
            .count()) === 1,
        'group 5 (untouched, unconfirmed) must be listed as unanswered in the summary',
    )
    results.push('untouched group: an untouched, unconfirmed group is recorded as unanswered, not a default answer')

    const submitButton = page.getByRole('button', { name: 'Kirim jawaban' })
    await submitButton.waitFor()
    assert(await submitButton.isDisabled(), 'submit must be locked while any group is unconfirmed')
    results.push('submit locked: disabled while groups remain unconfirmed')

    // --- Confirm every remaining group via "Simpan urutan ini" (fastest
    // path) and verify submit unlocks once all 9 are confirmed, and every
    // one of the 108 positions has reached send() by then. ---
    await page.getByText('Kembali ke kelompok').click()
    await page.getByRole('heading', { name: /Kelompok/ }).waitFor()

    for (let groupNumber = 5; groupNumber <= 9; groupNumber++) {
        const saveButton = page.getByRole('button', { name: 'Simpan urutan ini' })

        if ((await saveButton.count()) > 0) {
            await saveButton.click()
        }

        if (groupNumber < 9) {
            await page.getByRole('button', { name: 'Berikutnya' }).click()
        }
    }

    await page.getByText('Lihat ringkasan').click()

    const allCalls = await page.evaluate(() => window.__rmibAutosaveCalls)
    const lastValueByItemNo = new Map()

    for (const batch of allCalls) {
        for (const entry of batch) {
            lastValueByItemNo.set(entry.item_no, entry.value)
        }
    }

    // Group 1 was resumed (already on the server) and never touched again
    // in this flow, so it correctly never re-reaches send() -- re-sending
    // unchanged resumed data would be wasteful. Its 12 positions (item_no
    // 1-12) are excluded from this check on purpose; expecting 96 (8
    // groups x 12), not 108, proves that.
    assert(
        lastValueByItemNo.size === 96,
        `expected the 8 touched groups' 96 positions to have reached send() (group 1 was resumed and left untouched, so it must NOT reappear here), got ${lastValueByItemNo.size}`,
    )
    assert(
        ![...lastValueByItemNo.keys()].some((itemNo) => itemNo >= 1 && itemNo <= 12),
        'group 1 (item_no 1-12) must not have been re-autosaved -- it was only resumed, never re-touched',
    )

    for (let itemNo = 13; itemNo <= 108; itemNo++) {
        const value = lastValueByItemNo.get(itemNo)
        assert(
            typeof value === 'string' && /^(?:[1-9]|1[0-2])$/.test(value),
            `item_no ${itemNo}'s autosaved value must be a valid rank string "1".."12", got ${JSON.stringify(value)}`,
        )
    }

    results.push('autosave: all 8 touched groups (96 positions) reached send() correctly, group 1 (resumed, untouched) correctly did not')

    const submitAfterAll = page.getByRole('button', { name: 'Kirim jawaban' })
    await submitAfterAll.waitFor()
    assert(!(await submitAfterAll.isDisabled()), 'submit must unlock once all 9 groups are confirmed')
    results.push('submit unlocked: enabled with 9/9 groups confirmed')

    // --- Real HTTP transport scenario (Lead's 2026-09-21 instruction):
    // a second RmibItemsRunner on the same page, wired through the real
    // createHttpTransport() against a fake HTTP endpoint returning the
    // real wire shapes, proving rmibItemsOutcomeFromGeneric is actually
    // exercised inside a rendered runner. Safe to run here, at the very
    // end of the script (no page.goto() since the startFlow() call
    // above): the section-1 runner has moved off its group view (drag
    // rows/up-down buttons/Sebelumnya/Berikutnya) onto its ringkasan
    // screen by this point, so this section's own "Mulai" and job rows
    // are the only ones on the page when these locators run.
    const httpDemoStart = page.getByRole('button', { name: 'Mulai' })
    await httpDemoStart.waitFor()
    await httpDemoStart.click()

    const httpDemoDownButton = page
        .getByRole('button', { name: /Turunkan peringkat/ })
        .first()
    await httpDemoDownButton.waitFor()
    const httpDemoDownLabel = await httpDemoDownButton.getAttribute('aria-label')
    assert(
        httpDemoDownLabel.includes('(transport HTTP)'),
        `the real-transport scenario's job text must come from the fake HTTP endpoint's response, got: ${httpDemoDownLabel}`,
    )
    results.push('http transport: rmibItemsOutcomeFromGeneric-mapped job text rendered from the real createHttpTransport() + fake endpoint, not the synthetic fixture data')

    await httpDemoDownButton.click()
    await page.waitForFunction(
        () =>
            window.__rmibHttpTransportCalls.some(
                (call) => call.method === 'POST' && call.url.endsWith('/answers'),
            ),
        { timeout: 5000 },
    )
    results.push('http transport: reordering a group reached autosaveSend() -> a real POST /answers call')

    const httpDemoUnauthorizedButton = page.getByRole('button', {
        name: 'Uji token kedaluwarsa (401, uji)',
    })
    await httpDemoUnauthorizedButton.click()
    await page.waitForFunction(
        () => window.__rmibHttpTransportUnauthorizedFired === true,
        { timeout: 5000 },
    )
    await page.getByText('Sesi psikotes telah berakhir.').waitFor()
    results.push('http transport: a 401 fires onUnauthorized() exactly once and the page shows the session-ended message, same pattern as lobby.tsx')

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`)

    return results
}
