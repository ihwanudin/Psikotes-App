// Playwright CLI run-code --filename entrypoint. Secrets stay in memory and are never returned or printed.
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression.
async (page) => {
    const appOrigin = 'https://psikotes.oncam.id'
    const sources = ['https://seleksi.beasiswajepang.id', 'https://seleksi.serbaindo.com']
    const foreignOrigin = 'https://foreign.invalid'
    const wrongHost = 'https://oncam.id'
    const siblingOrigin = 'https://sibling.oncam.id'
    const loopback = 'http://127.0.0.1:8126'
    const loginCookieName = 'oncam_checkout_browser_login'
    const selectorName = '__Secure-oncam_checkout_session'
    const csrfName = '__Secure-oncam_checkout_csrf'
    const sourceTokens = new Map()
    const sourceActions = new Map()
    const syntheticDocuments = new Map()
    const violations = []
    const consoleMessages = []
    const expectedHttpConsole = []
    let opaqueAttackInFlight = false
    let expectedSandboxInstrumentationErrors = 0
    const rejectedHttpStatuses = new Set()
    const safeNetwork = new Set()
    let exchangePosts = 0
    const listenerTasks = new Set()
    const observe = (work) => {
        const task = work().catch(() => violations.push('browser observation failed'))
        listenerTasks.add(task)
        void task.finally(() => listenerTasks.delete(task))
    }
    const drainObservations = async () => {
        while (listenerTasks.size > 0) await Promise.all([...listenerTasks])
    }

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
        context.on('request', (request) => observe(async () => {
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
        }))
        context.on('response', (response) => observe(async () => {
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
        }))
        await context.route('**/*', async (route) => {
            const request = route.request()
            const url = parseUrl(request.url())
            const document = syntheticDocuments.get(request.url())
            if (document && request.method() === 'GET') {
                return route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8',
                    headers: { 'Cache-Control': 'no-store, private', 'Referrer-Policy': 'no-referrer' }, body: document })
            }
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
    const observeConsole = (targetPage) => {
        targetPage.on('console', (message) => {
            if (['error', 'warning'].includes(message.type())) {
                const rejected = /^Failed to load resource: the server responded with a status of (403|404|419) \(.*\)$/.exec(message.text())
                if (message.type() === 'error' && rejected) {
                    expectedHttpConsole.push(Number(rejected[1]))
                    return
                }
                consoleMessages.push(message.text().replace(/och1_[0-9a-f]+|ocs(?:rf)?1_[0-9a-f]+/g, '[credential]'))
            }
        })
        targetPage.on('pageerror', (error) => {
            // Installed Playwright's serviceWorkers:block init script reads this forbidden getter in opaque frames.
            if (opaqueAttackInFlight && error.message === "Failed to read the 'serviceWorker' property from 'Navigator': Service worker is disabled because the context is sandboxed and lacks the 'allow-same-origin' flag.") {
                expectedSandboxInstrumentationErrors++
                return
            }
            consoleMessages.push('Uncaught browser page error')
        })
    }
    observeConsole(page)
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

    await drainObservations()
    assert(exchangePosts === 8, 'Browser did not exercise the eight preceding cross-site POSTs')

    // Native logout must preserve real Laravel login, reject hostile forms, and audit exactly once.
    const noJs = await page.context().browser().newContext({ javaScriptEnabled: false, ignoreHTTPSErrors: true, serviceWorkers: 'block' })
    let hostileForms = 0
    let opaqueNetworkBlocks = 0
    try {
        await installInterception(noJs)
        const noJsPage = await noJs.newPage()
        observeConsole(noJsPage)
        await noJsPage.goto(`${appOrigin}/__browser/login`)
        await noJsPage.goto(`${appOrigin}/__browser/auth`)
        assert(await noJsPage.locator('body').innerText() === 'AUTH:1', 'Native context login probe failed')
        const nativeLogin = (await noJs.cookies(appOrigin)).find((cookie) => cookie.name === loginCookieName)
        assert(nativeLogin, 'Native context has no real Laravel login cookie')
        await submit(noJsPage, sources[0], await issue('no-js'))
        await assertCheckoutPage(noJsPage)
        const nativeCookies = await checkoutCookies(noJs)
        assertCookieContract(nativeCookies)
        assert((await control('state', 'no-js')).logoutAudits === 0, 'Native fixture already has a LOGOUT audit')

        const formDocument = (field) => `<!doctype html><html><meta charset="utf-8"><title>Form sintetis</title>
            <form method="post" action="${appOrigin}/checkout/logout">${field}<button type="submit">Uji form</button></form></html>`
        for (const kind of ['foreign', 'opaque', 'sibling']) {
            for (const field of ['', `<input type="hidden" name="_checkout_csrf" value="ocsrf1_${'0'.repeat(64)}">`]) {
                const origin = kind === 'foreign' ? foreignOrigin : kind === 'sibling' ? siblingOrigin : appOrigin
                const url = `${origin}/__browser/attack-${kind}`
                const form = formDocument(field)
                syntheticDocuments.set(url, kind === 'opaque'
                    ? `<!doctype html><html><title>Sandbox sintetis</title><iframe sandbox="allow-forms" srcdoc="${form.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"></iframe></html>`
                    : form)
                await noJs.addCookies(nativeCookies)
                opaqueAttackInFlight = kind === 'opaque'
                await noJsPage.goto(url)
                const attackRequest = noJsPage.waitForRequest((request) => request.method() === 'POST')
                const attackResponse = Promise.race([
                    noJsPage.waitForResponse((item) => item.request().method() === 'POST').then((response) => ({ response })),
                    noJsPage.waitForEvent('requestfailed', { predicate: (request) => request.method() === 'POST' })
                        .then((request) => ({ failure: request.failure()?.errorText })),
                ])
                const button = kind === 'opaque' ? noJsPage.frameLocator('iframe').getByRole('button', { name: 'Uji form' })
                    : noJsPage.getByRole('button', { name: 'Uji form' })
                await button.click()
                const attackHeaders = await (await attackRequest).allHeaders()
                const outcome = await attackResponse
                assert(attackHeaders.origin === 'null', `Hostile ${kind} form did not exercise literal null Origin`)
                if (outcome.failure) {
                    assert(kind === 'opaque' && outcome.failure === 'net::ERR_BLOCKED_BY_LOCAL_NETWORK_ACCESS_CHECKS',
                        `Unexpected hostile ${kind} network failure`)
                    opaqueNetworkBlocks++
                } else {
                    const rejected = outcome.response
                    await rejected.finished()
                    assert(rejected.status() === 419 || (rejected.status() === 303
                        && ['/checkout/unavailable', `${appOrigin}/checkout/unavailable`].includes((await rejected.allHeaders()).location)),
                    `Hostile ${kind} form was not rejected`)
                }
                assert((await control('state', 'no-js')).logoutAudits === 0, `Hostile ${kind} form revoked the session`)
                await noJs.addCookies(nativeCookies)
                await noJsPage.goto(`${appOrigin}/checkout`)
                await assertCheckoutPage(noJsPage)
                opaqueAttackInFlight = false
                hostileForms++
            }
        }

        const nativeRequest = noJsPage.waitForRequest((request) => request.method() === 'POST')
        const nativeResponse = noJsPage.waitForResponse((item) => item.request().method() === 'POST')
        await noJsPage.getByRole('button', { name: 'Keluar' }).click()
        const nativeHeaders = await (await nativeRequest).allHeaders()
        const nativeResult = await nativeResponse
        await nativeResult.finished()
        assert(nativeHeaders.origin === 'null', 'Native logout did not exercise literal null Origin')
        assert(nativeResult.status() === 303, `Native no-JS logout must be 303; got ${nativeResult.status()}`)
        await noJsPage.waitForURL(`${appOrigin}/checkout/unavailable`)
        assert((await checkoutCookies(noJs)).length === 0, 'Native no-JS logout did not clear cookies')
        assert((await control('state', 'no-js')).logoutAudits === 1, 'Native logout did not produce exactly one LOGOUT audit')

        // A stale client replays the old pair and exact old form; it cannot create another logout.
        const oldCsrf = nativeCookies.find((cookie) => cookie.name === csrfName).value
        const replayUrl = `${appOrigin}/__browser/replay-logout`
        syntheticDocuments.set(replayUrl, formDocument(`<input type="hidden" name="_checkout_csrf" value="${oldCsrf}">`))
        await noJs.addCookies(nativeCookies)
        await noJsPage.goto(replayUrl)
        await noJsPage.getByRole('button', { name: 'Uji form' }).click()
        await noJsPage.waitForURL(`${appOrigin}/checkout/unavailable`)
        assert((await control('state', 'no-js')).logoutAudits === 1, 'Native replay duplicated the LOGOUT audit')
        assert((await checkoutCookies(noJs)).length === 0, 'Native replay did not clear stale cookies')
        assert((await noJs.cookies(appOrigin)).find((cookie) => cookie.name === loginCookieName)?.value === nativeLogin.value,
            'Native exchange/logout/replay changed the Laravel login cookie byte')
        await noJsPage.goto(`${appOrigin}/__browser/auth`)
        assert(await noJsPage.locator('body').innerText() === 'AUTH:1', 'Native logout lost actual Laravel login authority')
        await drainObservations()
    } finally {
        await drainObservations()
        await noJs.close()
    }
    await drainObservations()
    assert(exchangePosts === 9, 'Browser did not exercise all canonical exchanges')
    assert(hostileForms === 6, 'Browser did not complete the hostile native forms')
    assert(violations.length === 0, `Browser boundary violations: ${JSON.stringify(violations)}`)
    assert(consoleMessages.length === 0, `Browser console was not clean: ${JSON.stringify(consoleMessages)}`)
    assert(expectedHttpConsole.every((status) => rejectedHttpStatuses.has(status)), 'Unexplained HTTP console error')
    assert([...safeNetwork].every((value) => [appOrigin, wrongHost, ...sources, foreignOrigin, siblingOrigin]
        .some((origin) => value.startsWith(origin))), 'Network escaped controlled origins')

    return {
        checks: [
            'two controlled cross-site origins and exact body-only exchange',
            'real Laravel Lax login cookie omitted on POST and authority preserved',
            'exact host-only Secure HttpOnly Lax checkout cookies and private headers',
            'fixation/replay/history/refresh/multi-tab/recovery fenced',
            'CSRF projection, invalid channels, progressive and no-JS logout',
            'six foreign/opaque/sibling native forms denied; one LOGOUT audit and real login preserved',
            'expiry and scope revocation clear credentials',
            'wrong origin and host fail closed',
            'desktop 1280, mobile 390/320, keyboard, no unexpected console/network errors; opaque limits counted',
        ],
        exchangePosts,
        hostileForms,
        opaqueNetworkBlocks,
        expectedSandboxInstrumentationErrors,
        controlledNetworkEntries: safeNetwork.size,
        credentialMaterialRecorded: false,
        screenshotsContainingCredentials: 0,
    }
}
