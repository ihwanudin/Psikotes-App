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
    const historyObservations = []
    let checkoutDocumentResponses = 0
    let exchangePosts = 0
    let paymentPosts = 0
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
            if (url.origin === appOrigin && url.pathname === '/checkout/payment' && request.method() === 'POST') {
                paymentPosts++
            }
        }))
        context.on('response', (response) => observe(async () => {
            const url = parseUrl(response.url())
            if (url.origin === appOrigin && url.pathname.startsWith('/checkout')) {
                if (url.pathname === '/checkout' && response.request().isNavigationRequest()) checkoutDocumentResponses++
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
            if (url.pathname.startsWith('/__browser/control/')) {
                violations.push('browser navigation attempted driver control')
                return route.abort()
            }
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
    // Test-local contract assertion only; never shipped as a client parser or authorization policy.
    const validateSummary = (summary) => {
        const exact = (value, keys, label) => assert(value !== null && typeof value === 'object' && !Array.isArray(value)
            && JSON.stringify(Object.keys(value)) === JSON.stringify(keys), `${label} keys differ`)
        const text = (value) => typeof value === 'string' && value.trim() !== ''
        exact(summary, ['contractVersion', 'sourceName', 'branchName', 'packageName', 'packageSource', 'attemptLabel',
            'profile', 'identityMessage', 'payment', 'access', 'consents'], 'summary')
        exact(summary.access, ['state', 'tests', 'startAvailable', 'message'], 'access')
        exact(summary.consents, ['psychotest', 'dass', 'legalReviewPending'], 'consents')
        exact(summary.payment, ['payer', 'state', 'amountIdr', 'amountSource', 'consultationRequested', 'actionAvailable', 'action',
            ...(summary.payment.payer === 'organization' ? ['organizationName'] : [])], 'payment')
        const safeAmount = (value) => Number.isSafeInteger(value) && value >= 0
        const validateChoice = (choice) => {
            exact(choice, ['consultationRequested', 'baseAmountIdr', 'consultationAmountIdr', 'amountIdr'], 'payment choice')
            assert(typeof choice.consultationRequested === 'boolean' && safeAmount(choice.baseAmountIdr)
                && safeAmount(choice.consultationAmountIdr) && safeAmount(choice.amountIdr)
                && choice.baseAmountIdr <= Number.MAX_SAFE_INTEGER - choice.consultationAmountIdr
                && choice.amountIdr === choice.baseAmountIdr + choice.consultationAmountIdr
                && (choice.consultationRequested ? choice.consultationAmountIdr > 0 : choice.consultationAmountIdr === 0),
            'Payment choice differs')
        }
        const validateAction = (action) => {
            exact(action, ['path', 'mode', 'currency', 'choices'], 'payment action')
            assert(action.path === '/checkout/payment' && ['select', 'continue'].includes(action.mode)
                && action.currency === 'IDR' && Array.isArray(action.choices)
                && action.choices.length >= 1 && action.choices.length <= 2
                && (action.mode !== 'continue' || action.choices.length === 1), 'Payment action differs')
            action.choices.forEach(validateChoice)
            const flags = action.choices.map((choice) => choice.consultationRequested)
            assert(new Set(flags).size === flags.length && !(flags[0] === true && flags[1] === false),
                'Payment choice order differs')
        }
        for (const key of ['sourceName', 'branchName', 'packageName', 'attemptLabel', 'identityMessage']) {
            assert(text(summary[key]), 'Summary label type differs')
        }
        assert(['catalog', 'charge_snapshot'].includes(summary.packageSource), 'Package provenance differs')
        assert(['unselected', 'self', 'organization'].includes(summary.payment.payer), 'Payer differs')
        assert(['unselected', 'unpaid', 'unbilled', 'preparing', 'pending', 'recovery_required', 'expired', 'rejected', 'paid', 'free']
            .includes(summary.payment.state), 'Payment state differs')
        if (summary.payment.payer === 'organization') {
            assert(summary.payment.organizationName === summary.branchName, 'Organization label differs')
        }
        if (summary.packageSource === 'catalog') {
            assert(summary.payment.amountSource === 'unavailable' && summary.payment.amountIdr === null
                && summary.payment.consultationRequested === null, 'Unavailable amount correlation differs')
        } else {
            assert(summary.payment.amountSource === 'charge_snapshot' && safeAmount(summary.payment.amountIdr)
                && typeof summary.payment.consultationRequested === 'boolean', 'Snapshot amount correlation differs')
        }
        assert(summary.payment.actionAvailable === (summary.payment.action !== null), 'Payment capability correlation differs')
        if (summary.payment.actionAvailable) {
            validateAction(summary.payment.action)
            const action = summary.payment.action
            if (summary.payment.payer === 'self' && action.mode === 'select') {
                assert(summary.payment.state === 'unpaid' && summary.payment.amountSource === 'unavailable',
                    'Self selection capability differs')
            } else if (summary.payment.payer === 'self' && action.mode === 'continue') {
                const choice = action.choices[0]
                assert(summary.payment.state === 'pending' && summary.payment.amountSource === 'charge_snapshot'
                    && summary.payment.amountIdr === choice.amountIdr
                    && summary.payment.consultationRequested === choice.consultationRequested,
                'Self continuation capability differs')
            } else {
                const choice = action.choices[0]
                assert(summary.payment.payer === 'organization' && summary.payment.state === 'unbilled'
                    && summary.payment.amountSource === 'unavailable' && action.mode === 'select'
                    && action.choices.length === 1 && choice.consultationRequested === false
                    && choice.baseAmountIdr === 0 && choice.consultationAmountIdr === 0 && choice.amountIdr === 0,
                'Organization zero-price capability differs')
            }
        }
        assert(Array.isArray(summary.profile) && summary.profile.length === 7, 'Profile collection differs')
        const profileKeys = ['fullName', 'birthDate', 'gender', 'educationLevel', 'intendedField', 'email', 'phone']
        assert(JSON.stringify(summary.profile.map((field) => field.key)) === JSON.stringify(profileKeys), 'Profile field order differs')
        for (const field of summary.profile) {
            exact(field, ['key', 'label', 'state', 'required', ...(field.state === 'locked' ? ['displayValue'] : [])], 'profile field')
            assert(['locked', 'missing'].includes(field.state) && text(field.label)
                && field.required === (field.key !== 'email') && (field.state !== 'locked' || text(field.displayValue)), 'Profile types differ')
        }
        assert(Array.isArray(summary.access.tests) && summary.access.tests.length >= 1 && summary.access.tests.length <= 5, 'Test collection differs')
        const types = summary.access.tests.map((test) => test.testType)
        assert(new Set(types).size === types.length && JSON.stringify([...types].sort()) === JSON.stringify(types), 'Test canonical order differs')
        assert(types.includes('dass21') && types.some((type) => type !== 'dass21'),
            'Mandatory DASS-21 plus psychotest composition missing')
        for (const test of summary.access.tests) {
            exact(test, ['testType', 'state'], 'test')
            assert(['dass21', 'ist', 'kraepelin', 'papi', 'rmib'].includes(test.testType)
                && ['locked', 'ready'].includes(test.state), 'Test state differs')
        }
        const ready = summary.access.tests.filter((test) => test.state === 'ready').length
        const expectedAccess = ready === types.length ? 'ready' : ready === 0 ? 'locked' : 'partial'
        const accessMessages = { ready: 'Prasyarat akses tes terpenuhi; mesin sesi belum tersedia.', partial: 'Sebagian akses tes belum siap.', locked: 'Akses tes belum siap.' }
        assert(summary.access.state === expectedAccess && summary.access.message === accessMessages[expectedAccess], 'Access aggregate differs')
        for (const key of ['psychotest', 'dass']) {
            const consent = summary.consents[key]
            exact(consent, consent.state === 'accepted' ? ['state', 'version']
                : consent.state === 'required' ? ['state', 'document'] : ['state'], 'consent')
            assert(['accepted', 'required'].includes(consent.state), 'Consent state differs')
            if (consent.state === 'accepted') assert(text(consent.version), 'Accepted version differs')
            if (consent.state === 'required') {
                exact(consent.document, ['version', 'title', 'text'], 'consent document')
                assert(Object.values(consent.document).every(text), 'Document types differ')
            }
        }
        assert(typeof summary.consents.legalReviewPending === 'boolean', 'Legal state type differs')
        const encoded = JSON.stringify(summary)
        assert(!/och1_|ocs1_|ocsrf1_|PRIVATE_OTHER_PROFILE|PRIVATE_GATEWAY|PRIVATE_INVOICE/.test(encoded), 'Summary JSON leaks forbidden data')
        assert(summary.contractVersion === 'checkout-summary-v2' && summary.sourceName === 'Integrasi seleksi', 'Summary version/source mismatch')
        assert(summary.access.startAvailable === false, 'Summary invented assessment start authority')
        assert(Array.isArray(summary.profile) && summary.profile.length === 7 && Array.isArray(summary.access.tests), 'Summary collections mismatch')
    }
    const assertCheckoutPage = async (targetPage) => {
        await targetPage.waitForURL(`${appOrigin}/checkout`)
        assertSafeLocation(targetPage)
        assert(await targetPage.locator('meta[name="checkout-csrf-token"]').count() === 1, 'CSRF meta projection missing')
        assert(await targetPage.locator('input[name="_checkout_csrf"]').count() === 1, 'CSRF hidden projection missing')
        const meta = await targetPage.locator('meta[name="checkout-csrf-token"]').getAttribute('content')
        const hidden = await targetPage.locator('input[name="_checkout_csrf"]').getAttribute('value')
        assert(typeof meta === 'string' && meta === hidden && /^ocsrf1_[0-9a-f]{64}$/.test(meta), 'CSRF projection mismatch')
        const scripts = targetPage.locator('script')
        assert(await scripts.count() === 1, 'Summary must contain one inert script only')
        const script = scripts.first()
        assert(await script.getAttribute('type') === 'application/json'
            && await script.getAttribute('id') === 'checkout-summary-v2'
            && await script.getAttribute('src') === null, 'Summary JSON is not inert')
        const summary = JSON.parse(await script.textContent())
        validateSummary(summary)
        assert(summary.payment.actionAvailable === false && summary.payment.action === null,
            'Default-off synthetic fixture exposed payment authority')
        assert(await targetPage.locator('script:not([type="application/json"]), script[src], [onerror], img').count() === 0,
            'Summary created executable content')
        const body = await targetPage.locator('body').innerText()
        for (const forbidden of ['PRIVATE_OTHER_PROFILE', 'PRIVATE_GATEWAY', 'PRIVATE_INVOICE', 'ocs1_', 'ocsrf1_', 'och1_']) {
            assert(!body.includes(forbidden), 'Summary exposed a forbidden marker')
        }

        return summary
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

    // Called with null by the no-browser Node probe; no page/context/request API is touched.
    if (page === null) {
        const sample = () => ({
            contractVersion: 'checkout-summary-v2', sourceName: 'Integrasi seleksi', branchName: 'Synthetic',
            packageName: 'Synthetic', packageSource: 'catalog', attemptLabel: 'Assessment Anda',
            profile: ['fullName', 'birthDate', 'gender', 'educationLevel', 'intendedField', 'email', 'phone']
                .map((key) => ({ key, label: key, state: 'missing', required: key !== 'email' })),
            identityMessage: 'Kelengkapan profil tidak menggantikan verifikasi identitas.',
            payment: { payer: 'unselected', state: 'unselected', amountIdr: null, amountSource: 'unavailable', consultationRequested: null, actionAvailable: false, action: null },
            access: { state: 'locked', tests: [{ testType: 'dass21', state: 'locked' }, { testType: 'ist', state: 'locked' }], startAvailable: false, message: 'Akses tes belum siap.' },
            consents: { psychotest: { state: 'accepted', version: 'synthetic-v1' }, dass: { state: 'accepted', version: 'synthetic-v1' }, legalReviewPending: true },
        })
        const snapshot = (s, amount = 100) => {
            s.packageSource = 'charge_snapshot'
            s.payment.payer = 'self'
            s.payment.state = 'unpaid'
            s.payment.amountIdr = amount
            s.payment.amountSource = 'charge_snapshot'
            s.payment.consultationRequested = false
        }
        const selfSelectPayment = () => ({ payer: 'self', state: 'unpaid', amountIdr: null, amountSource: 'unavailable',
            consultationRequested: null, actionAvailable: true, action: { path: '/checkout/payment', mode: 'select', currency: 'IDR', choices: [
                { consultationRequested: false, baseAmountIdr: 99000, consultationAmountIdr: 0, amountIdr: 99000 },
                { consultationRequested: true, baseAmountIdr: 99000, consultationAmountIdr: 50000, amountIdr: 149000 },
            ] } })
        const selfContinuePayment = () => ({ payer: 'self', state: 'pending', amountIdr: 149000, amountSource: 'charge_snapshot',
            consultationRequested: true, actionAvailable: true, action: { path: '/checkout/payment', mode: 'continue', currency: 'IDR', choices: [
                { consultationRequested: true, baseAmountIdr: 99000, consultationAmountIdr: 50000, amountIdr: 149000 },
            ] } })
        const organizationZeroPayment = () => ({ payer: 'organization', state: 'unbilled', amountIdr: null, amountSource: 'unavailable',
            consultationRequested: null, actionAvailable: true, action: { path: '/checkout/payment', mode: 'select', currency: 'IDR', choices: [
                { consultationRequested: false, baseAmountIdr: 0, consultationAmountIdr: 0, amountIdr: 0 },
            ] }, organizationName: 'Synthetic' })
        const terminalOrRecoveryActionCases = ['recovery_required', 'expired', 'rejected', 'paid', 'free']
            .flatMap((state) => [
                [`self-select-${state}`, (s) => { s.payment = selfSelectPayment(); s.payment.state = state }],
                [`self-continue-${state}`, (s) => { s.packageSource = 'charge_snapshot'; s.payment = selfContinuePayment(); s.payment.state = state }],
            ])
        const malformed = [
            ['unknown-test', (s) => { s.access.tests[0].testType = 'unknown' }],
            ['empty-tests', (s) => { s.access.tests = [] }],
            ['duplicate-tests', (s) => { s.access.tests.push({ ...s.access.tests[0] }) }],
            ['unsorted-tests', (s) => { s.access.tests.unshift({ testType: 'ist', state: 'locked' }) }],
            ['unknown-access', (s) => { s.access.state = 'unknown' }],
            ['access-count', (s) => { s.access.state = 'ready' }],
            ['unknown-payer', (s) => { s.payment.payer = 'other' }],
            ['unknown-payment-state', (s) => { s.payment.state = 'other' }],
            ['amount-negative', (s) => { s.payment.amountIdr = -1 }],
            ['amount-fraction', (s) => { s.payment.amountIdr = 0.5 }],
            ['amount-unsafe', (s) => { s.payment.amountIdr = 9007199254740992 }],
            ['amount-string', (s) => { s.payment.amountIdr = '100' }],
            ['amount-provenance', (s) => { s.payment.amountSource = 'charge_snapshot' }],
            ['amount-unknown-source', (s) => { s.payment.amountSource = 'other' }],
            ['consultation-null-correlation', (s) => { s.payment.consultationRequested = false }],
            ['consultation-type', (s) => { s.payment.consultationRequested = 'false' }],
            ['package-provenance', (s) => { s.packageSource = 'charge_snapshot' }],
            ['package-unknown-source', (s) => { s.packageSource = 'other' }],
            ['psychotest-not-applicable', (s) => { s.consents.psychotest = { state: 'not_applicable' } }],
            ['accepted-version-type', (s) => { s.consents.psychotest.version = 1 }],
            ['accepted-version-empty', (s) => { s.consents.psychotest.version = '' }],
            ['dass-not-applicable', (s) => { s.consents.dass = { state: 'not_applicable' } }],
            ['mandatory-dass-missing', (s) => { s.access.tests = [{ testType: 'ist', state: 'locked' }] }],
            ['dass-only-package', (s) => { s.access.tests = [{ testType: 'dass21', state: 'locked' }] }],
            ['action-missing', (s) => { delete s.payment.action }],
            ['action-capability-mismatch', (s) => { s.payment.actionAvailable = true }],
            ['action-path', (s) => { s.payment = selfSelectPayment(); s.payment.action.path = '/checkout/logout' }],
            ['action-currency', (s) => { s.payment = selfSelectPayment(); s.payment.action.currency = 'USD' }],
            ['action-choice-arithmetic', (s) => { s.payment = selfSelectPayment(); s.payment.action.choices[1].amountIdr = 1 }],
            ['action-choice-order', (s) => { s.payment = selfSelectPayment(); s.payment.action.choices.reverse() }],
            ['action-continue-snapshot', (s) => { s.packageSource = 'charge_snapshot'; s.payment = selfContinuePayment(); s.payment.amountIdr = 99000 }],
            ['action-select-snapshot', (s) => { s.packageSource = 'charge_snapshot'; s.payment = selfSelectPayment(); s.payment.amountSource = 'charge_snapshot'; s.payment.amountIdr = 99000; s.payment.consultationRequested = false }],
            ['organization-zero-snapshot', (s) => { s.packageSource = 'charge_snapshot'; s.payment = organizationZeroPayment(); s.payment.amountSource = 'charge_snapshot'; s.payment.amountIdr = 0; s.payment.consultationRequested = false }],
            ['organization-positive-snapshot', (s) => { s.packageSource = 'charge_snapshot'; s.payment = organizationZeroPayment(); s.payment.amountSource = 'charge_snapshot'; s.payment.amountIdr = 100; s.payment.consultationRequested = false }],
            ...terminalOrRecoveryActionCases,
            ['required-profile', (s) => { s.profile[0].required = false }],
            ['optional-email', (s) => { s.profile[5].required = true }],
            ['label-type', (s) => { s.branchName = 7 }],
            ['extra-top-key', (s) => { s.billId = 1 }],
            ['array-object', (s) => { s.payment = [] }],
            ['null-object', (s) => { s.access = null }],
            ['snapshot-negative', (s) => { snapshot(s, -1) }],
            ['snapshot-fraction', (s) => { snapshot(s, 0.5) }],
            ['snapshot-unsafe', (s) => { snapshot(s, 9007199254740992) }],
            ['snapshot-string', (s) => { snapshot(s, '100') }],
            ['snapshot-null', (s) => { snapshot(s, null) }],
            ['snapshot-consultation', (s) => { snapshot(s); s.payment.consultationRequested = null }],
            ['organization-label', (s) => { s.payment.payer = 'organization'; s.payment.organizationName = 7 }],
            ['required-document-type', (s) => { s.consents.psychotest = { state: 'required', document: { version: 'v1', title: 'Synthetic', text: 1 } } }],
            ['missing-display-value', (s) => { s.profile[0].state = 'locked' }],
        ]
        validateSummary(sample())
        let positiveProbes = 1
        for (const state of ['unselected', 'unpaid', 'unbilled', 'preparing', 'pending', 'recovery_required', 'expired', 'rejected', 'paid', 'free']) {
            const value = sample()
            snapshot(value, state === 'free' ? 0 : 100)
            value.payment.state = state
            validateSummary(value)
            positiveProbes++
        }
        for (const readyCount of [0, 1, 5]) {
            const value = sample()
            snapshot(value, 0) // Zero alone must NOT be inferred as free or ready.
            value.payment.payer = 'organization'
            value.payment.organizationName = value.branchName
            value.access.tests = ['dass21', 'ist', 'kraepelin', 'papi', 'rmib']
                .map((testType, index) => ({ testType, state: index < readyCount ? 'ready' : 'locked' }))
            value.access.state = readyCount === 0 ? 'locked' : readyCount === 5 ? 'ready' : 'partial'
            value.access.message = { locked: 'Akses tes belum siap.', partial: 'Sebagian akses tes belum siap.', ready: 'Prasyarat akses tes terpenuhi; mesin sesi belum tersedia.' }[value.access.state]
            value.consents.dass = { state: 'required', document: { version: 'v1', title: 'Synthetic', text: 'Synthetic text' } }
            validateSummary(value)
            positiveProbes++
        }
        const selfSelect = sample()
        selfSelect.payment = selfSelectPayment()
        validateSummary(selfSelect)
        positiveProbes++
        const selfContinue = structuredClone(selfSelect)
        selfContinue.packageSource = 'charge_snapshot'
        selfContinue.payment = selfContinuePayment()
        validateSummary(selfContinue)
        positiveProbes++
        const organizationZero = sample()
        organizationZero.payment = organizationZeroPayment()
        validateSummary(organizationZero)
        positiveProbes++
        const accepted = []
        for (const [name, mutate] of malformed) {
            const candidate = sample()
            mutate(candidate)
            let rejected = false
            try { validateSummary(candidate) } catch { rejected = true }
            if (!rejected) accepted.push(name)
        }
        assert(accepted.length === 0, `Malformed summary probes accepted: ${accepted.join(', ')}`)
        return { positiveProbes, negativeProbes: malformed.length, passed: true, browserStarted: false }
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
                consoleMessages.push('unexpected browser console message')
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
    let summary = await assertCheckoutPage(page)
    assert(summary.payment.amountIdr === null && summary.payment.consultationRequested === null
        && summary.payment.state === 'unselected' && summary.access.state === 'locked', 'No-charge summary invented payment/access')
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

    // Another attempt in the same cookie jar replaces only the dedicated pair. Old DOM is observation, not authority.
    const staleCsrf = await page.locator('meta[name="checkout-csrf-token"]').getAttribute('content')
    const staleName = summary.profile[0].displayValue
    const switchTab = await page.context().newPage()
    observeConsole(switchTab)
    await submit(switchTab, sources[1], await issue('same-person'))
    const switched = await assertCheckoutPage(switchTab)
    assert(switched.packageName === 'Package same-person', 'Shared cookie did not select the newly exchanged attempt')
    assert((await page.locator('script#checkout-summary-v2').count()) === 1
        && (await page.locator('script#checkout-summary-v2').textContent()).includes(staleName), 'Already delivered DOM was unexpectedly rewritten')
    const staleResponse = await mutation(page, { 'Content-Type': 'application/x-www-form-urlencoded' }, `_checkout_csrf=${staleCsrf}`)
    assert(staleResponse.status() === 419, 'Stale-tab CSRF mutated the new shared attempt')
    await page.reload()
    assert((await assertCheckoutPage(page)).packageName === 'Package same-person', 'Reload did not revalidate current shared attempt')
    const freshCsrf = await switchTab.locator('meta[name="checkout-csrf-token"]').getAttribute('content')
    const switchLogout = await mutation(switchTab, { 'X-Checkout-CSRF': freshCsrf })
    assert(switchLogout.status() === 303, 'Fresh shared-attempt logout failed')
    await switchTab.close()

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
    const firstSummary = JSON.parse(await page.locator('script#checkout-summary-v2').textContent())
    const secondSummary = JSON.parse(await secondTab.locator('script#checkout-summary-v2').textContent())
    assert(JSON.stringify(firstSummary) === JSON.stringify(secondSummary), 'Tabs did not share the same authorized summary')
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

    await drainObservations()
    let documentsBefore = checkoutDocumentResponses
    await page.goBack()
    assertSafeLocation(page)
    await drainObservations()
    historyObservations.push({ phase: 'after-logout-back', documentResponseObserved: checkoutDocumentResponses > documentsBefore,
        summaryDOMVisible: await page.locator('script#checkout-summary-v2').count() === 1 })
    await page.goto(`${appOrigin}/checkout`)
    await page.waitForURL(`${appOrigin}/checkout/unavailable`)
    assert(await page.locator('script#checkout-summary-v2').count() === 0, 'Logged-out server read returned summary')

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
    const recoveryStaleTab = await page.context().newPage()
    observeConsole(recoveryStaleTab)
    await recoveryStaleTab.goto(`${appOrigin}/checkout`)
    await assertCheckoutPage(recoveryStaleTab)
    const oldRecoveryCsrf = await recoveryStaleTab.locator('input[name="_checkout_csrf"]').getAttribute('value')
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
    // Submit the actual old delivered form, while the shared jar contains the newly exchanged pair.
    const recoveredCookies = await checkoutCookies(page.context())
    assertCookieContract(recoveredCookies)
    assert(recoveredCookies.find((cookie) => cookie.name === csrfName)?.value !== oldRecoveryCsrf,
        'Recovery did not rotate the delivered CSRF')
    const recoveryBefore = await control('state', 'orphan')
    try {
        const staleRequest = recoveryStaleTab.waitForRequest((request) => request.method() === 'POST'
            && parseUrl(request.url()).pathname === '/checkout/logout')
        const staleResult = recoveryStaleTab.waitForResponse((response) => response.request().method() === 'POST'
            && parseUrl(response.url()).pathname === '/checkout/logout')
        await recoveryStaleTab.getByRole('button', { name: 'Keluar' }).click()
        const request = await staleRequest
        assert(request.postData() === `_checkout_csrf=${oldRecoveryCsrf}`, 'Recovery stale form did not use its delivered CSRF')
        assert((await request.allHeaders()).origin === 'null', 'Recovery stale native form Origin differs')
        const rejected = await staleResult
        await rejected.finished()
        assert(rejected.status() === 419, 'Old recovery CSRF did not fail419 against the new pair')
        assert(JSON.stringify(await control('state', 'orphan')) === JSON.stringify(recoveryBefore), 'Old recovery form changed session/audit counts')
        const preserved = await checkoutCookies(page.context())
        assert(recoveredCookies.every((cookie) => preserved.some((after) => after.name === cookie.name && after.value === cookie.value))
            && preserved.length === 2, 'Old recovery form cleared or replaced the new pair')
        await page.reload()
        assert((await assertCheckoutPage(page)).packageName === 'Package orphan', 'Fresh recovered pair could not read its own summary')
    } finally {
        await recoveryStaleTab.close()
    }
    await drainObservations()
    documentsBefore = checkoutDocumentResponses
    await page.goBack()
    assertSafeLocation(page)
    await drainObservations()
    historyObservations.push({ phase: 'after-recovery-back', documentResponseObserved: checkoutDocumentResponses > documentsBefore,
        summaryDOMVisible: await page.locator('script#checkout-summary-v2').count() === 1 })
    await page.goto(`${appOrigin}/checkout`)
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
    assert(exchangePosts === 9, 'Browser did not exercise the nine preceding cross-site POSTs')

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
    // Frozen own allocation is visible; parent total, peer and invoice markers remain absent.
    await submit(page, sources[0], await issue('price'))
    summary = await assertCheckoutPage(page)
    assert(summary.payment.state === 'paid' && summary.access.state === 'locked', 'PROVISIONED fixture unexpectedly ready')
    await control('prepare-price', 'price')
    await page.reload()
    summary = await assertCheckoutPage(page)
    assert(summary.packageName === 'Synthetic' && summary.packageSource === 'charge_snapshot'
        && summary.payment.amountIdr === 100 && summary.payment.state === 'paid'
        && summary.access.state === 'partial' && summary.consents.dass.state === 'required', 'Own frozen/partial summary mismatch')
    assert(summary.consents.dass.document.text === 'Synthetic DASS </script><img src=x onerror="alert(1)"> & 日本語',
        'Escaped legal text did not roundtrip exactly')
    assert(!(await page.content()).includes('PRIVATE_CHANGED_CATALOG'), 'Changed catalogue replaced frozen summary')
    const verified = await control('verify', 'first')
    assert(verified.businessMatchesFixedPlan === true, 'Full business postcondition with exact fixture-control deltas failed')
    await drainObservations()
    assert(exchangePosts === 11, 'Browser did not exercise all canonical exchanges')
    assert(hostileForms === 6, 'Browser did not complete the hostile native forms')
    assert(paymentPosts === 0, 'Default-off synthetic fixture attempted payment/provider transport')
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
            'exact inert checkout-summary-v2, mandatory DASS-21, escaped DOM and CSP without executable application script in the default-off checkout state',
            'own frozen amount and partial access without parent/peer/invoice disclosure',
            'fixation/replay/history/refresh/shared-tab stale-CSRF/recovery fenced',
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
        fullBusinessPostcondition: true,
        historyObservations,
        immediateDeliveredDOMRemovalClaimed: false,
    }
}
