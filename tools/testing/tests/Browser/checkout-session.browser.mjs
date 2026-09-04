// Playwright CLI run-code --filename entrypoint. Secrets stay in memory and are never returned or printed.
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression.
async (page) => {
    const appOrigin = 'https://psikotes.oncam.id'
    const sources = ['https://seleksi.beasiswajepang.id', 'https://seleksi.serbaindo.com']
    const foreignOrigin = 'https://foreign.invalid'
    const wrongHost = 'https://oncam.id'
    const loopback = 'http://127.0.0.1:8126'
    const loginCookieName = 'oncam_checkout_browser_login'
    const selectorName = '__Secure-oncam_checkout_session'
    const csrfName = '__Secure-oncam_checkout_csrf'
    const sourceTokens = new Map()
    const sourceActions = new Map()
    const violations = []
    const consoleMessages = []
    const expectedHttpConsole = []
    const rejectedHttpStatuses = new Set()
    const safeNetwork = new Set()
    let exchangePosts = 0

    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message)
        }
    }
    const parseUrl = (value) => {
        const match = /^(https?):\/\/([^/?#]+)(\/[^?#]*)?(\?[^#]*)?/.exec(value)
        assert(match, 'Browser produced an invalid absolute URL')

        return {
            origin: `${match[1]}://${match[2]}`,
            host: match[2],
            pathname: match[3] || '/',
            search: match[4] || '',
        }
    }
    const privateHeaders = async (response) => {
        const headers = await response.allHeaders()
        const cache = headers['cache-control'] || ''

        return cache.includes('no-store') && cache.includes('private')
            && headers.pragma === 'no-cache'
            && headers['referrer-policy'] === 'no-referrer'
            && headers['x-frame-options'] === 'DENY'
            && headers['x-content-type-options'] === 'nosniff'
            && headers['content-security-policy'] === "default-src 'self'; base-uri 'none'; frame-ancestors 'none'"
    }
    const sourceDocument = (origin) => {
        const token = sourceTokens.get(origin)
        const action = sourceActions.get(origin) || `${appOrigin}/checkout/session`
        assert(typeof token === 'string', `Synthetic source ${origin} has no in-memory bearer`)

        return `<!doctype html><html lang="id"><meta charset="utf-8"><title>Source sintetis</title>
            <body><main><h1>Lanjutkan checkout</h1><form method="post" action="${action}">
            <input type="hidden" name="handoffToken" value="${token}">
            <button type="submit">Lanjutkan</button></form></main></body></html>`
    }
    const installInterception = async (context) => {
        context.on('request', async (request) => {
            const url = parseUrl(request.url())
            safeNetwork.add(`${url.origin}${url.pathname}`)
            const requestHeaders = await request.allHeaders()
            if (/och1_|ocs1_|ocsrf1_/.test(request.url() + (requestHeaders.referer || ''))) {
                violations.push('credential entered URL or Referer')
            }
            if (url.origin === appOrigin && url.pathname.startsWith('/__browser/')
                && /__Secure-oncam_checkout_(session|csrf)=/.test(requestHeaders.cookie || '')) {
                violations.push('path-scoped checkout cookie reached Laravel auth route')
            }
            if (url.origin === appOrigin && url.pathname === '/checkout/session' && request.method() === 'POST') {
                exchangePosts++
                const headers = await request.allHeaders()
                const body = request.postData() || ''
                if (!/^handoffToken=och1_[0-9a-f]{64}$/.test(body)) {
                    violations.push('exchange body was not one canonical bearer field')
                }
                if ((headers.cookie || '').includes(`${loginCookieName}=`)) {
                    violations.push('Lax Laravel login cookie was sent on cross-site POST')
                }
                if (url.search !== '') {
                    violations.push('exchange URL contained a query')
                }
            }
        })
        context.on('response', async (response) => {
            const url = parseUrl(response.url())
            if (url.origin === appOrigin && url.pathname.startsWith('/checkout')) {
                if (!(await privateHeaders(response))) {
                    violations.push(`privacy headers missing on ${url.pathname}:${response.status()}`)
                }
                const headers = await response.headersArray()
                if (headers.some(({ name, value }) => name.toLowerCase() === 'set-cookie'
                    && value.startsWith(`${loginCookieName}=`))) {
                    violations.push('checkout response wrote the Laravel login cookie')
                }
            }
            if ([appOrigin, wrongHost].includes(url.origin) && [403, 404, 419].includes(response.status())) {
                rejectedHttpStatuses.add(response.status())
            }
        })
        await context.route('**/*', async (route) => {
            const request = route.request()
            const url = parseUrl(request.url())
            if ([...sources, foreignOrigin].includes(url.origin)) {
                if (request.method() !== 'GET' || url.pathname !== '/handoff') {
                    violations.push(`unexpected synthetic source request ${request.method()} ${url.pathname}`)

                    return route.abort()
                }

                return route.fulfill({
                    status: 200,
                    contentType: 'text/html; charset=UTF-8',
                    headers: { 'Cache-Control': 'no-store, private', 'Referrer-Policy': 'strict-origin-when-cross-origin' },
                    body: sourceDocument(url.origin),
                })
            }
            if (![appOrigin, wrongHost].includes(url.origin)) {
                violations.push(`blocked non-loopback origin ${url.origin}`)

                return route.abort()
            }
            // Chromium supplies Cookie/Origin/Referer unchanged; TLS bridge owns proxy headers.
            return route.continue()
        })
    }
    const control = async (operation, alias) => {
        const response = await page.request.post(`${loopback}/__browser/control/${operation}`, {
            headers: { Host: 'psikotes.oncam.id', 'X-Browser-Harness': 'synthetic-only' },
            data: { alias },
        })
        assert(response.ok(), `Synthetic control ${operation} failed (${response.status()})`)

        if (response.status() === 204) {
            return null
        }

        return response.json()
    }
    const issue = async (alias, recovery = false) => {
        const payload = await control(recovery ? 'recover' : 'issue', alias)
        assert(typeof payload.handoffToken === 'string', 'Synthetic issue omitted bearer')

        return payload.handoffToken
    }
    const submit = async (targetPage, origin, token, action = `${appOrigin}/checkout/session`) => {
        sourceTokens.set(origin, token)
        sourceActions.set(origin, action)
        await targetPage.goto(`${origin}/handoff`)
        assert(targetPage.url() === `${origin}/handoff`, 'Browser did not retain the controlled source origin')
        await targetPage.getByRole('button', { name: 'Lanjutkan' }).click()
        await targetPage.waitForLoadState('domcontentloaded')
    }
    const checkoutCookies = async (context) => (await context.cookies(`${appOrigin}/checkout`))
        .filter((cookie) => [selectorName, csrfName].includes(cookie.name))
    const assertCookieContract = (cookies) => {
        assert(cookies.length === 2, 'Checkout cookie pair missing')
        for (const cookie of cookies) {
            assert(cookie.domain === 'psikotes.oncam.id', 'Checkout cookie is not host-only on the fixed host')
            assert(cookie.path === '/checkout', 'Checkout cookie path mismatch')
            assert(cookie.secure && cookie.httpOnly && cookie.sameSite === 'Lax', 'Checkout cookie flags mismatch')
            assert(cookie.expires > Date.now() / 1000, 'Checkout cookie expiry is not bounded in the future')
        }
    }
    const assertSafeLocation = (targetPage) => {
        const value = targetPage.url()
        assert(!value.includes('och1_') && !value.includes('ocs1_') && !value.includes('ocsrf1_'), 'Credential entered URL/history')
    }
    const assertCheckoutPage = async (targetPage) => {
        await targetPage.waitForURL(`${appOrigin}/checkout`)
        assertSafeLocation(targetPage)
        assert(await targetPage.locator('meta[name="checkout-csrf-token"]').count() === 1, 'CSRF meta projection missing')
        assert(await targetPage.locator('input[name="_checkout_csrf"]').count() === 1, 'CSRF hidden projection missing')
        const meta = await targetPage.locator('meta[name="checkout-csrf-token"]').getAttribute('content')
        const hidden = await targetPage.locator('input[name="_checkout_csrf"]').getAttribute('value')
        assert(typeof meta === 'string' && meta === hidden && /^ocsrf1_[0-9a-f]{64}$/.test(meta), 'CSRF projection mismatch')
    }
    const mutation = async (targetPage, headers = {}, body = undefined) => {
        const responsePromise = targetPage.waitForResponse((response) => {
            const url = parseUrl(response.url())

            return url.origin === appOrigin && url.pathname === '/checkout/logout'
                && response.request().method() === 'POST'
        })
        await targetPage.evaluate(async ({ headers: requestHeaders, body: requestBody }) => {
            await fetch('/checkout/logout', { method: 'POST', headers: requestHeaders, body: requestBody })
        }, { headers, body })

        return responsePromise
    }

    page.setDefaultTimeout(30000)
    page.setDefaultNavigationTimeout(30000)
    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) {
            const rejected = /^Failed to load resource: the server responded with a status of (403|404|419) \(.*\)$/.exec(message.text())
            if (message.type() === 'error' && rejected) {
                expectedHttpConsole.push(Number(rejected[1]))
                return
            }
            consoleMessages.push(message.text().replace(/och1_[0-9a-f]+|ocs(?:rf)?1_[0-9a-f]+/g, '[credential]'))
        }
    })
    page.on('pageerror', (error) => consoleMessages.push(error.message))
    await installInterception(page.context())

    // A real encrypted Laravel session remains authoritative around both cross-site exchanges.
    await page.goto(`${appOrigin}/__browser/login`)
    const authProbe = await page.evaluate(async () => {
        const response = await fetch('/__browser/auth', { credentials: 'same-origin' })

        return { status: response.status, body: await response.text() }
    })
    assert(authProbe.status === 200 && authProbe.body === 'AUTH:1', `Real Laravel login did not authorize exact synthetic principal (${authProbe.status})`)
    let loginBefore = (await page.context().cookies(appOrigin)).find((cookie) => cookie.name === loginCookieName)
    assert(loginBefore && loginBefore.secure && loginBefore.sameSite === 'Lax', 'Real Laravel login cookie contract missing')

    const firstToken = await issue('first')
    const fixedSelector = `ocs1_${'a'.repeat(64)}`
    const fixedCsrf = `ocsrf1_${'b'.repeat(64)}`
    await page.context().addCookies([
        { name: selectorName, value: fixedSelector, domain: 'psikotes.oncam.id', path: '/checkout', secure: true, httpOnly: true, sameSite: 'Lax' },
        { name: csrfName, value: fixedCsrf, domain: 'psikotes.oncam.id', path: '/checkout', secure: true, httpOnly: true, sameSite: 'Lax' },
    ])
    await submit(page, sources[0], firstToken)
    await assertCheckoutPage(page)
    let credentials = await checkoutCookies(page.context())
    assertCookieContract(credentials)
    assert(credentials.every((cookie) => ![fixedSelector, fixedCsrf].includes(cookie.value)), 'Fixation cookie survived exchange')
    await page.reload()
    await assertCheckoutPage(page)
    await page.goBack()
    assertSafeLocation(page)
    await page.goto(`${appOrigin}/checkout`)
    await assertCheckoutPage(page)

    await submit(page, sources[0], firstToken)
    await page.waitForURL(`${appOrigin}/checkout/unavailable`)
    assertSafeLocation(page)
    await page.goto(`${appOrigin}/checkout`)
    await assertCheckoutPage(page)

    const secondToken = await issue('second')
    await submit(page, sources[1], secondToken)
    await assertCheckoutPage(page)
    credentials = await checkoutCookies(page.context())
    assertCookieContract(credentials)
    const loginAfterExchange = (await page.context().cookies(appOrigin)).find((cookie) => cookie.name === loginCookieName)
    assert(loginAfterExchange?.value === loginBefore.value, 'Laravel login cookie byte changed during exchange')
    await page.goto(`${appOrigin}/__browser/auth`)
    assert((await page.locator('body').innerText()) === 'AUTH:1', 'Laravel login authority changed after exchange')
    // The intentional web probe re-encrypts its own cookie; checkout must preserve this new byte too.
    loginBefore = (await page.context().cookies(appOrigin)).find((cookie) => cookie.name === loginCookieName)
    await page.goto(`${appOrigin}/checkout`)
    await assertCheckoutPage(page)

    // Tabs share one principal; page remains usable at desktop/mobile widths and by keyboard.
    const secondTab = await page.context().newPage()
    await secondTab.goto(`${appOrigin}/checkout`)
    await assertCheckoutPage(secondTab)
    const firstPublic = await page.locator('main').getAttribute('data-checkout-session')
    const secondPublic = await secondTab.locator('main').getAttribute('data-checkout-session')
    assert(firstPublic === secondPublic, 'Tabs did not share the exact checkout principal')
    await secondTab.close()
    for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }, { width: 320, height: 720 }]) {
        await page.setViewportSize(viewport)
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)
        assert(overflow <= 2, `Checkout page overflowed at ${viewport.width}px`)
    }
    await page.keyboard.press('Tab')
    assert(await page.getByRole('button', { name: 'Keluar' }).evaluate((element) => element === document.activeElement),
        'Keyboard did not reach logout')

    // Missing, wrong and duplicate mutation credentials are rejected without losing the session.
    let response = await mutation(page)
    assert(response.status() === 419, 'Missing explicit CSRF did not fail 419')
    response = await mutation(page, { 'X-Checkout-CSRF': `ocsrf1_${'0'.repeat(64)}` })
    assert(response.status() === 419, 'Wrong progressive CSRF did not fail 419')
    const projected = await page.locator('meta[name="checkout-csrf-token"]').getAttribute('content')
    response = await mutation(page, { 'Content-Type': 'application/x-www-form-urlencoded' },
        `_checkout_csrf=${projected}&_checkout_csrf=${projected}`)
    assert(response.status() === 419, 'Duplicate form CSRF did not fail 419')
    await page.reload()
    await assertCheckoutPage(page)

    // Progressive header logout uses the page-local projection and clears only checkout cookies.
    const progressiveResponse = page.waitForResponse((item) => parseUrl(item.url()).pathname === '/checkout/logout'
        && item.request().method() === 'POST')
    await page.evaluate(async () => {
        const csrf = document.querySelector('meta[name="checkout-csrf-token"]').getAttribute('content')
        await fetch('/checkout/logout', { method: 'POST', headers: { 'X-Checkout-CSRF': csrf } })
    })
    assert((await progressiveResponse).status() === 303, 'Progressive logout did not redirect')
    assert((await checkoutCookies(page.context())).length === 0, 'Progressive logout did not clear checkout cookies')
    assert((await page.context().cookies(appOrigin)).find((cookie) => cookie.name === loginCookieName)?.value === loginBefore.value,
        'Progressive logout changed Laravel login cookie')

    // Natural expiry and persisted scope revocation clear both credentials.
    const expiryToken = await issue('expiry')
    await submit(page, sources[0], expiryToken)
    await assertCheckoutPage(page)
    await control('expire', 'expiry')
    await page.reload()
    await page.waitForURL(`${appOrigin}/checkout/unavailable`)
    assert((await checkoutCookies(page.context())).length === 0, 'Expired session cookies were not cleared')

    const revokeToken = await issue('revoke')
    await submit(page, sources[1], revokeToken)
    await assertCheckoutPage(page)
    await control('revoke', 'revoke')
    await page.reload()
    await page.waitForURL(`${appOrigin}/checkout/unavailable`)
    assert((await checkoutCookies(page.context())).length === 0, 'Scope-revoked session cookies were not cleared')

    // Delivery-loss simulation plus P13 recovery fences the old cookie pair and establishes a new generation.
    const orphanToken = await issue('orphan')
    await submit(page, sources[0], orphanToken)
    await assertCheckoutPage(page)
    const orphanCookies = await checkoutCookies(page.context())
    await page.context().clearCookies()
    await page.context().addCookies([loginBefore])
    const recoveryToken = await issue('orphan', true)
    await page.context().addCookies(orphanCookies)
    await page.goto(`${appOrigin}/checkout`)
    await page.waitForURL(`${appOrigin}/checkout/unavailable`)
    assert((await checkoutCookies(page.context())).length === 0, 'Recovery did not fence old checkout credentials')
    await submit(page, sources[1], recoveryToken)
    await assertCheckoutPage(page)

    // Actual foreign Origin and wrong HTTPS host are rejected through the intercepted browser path.
    const foreignToken = await issue('foreign-origin')
    await submit(page, foreignOrigin, foreignToken)
    assert(page.url() === `${appOrigin}/checkout/session`, 'Foreign Origin did not stay on generic exchange rejection')
    assert((await page.locator('body').innerText()) === 'Forbidden', 'Foreign Origin response was not generic')
    const wrongHostToken = await issue('wrong-host')
    await submit(page, sources[0], wrongHostToken, `${wrongHost}/checkout/session`)
    assert(page.url() === `${wrongHost}/checkout/session`, 'Wrong host was not exercised by the browser')
    assert((await page.locator('body').innerText()) === 'Not Found', 'Wrong host did not fail closed')

    await page.waitForTimeout(100)
    assert(exchangePosts === 8, 'Browser did not exercise the eight preceding cross-site POSTs')
    assert(violations.length === 0, `Browser boundary violations: ${JSON.stringify(violations)}`)
    assert(consoleMessages.length === 0, `Browser console was not clean: ${JSON.stringify(consoleMessages)}`)
    assert(expectedHttpConsole.every((status) => rejectedHttpStatuses.has(status)), 'Unexplained HTTP console error')
    assert([...safeNetwork].every((value) => [appOrigin, wrongHost, ...sources, foreignOrigin]
        .some((origin) => value.startsWith(origin))), 'Network escaped controlled origins')

    // Keep native form acceptance strict and last so independent cases run even if it regresses.
    const noJs = await page.context().browser().newContext({ javaScriptEnabled: false, ignoreHTTPSErrors: true, serviceWorkers: 'block' })
    await installInterception(noJs)
    const noJsPage = await noJs.newPage()
    const noJsToken = await issue('no-js')
    await submit(noJsPage, sources[0], noJsToken)
    await assertCheckoutPage(noJsPage)
    assert(exchangePosts === 9, 'Browser did not exercise the native form exchange')
    const nativeRequest = noJsPage.waitForRequest((request) => request.method() === 'POST')
    const nativeResponse = noJsPage.waitForResponse((response) => response.request().method() === 'POST')
    await noJsPage.getByRole('button', { name: 'Keluar' }).click()
    const nativeHeaders = await (await nativeRequest).allHeaders()
    const nativeStatus = (await nativeResponse).status()
    assert(nativeStatus === 303,
        `Native no-JS logout must be 303; got ${nativeStatus}, Origin=${nativeHeaders.origin || 'absent'}; preceding browser cases passed`)
    await noJsPage.waitForURL(`${appOrigin}/checkout/unavailable`)
    assert((await checkoutCookies(noJs)).length === 0, 'Native no-JS logout did not clear cookies')
    await noJs.close()
    assert(violations.length === 0, `Browser boundary violations: ${JSON.stringify(violations)}`)

    return {
        checks: [
            'two controlled cross-site origins and exact body-only exchange',
            'real Laravel Lax login cookie omitted on POST and authority preserved',
            'exact host-only Secure HttpOnly Lax checkout cookies and private headers',
            'fixation/replay/history/refresh/multi-tab/recovery fenced',
            'CSRF projection, invalid channels, progressive and no-JS logout',
            'expiry and scope revocation clear credentials',
            'wrong origin and host fail closed',
            'desktop 1280, mobile 390/320, keyboard, console and network clean',
        ],
        exchangePosts,
        controlledNetworkEntries: safeNetwork.size,
        credentialMaterialRecorded: false,
        screenshotsContainingCredentials: 0,
    }
}
