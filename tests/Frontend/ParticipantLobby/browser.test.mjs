// Playwright CLI run-code --filename entrypoint; uses an isolated browser profile.
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8011'
    const token = 'synthetic-lobby-fixture-not-a-credential'
    const results = []
    const errors = []
    await page.unrouteAll({ behavior: 'ignoreErrors' })
    page.on('pageerror', (error) => errors.push(error.message))
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message)
        }
    }
    await page.addInitScript((fixtureToken) => {
        if (location.origin === 'http://127.0.0.1:8011') {
            if (location.search === '?no-token') {
                sessionStorage.removeItem('participant_access_token')
            } else {
                sessionStorage.setItem('participant_access_token', fixtureToken)
            }
        }
    }, token)

    const cases = [
        ['null', null, null, 'Nama belum dilengkapi', 'Belum tersedia'],
        ['empty', '', '', 'Nama belum dilengkapi', 'Belum tersedia'],
        [
            'whitespace',
            ' \t\n ',
            '\t  ',
            'Nama belum dilengkapi',
            'Belum tersedia',
        ],
        [
            'complete',
            'Nadia Peserta Contoh',
            'TEST-SYNTHETIC-001',
            'Nadia Peserta Contoh',
            'TEST-SYNTHETIC-001',
        ],
        [
            'missing name only',
            null,
            'TEST-SYNTHETIC-002',
            'Nama belum dilengkapi',
            'TEST-SYNTHETIC-002',
        ],
        [
            'missing number only',
            'Nadia Peserta Contoh',
            null,
            'Nadia Peserta Contoh',
            'Belum tersedia',
        ],
        [
            'preserve nonblank',
            '  Nadia Peserta Contoh  ',
            ' TEST-SYNTHETIC-003 ',
            '  Nadia Peserta Contoh  ',
            ' TEST-SYNTHETIC-003 ',
        ],
    ]
    let profile
    let fail = false
    let release
    let pending
    let requests = []
    await page.route('**/*', async (route) => {
        const request = route.request()
        const url = request.url()

        if (!url.startsWith(`${origin}/`)) {
            throw new Error('Unexpected external request')
        }

        const pathname = url.slice(origin.length)

        if (!pathname.startsWith('/api/')) {
            return route.continue()
        }

        assert(
            ['/api/me', '/api/me/entitlements'].includes(pathname),
            'Unexpected API',
        )
        assert(request.method() === 'GET', 'Unexpected write request')
        assert(
            request.headers().authorization === `Bearer ${token}`,
            'Fixture token changed',
        )
        requests.push(pathname)
        await pending

        return route.fulfill({
            status: fail ? 401 : 200,
            json: {
                data:
                    pathname === '/api/me'
                        ? profile
                        : [
                              { test_type: 'ist', status: 'locked' },
                              { test_type: 'papi', status: 'ready' },
                              { test_type: 'rmib', status: 'in_progress' },
                              { test_type: 'kraepelin', status: 'done' },
                              // DASS-21 stays in this same list, styled the same
                              // as every other test (owner decision, decision
                              // doc item 10) — a known status here proves it
                              // gets a real Indonesian label, not special-cased.
                              { test_type: 'dass21', status: 'ready' },
                              // Not one of the four documented statuses: proves the
                              // fallback path renders safely instead of the raw word
                              // or crashing (Entitlement.status describes the
                              // expected shape, not a runtime guarantee).
                              { test_type: 'future_test', status: 'archived' },
                          ],
            },
        })
    })

    for (const [
        name,
        fullName,
        testNumber,
        expectedName,
        expectedNumber,
    ] of cases) {
        profile = { full_name: fullName, test_number: testNumber }
        requests = []
        pending = new Promise((resolve) => {
            release = resolve
        })
        await page.goto(origin)
        await page.getByText('Memuat data peserta', { exact: true }).waitFor()
        release()
        await page.getByRole('heading', { name: 'Tes yang tersedia' }).waitFor()
        const heading = await page.locator('section h2').first().textContent()
        const number = await page
            .locator('section h2')
            .first()
            .locator('..')
            .locator('p')
            .textContent()
        assert(
            heading === expectedName,
            `${name}: name was ${JSON.stringify(heading)}`,
        )
        assert(
            number === `Nomor tes ${expectedNumber}`,
            `${name}: number was ${JSON.stringify(number)}`,
        )
        const listText = await page
            .getByRole('list', { name: 'Daftar tes' })
            .textContent()
        // Every status is translated to Indonesian; the four documented
        // statuses each get a distinct label, and the not-yet-workable ones
        // (locked, in_progress) carry a one-sentence factual explanation —
        // never a reason, since /api/me/entitlements only returns status.
        assert(
            listText.includes('Siap'),
            `${name}: ready label changed`,
        )
        assert(
            listText.includes('Belum dibuka'),
            `${name}: locked label changed`,
        )
        assert(
            listText.includes('Akses untuk tes ini belum dibuka.'),
            `${name}: locked description changed`,
        )
        assert(
            listText.includes('Sedang berlangsung'),
            `${name}: in_progress label changed`,
        )
        assert(
            listText.includes('Tes ini sedang berlangsung.'),
            `${name}: in_progress description changed`,
        )
        assert(
            listText.includes('Selesai'),
            `${name}: done label changed`,
        )
        // DASS-21 stays in this same list (decision doc item 10) and must
        // get a real Indonesian label like every other test, not the
        // unknown-status fallback.
        const dassRowText = await page
            .getByRole('listitem')
            .filter({ hasText: 'DASS-21' })
            .textContent()
        assert(
            dassRowText.includes('Siap'),
            `${name}: DASS-21 did not get the ready label`,
        )
        assert(
            !dassRowText.includes('Status tidak dikenali'),
            `${name}: DASS-21 fell back to the unknown-status label`,
        )
        // Unrecognized status: safe neutral fallback, not a crash.
        assert(
            listText.includes('Status tidak dikenali'),
            `${name}: unknown status fallback label changed`,
        )
        assert(
            listText.includes('Status tes ini tidak dikenali oleh sistem.'),
            `${name}: unknown status fallback description changed`,
        )

        // The whole point of this fix: no raw English/DB word ever reaches
        // the participant.
        for (const rawWord of ['locked', 'in_progress', 'done', 'archived']) {
            assert(
                !listText.includes(rawWord),
                `${name}: raw status word "${rawWord}" leaked to the participant`,
            )
        }

        assert(
            (await page.getByRole('button').count()) === 0,
            `${name}: unexpected access action`,
        )
        assert(requests.length === 2, `${name}: unexpected fetch count`)
        assert(
            (await page.evaluate(() =>
                sessionStorage.getItem('participant_access_token'),
            )) === token,
            `${name}: token changed`,
        )
        results.push(
            `${name}: PASS (loading → ready, labels, server statuses, no write)`,
        )
    }

    fail = true
    await page.goto(origin)
    await page.getByRole('heading', { name: 'Sesi tidak aktif' }).waitFor()
    assert(
        await page
            .getByRole('alert')
            .textContent()
            .then((text) => text.includes('Sesi psikotes telah berakhir.')),
        'Fetch error message changed',
    )
    assert(
        (await page.evaluate(() =>
            sessionStorage.getItem('participant_access_token'),
        )) === null,
        'Failed fetch did not clear token',
    )
    results.push('fetch failure: PASS (error message and token cleanup)')

    requests = []
    await page.goto(`${origin}/?no-token`)
    await page.getByRole('heading', { name: 'Sesi tidak aktif' }).waitFor()
    assert(
        await page
            .getByRole('alert')
            .textContent()
            .then((text) => text.includes('Sesi psikotes tidak ditemukan.')),
        'Missing token message changed',
    )
    assert(requests.length === 0, 'Missing token sent API requests')
    results.push('missing token: PASS (initial error, no API fetch)')

    const captureGeometry = async (state, width) => {
        const geometry = await page.evaluate(() => {
            const viewport = document.documentElement.clientWidth
            const labels = [...document.querySelectorAll('h1, h2, p, li span')]
                .filter((element) => element.getClientRects().length > 0)
                .map((element) => {
                    const box = element.getBoundingClientRect()
                    const range = document.createRange()
                    range.selectNodeContents(element)
                    const lines = [...range.getClientRects()]
                    const style = getComputedStyle(element)
                    const clippingAncestors = []
                    let ancestor = element

                    while (ancestor) {
                        const ancestorStyle = getComputedStyle(ancestor)
                        const ancestorBox = ancestor.getBoundingClientRect()
                        const clippingValues = ['hidden', 'clip', 'auto', 'scroll']
                        clippingAncestors.push({
                            x: clippingValues.includes(ancestorStyle.overflowX),
                            y: clippingValues.includes(ancestorStyle.overflowY),
                            left: ancestorBox.left + ancestor.clientLeft,
                            top: ancestorBox.top + ancestor.clientTop,
                            right: ancestorBox.left + ancestor.clientLeft + ancestor.clientWidth,
                            bottom: ancestorBox.top + ancestor.clientTop + ancestor.clientHeight,
                        })
                        ancestor = ancestor.parentElement
                    }

                    return {
                        text: element.textContent,
                        textFragments: lines.length,
                        outside: lines.some((line) =>
                            line.left < -1 || line.right > viewport + 1 ||
                            line.left < box.left - 1 || line.right > box.right + 1,
                        ),
                        // Font bounds may exceed line-height without clipping when overflow is visible.
                        clipped: clippingAncestors.some((clip) => lines.some((line) =>
                            (clip.x && (line.left < clip.left - 1 || line.right > clip.right + 1)) ||
                            (clip.y && (line.top < clip.top - 1 || line.bottom > clip.bottom + 1)),
                        )) || style.textOverflow === 'ellipsis',
                    }
                })

            return {
                viewport,
                scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
                labels,
                controls: document.querySelectorAll('a[href], button, input, select, textarea, [tabindex]').length,
                styles: {
                    mainPadding: getComputedStyle(document.querySelector('main')).paddingLeft,
                    headingSize: getComputedStyle(document.querySelector('h1')).fontSize,
                    sectionRadius: getComputedStyle(document.querySelector('section')).borderRadius,
                },
            }
        })
        const screenshot = `output/playwright/lobby-visual/${width}-${state}.png`
        await page.screenshot({ path: screenshot, fullPage: true })
        assert(geometry.scrollWidth === geometry.viewport, `${width}/${state}: page overflow`)
        assert(geometry.labels.every((label) => !label.outside && !label.clipped),
            `${width}/${state}: clipped label ${JSON.stringify(geometry.labels.filter((label) => label.outside || label.clipped))}`)
        // The existing lobby is informational: it has no links or focusable controls.
        assert(geometry.controls === 0, `${width}/${state}: unexpected tab stop`)
        const beforeTab = page.url()
        await page.keyboard.press('Tab')
        assert(page.url() === beforeTab, `${width}/${state}: Tab navigated`)
        results.push({ state, width, screenshot, ...geometry })
    }

    for (const width of [320, 390, 1280]) {
        await page.setViewportSize({ width, height: 900 })
        fail = false
        profile = { full_name: null, test_number: null }
        pending = new Promise((resolve) => {
            release = resolve
        })
        await page.goto(origin)
        await page.getByText('Memuat data peserta', { exact: true }).waitFor()
        await captureGeometry('loading', width)
        release()
        await page.getByRole('heading', { name: 'Nama belum dilengkapi' }).waitFor()
        await captureGeometry('null', width)

        profile = { full_name: 'Nadia Peserta Contoh', test_number: 'TEST-SYNTHETIC-001' }
        await page.goto(origin)
        await page.getByRole('heading', { name: 'Nadia Peserta Contoh' }).waitFor()
        await captureGeometry('complete', width)

        fail = true
        await page.goto(origin)
        await page.getByRole('heading', { name: 'Sesi tidak aktif' }).waitFor()
        await captureGeometry('error', width)
    }

    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`)
    const unstyled = results.filter((result) => typeof result === 'object' && (
        result.styles.mainPadding !== '16px' ||
        result.styles.headingSize !== '30px' ||
        result.styles.sectionRadius !== '16px'
    ))
    assert(unstyled.length === 0,
        `Visual fixture missing lobby utilities; geometry is NOT accepted: ${JSON.stringify(unstyled.map(({ width, state, styles }) => ({ width, state, styles })))}`)

    return results
}
