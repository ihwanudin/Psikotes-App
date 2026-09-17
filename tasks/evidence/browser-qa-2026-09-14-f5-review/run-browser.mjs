// Playwright CLI run-code entrypoint for the read-only F5 fixture QA review.
// Uses only disposable synthetic accounts and the local testing database.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression.
async (page) => {
    const origin = 'http://127.0.0.1:8014';
    const loginUrl = `${origin}/admin/login`;
    const reviewUrl = `${origin}/admin/psychologist-review-fixture`;
    const evidenceDir = 'C:/Users/ThinkPad/.codex/worktrees/0922/Psikotes/tasks/evidence/browser-qa-2026-09-14-f5-review';
    const password = 'synthetic-password';
    const checks = [];
    const browserErrors = [];
    const externalRequests = [];
    const requests = [];
    const styles = {};
    const geometry = [];
    let phase = 'setup';

    const check = (name, passed, detail = '') => {
        checks.push({ name, passed: Boolean(passed), detail });
    };

    page.on('pageerror', (error) => browserErrors.push({ phase, text: `pageerror: ${error.message}` }));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserErrors.push({ phase, text: `console: ${message.text()}` });
        }
    });
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = request.url();

        if (!url.startsWith(`${origin}/`)) {
            externalRequests.push({ method: request.method(), url });

            if (url.startsWith('https://ui-avatars.com/api/')) {
                await route.fulfill({
                    status: 200,
                    contentType: 'image/svg+xml',
                    body: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"/>',
                });

                return;
            }
        } else {
            requests.push({
                method: request.method(),
                path: url.slice(origin.length).split('?')[0],
            });
        }

        await route.continue();
    });

    const waitForLogin = async () => {
        await page.getByRole('textbox', {
            name: /^(Email address|Alamat email)\*?$/i,
        }).waitFor();
    };

    const login = async (email) => {
        await page.context().clearCookies();
        await page.goto(loginUrl);
        await waitForLogin();
        await page.getByRole('textbox', {
            name: /^(Email address|Alamat email)\*?$/i,
        }).fill(email);
        await page.getByRole('textbox', {
            name: /^(Password|Kata sandi)\*?$/i,
        }).fill(password);
        await Promise.all([
            page.waitForURL((url) => !url.pathname.endsWith('/admin/login')),
            page.locator('button[type="submit"]').click(),
        ]);
    };

    await page.context().clearCookies();
    phase = 'guest_redirect';
    await page.setViewportSize({ width: 390, height: 900 });
    const guestResponse = await page.goto(reviewUrl);
    await waitForLogin();
    check(
        'Guest is redirected to login without fixture content',
        page.url().includes('/admin/login') &&
            (await page.getByText('F5-SYNTHETIC-REVIEW-001', { exact: false }).count()) === 0,
        `response=${guestResponse?.status() ?? 'redirected'} url=${page.url()}`,
    );
    await page.screenshot({ path: `${evidenceDir}/guest-login-390.png`, fullPage: true });

    for (const role of [
        ['super_admin', 'super.fixture@example.test'],
        ['branch_admin', 'branch.fixture@example.test'],
        ['staff', 'staff.fixture@example.test'],
    ]) {
        const [name, email] = role;
        phase = `expected_denial:${name}`;
        await login(email);
        const response = await page.goto(reviewUrl);
        const body = await page.locator('body').innerText();
        check(
            `${name} receives concealed denial`,
            response?.status() === 404 &&
                !body.includes('F5-SYNTHETIC-REVIEW-001') &&
                !body.includes('DATA SINTETIS — BUKAN LAPORAN NYATA'),
            `status=${response?.status() ?? 'none'} fixtureText=${body.includes('F5-SYNTHETIC-REVIEW-001')}`,
        );
    }

    phase = 'psychologist_positive';
    await login('psychologist.fixture@example.test');
    await page.goto(reviewUrl);
    await page.getByRole('heading', {
        name: 'DATA SINTETIS — BUKAN LAPORAN NYATA',
    }).waitFor();
    await page.waitForFunction(() => Boolean(window.Livewire));

    check(
        'Psychologist can open the synthetic review fixture',
        page.url().includes('/admin/psychologist-review-fixture'),
        page.url(),
    );
    check(
        'HPP DOM excludes detailed DASS and raw instrument evidence',
        (await page.getByText('Kategori umum DASS-21', { exact: true }).count()) === 1 &&
            (await page.getByText('Depresi (x2)', { exact: true }).count()) === 0 &&
            (await page.getByRole('heading', { name: 'Ringkasan instrumen sintetis' }).count()) === 0,
    );

    styles.loadedSheets = await page.evaluate(() =>
        Array.from(document.styleSheets).map((sheet) => sheet.href).filter(Boolean),
    );
    styles.warning = await page.locator('[data-synthetic-warning]').evaluate((element) => {
        const style = getComputedStyle(element);

        return {
            background: style.backgroundColor,
            borderWidth: Number.parseFloat(style.borderTopWidth),
            color: style.color,
        };
    });

    const internalButton = page.getByRole('button', { name: 'Bukti internal & DASS' });
    await internalButton.focus();
    await internalButton.press('Enter');
    const internalHeading = page.getByRole('heading', { name: 'Bukti internal psikolog' });
    await internalHeading.waitFor();
    await page.waitForFunction(() => document.activeElement?.id === 'projection-heading');
    check(
        'Keyboard projection switch restores focus to the internal heading',
        await internalHeading.evaluate((element) => element === document.activeElement),
    );
    check(
        'Internal projection contains DASS detail and raw evidence',
        (await page.getByText('Depresi (x2)', { exact: true }).count()) === 1 &&
            (await page.getByRole('heading', { name: 'Ringkasan instrumen sintetis' }).count()) === 1 &&
            (await internalButton.getAttribute('aria-pressed')) === 'true',
    );

    styles.g7 = await page.locator('[data-review-state="g7-unresolved"]').evaluate((element) => {
        const style = getComputedStyle(element);

        return { borderWidth: Number.parseFloat(style.borderTopWidth), borderColor: style.borderTopColor };
    });
    styles.formControl = await page.locator('#g6-final-level').evaluate((element) => {
        const style = getComputedStyle(element);

        return {
            borderWidth: Number.parseFloat(style.borderTopWidth),
            height: element.getBoundingClientRect().height,
        };
    });
    styles.validationButton = await page.getByRole('button', {
        name: 'Validasi kesiapan sintetis',
    }).evaluate((element) => {
        const style = getComputedStyle(element);

        return {
            background: style.backgroundColor,
            color: style.color,
            height: element.getBoundingClientRect().height,
        };
    });
    check(
        'Synthetic warning has visible background and border',
        styles.warning.background !== 'rgba(0, 0, 0, 0)' && styles.warning.borderWidth >= 1,
        JSON.stringify(styles.warning),
    );
    check(
        'G7 unresolved state has a prominent border',
        styles.g7.borderWidth >= 2,
        JSON.stringify(styles.g7),
    );
    check(
        'Form controls have visible borders and 44px targets',
        styles.formControl.borderWidth >= 1 && styles.formControl.height >= 44,
        JSON.stringify(styles.formControl),
    );
    check(
        'Primary validation button has visible treatment and 44px target',
        !['rgba(0, 0, 0, 0)', 'rgb(255, 255, 255)'].includes(styles.validationButton.background) &&
            styles.validationButton.height >= 44,
        JSON.stringify(styles.validationButton),
    );

    await page.locator('#g6-final-level').selectOption('4');
    await page.locator('#g6-reason').fill('12345678901234567890');
    await page.locator('#g7-final-level').selectOption('3');
    await page.locator('#accompaniment-conditions').fill('Pendampingan adaptasi kerja selama masa awal.');
    await page.getByRole('button', { name: 'Validasi kesiapan sintetis' }).click();
    await page.getByText('Hasil hitung ulang server: DIPERTIMBANGKAN', { exact: true }).waitFor();
    check(
        'Readiness recalculates while persistence/sign/publish remain blocked',
        (await page.getByText('PERSISTENCE_AUTHORITY_UNBOUND', { exact: true }).count()) === 1 &&
            await page.getByRole('button', { name: 'Tanda tangan belum tersedia' }).isDisabled() &&
            await page.getByRole('button', { name: 'Publikasi belum tersedia' }).isDisabled(),
    );

    for (const width of [320, 390, 768, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        const result = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            documentWidth: document.documentElement.scrollWidth,
            reasonWidth: document.querySelector('#g6-reason')?.getBoundingClientRect().width ?? 0,
            tableContained: [...document.querySelectorAll('[aria-label="Tabel aspek dan sumber level"]')]
                .every((element) => element.scrollWidth >= element.clientWidth),
        }));
        geometry.push({ width, ...result });
        check(
            `${width}px has no page overflow and keeps the reason field visible`,
            result.documentWidth === result.viewport && result.reasonWidth > 0 && result.tableContained,
            JSON.stringify(result),
        );

        if ([320, 768].includes(width)) {
            await page.screenshot({
                path: `${evidenceDir}/psychologist-internal-${width}.png`,
                fullPage: true,
            });
        }
    }

    await page.locator('[data-fixture-id]').evaluate((element) => {
        window.Alpine.$data(element).draftTouched = false;
    });
    await page.goto(`${reviewUrl}?scenario=v2`);
    await page.getByRole('heading', {
        name: 'DATA SINTETIS — BUKAN LAPORAN NYATA',
    }).waitFor();
    await page.getByRole('button', { name: 'Bukti internal & DASS' }).click();
    await page.getByRole('button', { name: 'V2_PROCEDURE_NOTE_REQUIRED' }).click();
    const procedureNote = page.locator('#procedure-note');
    await page.waitForFunction(() => document.activeElement?.id === 'procedure-note');
    const focused = await procedureNote.evaluate((element) => element === document.activeElement);
    await procedureNote.fill('Gangguan koneksi telah ditinjau oleh psikolog.');
    const beforeUnloadHandler = await page.locator('[data-fixture-id]').getAttribute('x-on:beforeunload.window');
    await page.locator('[data-fixture-id]').evaluate((element) => {
        window.Alpine.$data(element).draftTouched = false;
    });
    await page.reload();
    await page.getByRole('button', { name: 'Bukti internal & DASS' }).click();
    check(
        'V2 blocker focuses its field and reload discards the temporary draft',
        focused && beforeUnloadHandler?.includes('$event.preventDefault()') &&
            (await page.locator('#procedure-note').inputValue()) === '',
        `focused=${focused} warning=${beforeUnloadHandler ?? 'missing'}`,
    );
    await page.locator('[data-fixture-id]').evaluate((element) => {
        window.Alpine.$data(element).draftTouched = false;
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`${reviewUrl}?scenario=v3`);
    await page.getByRole('heading', { name: 'STOP — laporan tidak dibuat' }).waitFor();
    styles.v3 = await page.locator('[data-validity-stop="V3"]').evaluate((element) => {
        const style = getComputedStyle(element);

        return {
            background: style.backgroundColor,
            borderColor: style.borderTopColor,
            borderWidth: Number.parseFloat(style.borderTopWidth),
        };
    });
    check(
        'V3 exposes no override, signing, or publish actions',
        (await page.getByText('Ubah level profesional', { exact: false }).count()) === 0 &&
            (await page.getByRole('button', { name: /Tanda tangan|Publikasi/ }).count()) === 0,
    );
    check(
        'V3 stop surface is visibly prominent',
        styles.v3.borderWidth >= 2 && styles.v3.background !== 'rgba(0, 0, 0, 0)',
        JSON.stringify(styles.v3),
    );
    await page.screenshot({ path: `${evidenceDir}/psychologist-v3-1280.png`, fullPage: true });

    const unexpectedWrites = requests.filter(({ method, path }) => {
        if (['GET', 'HEAD'].includes(method)) {
            return false;
        }

        if (method === 'POST' && path === '/admin/login') {
            return false;
        }

        return !(method === 'POST' && /^\/livewire(?:-[a-f0-9]+)?\/update$/.test(path));
    });
    check('No unexpected HTTP write routes were called', unexpectedWrites.length === 0, JSON.stringify(unexpectedWrites));
    check(
        'No unexpected external hosts were contacted',
        externalRequests.every(({ method, url }) => method === 'GET' && url.startsWith('https://ui-avatars.com/api/')),
        JSON.stringify(externalRequests),
    );
    const unexpectedBrowserErrors = browserErrors.filter(
        ({ phase: errorPhase }) => !errorPhase.startsWith('expected_denial:'),
    );
    check(
        'No unexpected browser console or page errors occurred',
        unexpectedBrowserErrors.length === 0,
        JSON.stringify({ unexpectedBrowserErrors, expectedDenialErrors: browserErrors.length - unexpectedBrowserErrors.length }),
    );

    const result = {
        checks,
        styles,
        geometry,
        browserErrors,
        unexpectedBrowserErrors,
        externalRequests,
        unexpectedWrites,
        requestCount: requests.length,
    };
    await page.evaluate((payload) => {
        localStorage.setItem('f5-browser-qa-result', JSON.stringify(payload));
    }, result);

    return result;
}
