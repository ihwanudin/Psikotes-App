// Playwright CLI run-code --filename entrypoint. All values are synthetic and the origin is loopback-only.
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression.
async (page) => {
    const origin = 'http://127.0.0.1:8023'
    const output = 'output/playwright/assessment-bill-reviewer'
    page.setDefaultTimeout(180000)
    page.setDefaultNavigationTimeout(180000)
    const report = { checks: [], console: [], requests: [], failures: [] }
    const assert = (condition, message) => {
        if (!condition) {
throw new Error(message)
}
    }
    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) {
report.console.push(message.text())
}
    })
    page.on('pageerror', (error) => report.console.push(error.message))
    page.on('requestfailed', (request) => report.failures.push(request.url()))
    page.on('response', (response) => report.requests.push({
        path: response.url().startsWith(origin) ? response.url().slice(origin.length).split('?')[0] : response.url(),
        status: response.status(),
        method: response.request().method(),
    }))
    await page.context().route('**/*', (route) => {
        const url = route.request().url()

        if (url.startsWith(`${origin}/`)) {
return route.continue()
}

        report.failures.push(`blocked:${url}`)

        return route.abort()
    })

    const login = async (email) => {
        await page.context().clearCookies()
        await page.goto(`${origin}/admin/login`)
        await page.getByLabel(/email/i).fill(email)
        await page.locator('input[type="password"]').fill('browser-password')
        await page.getByRole('button', { name: /sign in|masuk/i }).click()
        await page.waitForURL(/\/admin(?:\/)?$/)
    }
    const visitBill = async (row) => {
        await page.goto(`${origin}/admin/assessment-bill-reviews`)
        const rows = page.locator('table tbody tr')
        await rows.nth(row).getByRole('link').first().click()
        await page.waitForURL(/assessment-bill-reviews\/\d+$/)
    }
    const assertSafe = async () => {
        const html = await page.content()

        for (const forbidden of [
            'Nama Privat Tidak Boleh Tampil', 'KANDIDAT-PRIVAT-', 'assessment-bills/',
            'proof_checksum_sha256', 'proof_object_key', 'gateway_ref', 'invoice_url',
        ]) {
assert(!html.includes(forbidden), `Sensitive value leaked: ${forbidden}`)
}
    }
    const openProofAndReturn = async () => {
        const detail = page.url()
        await page.getByRole('button', { name: 'Buka bukti' }).click()
        await page.waitForURL(`${origin}/__browser/proof`)
        assert((await page.locator('main').innerText()).includes('Bukti pembayaran sintetis'), 'Safe proof target missing')
        await page.goto(detail)
    }
    const confirmApprove = async (double = false) => {
        await page.getByRole('button', { name: 'Setujui pembayaran' }).click()
        const confirm = page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' })
        await confirm.waitFor()

        if (double) {
await confirm.dblclick()
} else {
await confirm.click()
}

        await page.getByText('Keputusan tersimpan.').waitFor()
    }
    const state = async () => {
        const response = await page.request.get(`${origin}/__browser/control/state`, {
            headers: { 'X-Browser-Harness': 'synthetic-only' },
        })
        assert(response.ok(), 'State probe failed')

        return response.json()
    }

    await page.setViewportSize({ width: 1365, height: 900 })
    await login('reviewer@example.test')
    assert(await page.getByText('Review tagihan asesmen').count(), 'Reviewer navigation missing')
    await page.getByText('Review tagihan asesmen').first().click()
    await page.waitForURL(/assessment-bill-reviews$/)
    const listText = await page.locator('main').innerText()

    for (const text of ['Tagihan', 'Organisasi', 'Nominal', 'Peserta', 'Status', 'Bukti diunggah']) {
        assert(listText.includes(text), `Safe list column missing: ${text}`)
    }

    assert(listText.includes('Rp') || listText.includes('IDR'), 'IDR amount not readable')
    await assertSafe()
    await page.screenshot({ path: `${output}/desktop-list.png`, fullPage: true })

    // A decision without an access audit must fail generically.
    await visitBill(0)
    await page.getByRole('button', { name: 'Setujui pembayaran' }).click()
    await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click()
    await page.getByText(/Buka bukti lalu coba kembali/).waitFor()
    assert((await state()).approve.status === 'pending', 'Decision without open mutated bill')

    await openProofAndReturn()
    await confirmApprove(true)
    let current = await state()
    assert(current.approve.status === 'paid', 'Approve did not settle')
    assert(current.approve.decisionAudits === 1 && current.approve.accessAudits === 1, 'Approve was not idempotent')
    assert(current.activationOutbox === 1, 'Approve activation outbox mismatch')
    await assertSafe()
    report.checks.push('without-open denied; open/approve settles once under double click')

    // Reject modal is keyboard reachable, cancellable, and bounded.
    await visitBill(1)
    await openProofAndReturn()
    await page.keyboard.press('Tab')

    for (let i = 0; i < 30 && !(await page.getByRole('button', { name: 'Tolak pembayaran' }).evaluate((el) => el === document.activeElement)); i++) {
        await page.keyboard.press('Tab')
    }

    const rejectButton = page.getByRole('button', { name: 'Tolak pembayaran' })
    assert(await rejectButton.evaluate((el) => el === document.activeElement), 'Keyboard could not reach reject action')
    assert(await rejectButton.evaluate((el) => el.matches(':focus-visible')), 'Reject focus is not visible')
    const actionClasses = await page.locator('button').evaluateAll((buttons) => ({
        approve: buttons.find((button) => button.innerText.includes('Setujui pembayaran')).className,
        reject: buttons.find((button) => button.innerText.includes('Tolak pembayaran')).className,
    }))
    assert(actionClasses.approve !== actionClasses.reject && actionClasses.reject.includes('danger'),
        'Destructive reject action is not visually distinguished')
    await page.keyboard.press('Enter')
    await page.getByLabel('Alasan penolakan').waitFor()
    await page.screenshot({ path: `${output}/keyboard-reject.png`, fullPage: true })
    await page.keyboard.press('Escape')
    assert((await state()).reject.status === 'pending', 'Escape changed decision')
    await rejectButton.click()
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click()
    assert((await state()).reject.status === 'pending', 'Cancel changed decision')
    await rejectButton.click()
    await page.getByLabel('Alasan penolakan').selectOption('UNREADABLE_PROOF')
    await page.getByRole('dialog').getByRole('button', { name: 'Submit' }).click()
    await page.getByText('Keputusan tersimpan.').waitFor()
    current = await state()
    assert(current.reject.status === 'rejected' && current.reject.decisionAudits === 1, 'Reject result mismatch')
    report.checks.push('keyboard focus/modal Escape/Cancel safe; destructive bounded reject succeeds once')

    // A replacement after successful proof access fences the old reviewer context.
    await visitBill(2)
    await openProofAndReturn()
    const replacement = await page.request.post(`${origin}/__browser/control/replace`, {
        headers: { 'X-Browser-Harness': 'synthetic-only' },
    })
    assert(replacement.ok(), 'Synthetic replacement failed')
    await page.reload()
    await page.getByRole('button', { name: 'Setujui pembayaran' }).click()
    await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click()
    await page.getByText(/Buka bukti lalu coba kembali/).waitFor()
    current = await state()
    assert(current.stale.status === 'pending' && current.stale.decisionAudits === 0, 'Stale proof settled')
    report.checks.push('replacement after open is fenced without settlement')

    // Mobile/reflow uses the real list/detail; bounded horizontal table scrolling is allowed.
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(`${origin}/admin/assessment-bill-reviews`)
    const overflow = await page.evaluate(() => ({
        body: document.documentElement.scrollWidth - innerWidth,
        main: document.querySelector('main').getBoundingClientRect().right - innerWidth,
    }))
    assert(overflow.body <= 2 && overflow.main <= 2, `Critical mobile overflow: ${JSON.stringify(overflow)}`)
    await page.screenshot({ path: `${output}/mobile-list.png`, fullPage: true })
    report.checks.push('desktop/mobile layout and safe fields verified')

    // Direct URL/navigation denial for every non-reviewer role and guest.
    assert(report.console.length === 0, `Unexpected console errors before denial probes: ${JSON.stringify(report.console)}`)
    report.console = []
    const denied = ['branch@example.test', 'staff@example.test', 'psychologist@example.test']

    for (const email of denied) {
        await login(email)
        assert(await page.getByText('Review tagihan asesmen').count() === 0, `${email} saw reviewer navigation`)
        const response = await page.goto(`${origin}/admin/assessment-bill-reviews/1`)
        assert(response.status() === 404, `${email} direct detail was not hidden`)
    }

    await page.context().clearCookies()
    await page.goto(`${origin}/admin/assessment-bill-reviews/1`)
    assert(page.url().includes('/admin/login'), 'Guest was not redirected to login')
    assert(report.console.length === denied.length && report.console.every((message) => message.includes('404')),
        `Unexpected denial console output: ${JSON.stringify(report.console)}`)
    report.denialConsole404 = report.console.length
    report.console = []
    report.checks.push('BranchAdmin, Staff legacy, Psychologist, and guest denied')

    const unsafeNetwork = report.requests.filter(({ path }) =>
        path.includes('assessment-bills/') || path.includes('proof_checksum') || path.includes('gateway_ref'))
    assert(unsafeNetwork.length === 0, 'Private proof identity leaked in network URL')
    assert(report.failures.length === 0, `Network failures: ${JSON.stringify(report.failures)}`)

    return report
}
