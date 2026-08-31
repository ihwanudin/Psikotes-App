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
        assert(
            (
                await page
                    .getByRole('list', { name: 'Daftar tes' })
                    .textContent()
            ).includes('locked'),
            `${name}: locked entitlement changed`,
        )
        assert(
            (
                await page
                    .getByRole('list', { name: 'Daftar tes' })
                    .textContent()
            ).includes('Siap'),
            `${name}: ready entitlement changed`,
        )
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
    assert(errors.length === 0, `Browser errors: ${errors.join('; ')}`)

    return results
}
