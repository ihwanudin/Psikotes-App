// Playwright CLI run-code --filename entrypoint; uses an isolated browser profile.
// See tests/Frontend/PsychologistReview/browser.test.mjs (and PapiRunner's/RmibRunner's/
// IstSubtestScreen's own copies of this same note) for the guard below: the CLI splices
// this file's raw content into `await (<content>)(page);`, so a trailing `;` after the
// closing `}` (which a formatting run would otherwise add) breaks the splice with a
// SyntaxError. The bare `prettier-ignore` line (nothing else on that line, or Prettier
// won't recognize it) keeps that from happening even under a broad `prettier --write`.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8018'
    const results = []
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message)
        }
    }

    // Mirrors preview.tsx's valueFor() exactly -- a duplicated formula on
    // purpose, not an import: this file is standalone synthetic test data,
    // not the thing under test.
    const valueFor = (columnNumber, position) =>
        (((position - 1 + (columnNumber - 1)) % 9) + 1)

    // Expected numbers top-to-bottom on screen: position 28 first (topmost),
    // position 1 last (bottom-most) -- see kraepelin-column.tsx's doc for why.
    const expectedTopToBottom = (columnNumber) =>
        Array.from({ length: 28 }, (_, i) => valueFor(columnNumber, 28 - i))

    await page.goto(origin)

    // --- Reconnecting state: the fixture's first fetchItems() call always
    // fails with network_error, so this must actually render, not just
    // exist in source. ---
    const alert = page.getByRole('alert')
    await alert.waitFor()
    const alertText = await alert.textContent()
    assert(
        alertText.includes('Tidak dapat terhubung'),
        `reconnecting state must show the network-error message, got: ${alertText}`,
    )
    results.push('reconnecting: "Tidak dapat terhubung. Coba lagi." renders on the first failed fetch')

    const retryButton = page.getByRole('button', { name: 'Coba lagi' })
    await retryButton.waitFor()
    const retryBox = await retryButton.boundingBox()
    assert(retryBox !== null, '"Coba lagi" button has no bounding box')
    assert(
        retryBox.height >= 44,
        `"Coba lagi" touch target must be >=44px tall, got ${retryBox.height}px`,
    )
    results.push(`touch target: "Coba lagi" is ${Math.round(retryBox.height)}px tall (>=44px)`)

    // --- Clicking "Coba lagi" retries immediately (bypassing the fixture's
    // no-op queueRetry) and the second attempt succeeds. ---
    await retryButton.click()
    const group = page.getByRole('group', { name: 'Kolom Kraepelin' })
    await group.waitFor()
    results.push('retry: clicking "Coba lagi" recovers and renders the real column')

    // --- Order contract: the numbers rendered top-to-bottom must be
    // EXACTLY position 28..1 of column 1 -- i.e. the client must not
    // reverse the already-administration-order data it received again.
    // Comparing the full 28-number sequence (not just the two endpoints)
    // so the assertion can't pass by coincidence. ---
    const readNumbersTopToBottom = () =>
        page.evaluate(() => {
            const groupEl = document.querySelector('[aria-label="Kolom Kraepelin"]')
            return Array.from(groupEl.querySelectorAll(':scope > div')).map((el) =>
                Number(el.textContent),
            )
        })

    const column1Numbers = await readNumbersTopToBottom()
    assert(
        JSON.stringify(column1Numbers) === JSON.stringify(expectedTopToBottom(1)),
        `column 1 numbers must render top-to-bottom as position 28..1, got ${JSON.stringify(column1Numbers)}`,
    )
    results.push('order: column 1 renders position 28 (top) .. position 1 (bottom), server order not re-reversed')

    // --- 27 answer slots, initial focus on the first one (between
    // position 1 and 2, the bottom-most pair). ---
    const slots = page.locator('[aria-label^="Kotak jumlah"]')
    assert((await slots.count()) === 27, `expected 27 answer slots, got ${await slots.count()}`)

    const firstSlot = page.getByLabel('Kotak jumlah 1 dari 27')
    const isFirstSlotFocused = await firstSlot.evaluate((el) => el === document.activeElement)
    assert(isFirstSlotFocused, 'the first answer slot must be auto-focused on mount')
    results.push('focus: mounting a column auto-focuses answer slot 1 of 27')

    // --- Physical-keyboard entry auto-advances focus (kraepelin-column.tsx's
    // inputMode="none" contract: still a real, focusable, typeable field). ---
    await page.keyboard.type('7')
    assert((await firstSlot.inputValue()) === '7', 'typing a digit must fill the focused slot')
    const secondSlot = page.getByLabel('Kotak jumlah 2 dari 27')
    const isSecondSlotFocused = await secondSlot.evaluate((el) => el === document.activeElement)
    assert(isSecondSlotFocused, 'entering a digit must auto-advance focus to the next slot')
    results.push('keyboard entry: typing a digit fills the slot and auto-advances focus')

    // --- The on-screen keypad also writes into whichever slot is
    // currently focused. ---
    await page.getByRole('button', { name: 'Masukkan angka 3' }).click()
    assert((await secondSlot.inputValue()) === '3', 'the keypad must write into the focused slot')
    results.push('keypad: tapping a digit fills the currently focused slot')

    // --- Touch targets: keypad digit/backspace buttons are 44px, matching
    // the a11y audit's ambang (already correct here, checked as a
    // regression guard). ---
    const keypadDigit = page.getByRole('button', { name: 'Masukkan angka 5' })
    const keypadDigitBox = await keypadDigit.boundingBox()
    assert(
        keypadDigitBox.height >= 44,
        `keypad digit button must be >=44px tall, got ${keypadDigitBox.height}px`,
    )
    results.push('touch target: keypad digit buttons are >=44px tall')

    // --- Switching columns (the fixture's own debug control, not part of
    // the production component) remounts with fresh, empty state --
    // nothing leaks from column 1 -- and the new column's numbers match
    // ITS OWN administration order, not column 1's. ---
    await page.getByRole('button', { name: /Kolom berikutnya/ }).click()
    await page.getByRole('group', { name: 'Kolom Kraepelin' }).waitFor()
    const column2Numbers = await readNumbersTopToBottom()
    assert(
        JSON.stringify(column2Numbers) === JSON.stringify(expectedTopToBottom(2)),
        `column 2 numbers must render top-to-bottom as its own position 28..1, got ${JSON.stringify(column2Numbers)}`,
    )
    assert(
        JSON.stringify(column2Numbers) !== JSON.stringify(column1Numbers),
        'column 2 must show different numbers than column 1 (columnIndex actually changed what was fetched)',
    )
    const firstSlotAfterSwitch = page.getByLabel('Kotak jumlah 1 dari 27')
    assert(
        (await firstSlotAfterSwitch.inputValue()) === '',
        'switching columns must not carry over column 1\'s typed digits',
    )
    results.push('column switch: remounts with fresh empty state and the new column\'s own order')

    // --- No horizontal overflow at 320/375/1280px. ---
    for (const width of [320, 375, 1280]) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto(origin)
        // This reload lands back on the reconnecting state (fetchAttempts
        // is a module-level counter that only ever fails once, on the very
        // first call across the fixture's lifetime -- a fresh page load
        // does reset it, since it's a new JS context).
        await page.getByRole('button', { name: 'Coba lagi' }).click()
        await page.getByRole('group', { name: 'Kolom Kraepelin' }).waitFor()
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

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`)

    return results
}
