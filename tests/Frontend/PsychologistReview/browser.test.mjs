// Playwright CLI run-code --filename entrypoint. Requires a disposable testing
// Laravel server with the synthetic reviewer account documented below.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression as its entrypoint.
async (page) => {
    const origin = 'http://127.0.0.1:8014';
    const reviewUrl = `${origin}/admin/psychologist-review-fixture`;
    const browserErrors = [];
    const requests = [];
    const results = [];
    const assert = (condition, message) => {
        if (!condition) {
            throw new Error(message);
        }
    };

    page.on('pageerror', (error) => browserErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserErrors.push(message.text());
        }
    });
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = request.url();

        if (!url.startsWith(`${origin}/`)) {
            assert(
                request.method() === 'GET' &&
                    url.startsWith('https://ui-avatars.com/api/'),
                `Unexpected external request: ${url}`,
            );
            await route.fulfill({
                status: 200,
                contentType: 'image/svg+xml',
                body: '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"/>',
            });

            return;
        }

        requests.push({
            method: request.method(),
            path: url.slice(origin.length).split('?')[0],
        });
        await route.continue();
    });

    await page.goto(reviewUrl);

    if (page.url().includes('/admin/login')) {
        await page
            .getByRole('textbox', {
                name: /^(Email address|Alamat email)\*?$/i,
            })
            .fill('psychologist.fixture@example.test');
        await page
            .getByRole('textbox', { name: /^(Password|Kata sandi)\*?$/i })
            .fill('synthetic-password');
        await page.locator('button[type="submit"]').click();
    }

    await page
        .getByRole('heading', { name: 'DATA SINTETIS — BUKAN LAPORAN NYATA' })
        .waitFor();
    await page.waitForFunction(() => Boolean(window.Livewire));
    assert(
        await page
            .getByText(
                'Perubahan draf akan hilang jika halaman dimuat ulang atau ditutup.',
            )
            .isVisible(),
        'Temporary draft-loss warning is missing',
    );
    assert(
        await page
            .getByText('Kategori umum DASS-21', { exact: true })
            .isVisible(),
        'HPP general DASS is missing',
    );
    assert(
        (await page
            .getByText('Skor subskala DASS-21', { exact: true })
            .count()) === 0,
        'Detailed DASS leaked into HPP DOM',
    );
    assert(
        (await page.getByText('Depresi (x2)', { exact: true }).count()) === 0,
        'DASS subscale leaked into HPP DOM',
    );
    results.push('HPP/internal projection separation: PASS');

    const internalButton = page.getByRole('button', {
        name: 'Bukti internal & DASS',
    });
    await internalButton.focus();
    await internalButton.press('Enter');
    const internalHeading = page.getByRole('heading', {
        name: 'Bukti internal psikolog',
    });
    await internalHeading.waitFor();
    await page.waitForFunction(
        () => document.activeElement?.id === 'projection-heading',
    );
    assert(
        await internalHeading.evaluate(
            (element) => element === document.activeElement,
        ),
        'Panel heading did not receive restored focus',
    );
    assert(
        await page.getByText('Depresi (x2)', { exact: true }).isVisible(),
        'Internal DASS detail is missing',
    );
    assert(
        (await internalButton.getAttribute('aria-pressed')) === 'true',
        'Projection button state is not announced',
    );

    const queue = page.getByRole('heading', { name: /Antrean tinjauan G7/ });
    const table = page.getByRole('table', { name: 'Sumber level psikotes' });
    assert(await queue.isVisible(), 'Labelled unresolved G7 queue is missing');
    assert(
        await queue.evaluate(
            (element, tableElement) =>
                Boolean(
                    element.compareDocumentPosition(tableElement) &
                    Node.DOCUMENT_POSITION_FOLLOWING,
                ),
            await table.elementHandle(),
        ),
        'Unresolved G7 queue is not before the canonical aspect table',
    );

    const namedControls = page.locator('select, textarea');
    const controlCount = await namedControls.count();

    for (let index = 0; index < controlCount; index += 1) {
        const control = namedControls.nth(index);
        assert(
            Boolean(await control.getAttribute('name')),
            `Control ${index} has no name`,
        );
        assert(
            (await control.getAttribute('autocomplete')) === 'off',
            `Control ${index} has no autocomplete policy`,
        );
        assert(
            (await control.getAttribute('class'))?.includes('hover:'),
            `Control ${index} has no hover treatment`,
        );
    }

    await page.locator('#g6-final-level').selectOption('4');
    await page
        .getByText('Pratinjau rekomendasi menunggu hitung ulang server', {
            exact: true,
        })
        .waitFor();
    await page.locator('#g6-reason').fill('1234567890123456789');
    await page.locator('#g7-final-level').selectOption('3');
    await page
        .locator('#accompaniment-conditions')
        .fill('Pendampingan adaptasi kerja selama masa awal.');
    await page
        .getByRole('button', { name: 'Validasi kesiapan sintetis' })
        .click();
    await page
        .getByText('OVERRIDE_REASON_MIN_LENGTH', { exact: true })
        .waitFor();
    assert(
        await page.getByText('19 karakter', { exact: true }).isVisible(),
        'G6 count did not use the trimmed boundary',
    );

    await page.locator('#g6-reason').fill('12345678901234567890');
    await page
        .getByRole('button', { name: 'Validasi kesiapan sintetis' })
        .click();
    await page
        .getByText('OVERRIDE_REASON_MIN_LENGTH', { exact: true })
        .waitFor({ state: 'detached' });
    assert(
        await page
            .getByText('Hasil hitung ulang server: DIPERTIMBANGKAN', {
                exact: true,
            })
            .isVisible(),
        'Server recalculation did not restore the recommendation preview',
    );
    assert(
        await page
            .getByText('PERSISTENCE_AUTHORITY_UNBOUND', { exact: true })
            .isVisible(),
        'Persistence gate disappeared',
    );
    assert(
        await page
            .getByRole('button', { name: 'Tanda tangan belum tersedia' })
            .isDisabled(),
        'Synthetic signing control became enabled',
    );
    assert(
        await page
            .getByRole('button', { name: 'Publikasi belum tersedia' })
            .isDisabled(),
        'Synthetic publish control became enabled',
    );
    results.push('G6/G7/readiness interaction: PASS');

    await page.locator('#label-final').selectOption('TIDAK_DISARANKAN');
    await page.locator('#label-reason').fill('1234567890123456789');
    await page
        .getByRole('button', { name: 'Validasi kesiapan sintetis' })
        .click();
    await page
        .getByText('OVERRIDE_REASON_MIN_LENGTH', { exact: true })
        .waitFor();
    await page
        .locator('#label-reason')
        .fill('Pertimbangan profesional menunjukkan risiko tambahan.');
    await page
        .getByRole('button', { name: 'Validasi kesiapan sintetis' })
        .click();
    await page
        .getByText('OVERRIDE_REASON_MIN_LENGTH', { exact: true })
        .waitFor({ state: 'detached' });
    assert(
        await page
            .getByText('Hasil hitung ulang server: TIDAK_DISARANKAN', {
                exact: true,
            })
            .isVisible(),
        'Validated label override did not update the final preview',
    );
    results.push('Label override reason boundary: PASS');

    for (const width of [320, 390, 768, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        const geometry = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            documentWidth: document.documentElement.scrollWidth,
            reasonVisible:
                document.querySelector('#g6-reason')?.getBoundingClientRect()
                    .width ?? 0,
            containedTableScroll: [
                ...document.querySelectorAll(
                    '[aria-label="Tabel aspek dan sumber level"]',
                ),
            ].every((element) => element.scrollWidth >= element.clientWidth),
        }));

        assert(
            geometry.documentWidth === geometry.viewport,
            `${width}: page-level horizontal overflow`,
        );
        assert(
            geometry.reasonVisible > 0,
            `${width}: G6 reason field is clipped`,
        );
        assert(
            geometry.containedTableScroll,
            `${width}: dense table is not contained`,
        );
    }

    results.push('320/390/768/1280 responsive geometry: PASS');

    await page.locator('[data-fixture-id]').evaluate((element) => {
        window.Alpine.$data(element).draftTouched = false;
    });
    await page.goto(`${reviewUrl}?scenario=v2`);
    await page
        .getByRole('heading', { name: 'DATA SINTETIS — BUKAN LAPORAN NYATA' })
        .waitFor();
    await page.getByRole('button', { name: 'Bukti internal & DASS' }).click();
    const procedureBlocker = page.getByRole('button', {
        name: 'V2_PROCEDURE_NOTE_REQUIRED',
    });
    await procedureBlocker.click();
    const procedureNote = page.locator('#procedure-note');
    await page.waitForFunction(
        () => document.activeElement?.id === 'procedure-note',
    );
    assert(
        await procedureNote.evaluate(
            (element) => element === document.activeElement,
        ),
        'V2 blocker did not focus its field',
    );
    await procedureNote.fill('Gangguan koneksi telah ditinjau oleh psikolog.');
    const fixtureRoot = page.locator('[data-fixture-id]');
    const beforeUnloadHandler = await fixtureRoot.getAttribute(
        'x-on:beforeunload.window',
    );
    assert(
        beforeUnloadHandler?.includes('$event.preventDefault()'),
        'Reload did not warn about temporary draft loss',
    );
    await fixtureRoot.evaluate((element) => {
        window.Alpine.$data(element).draftTouched = false;
    });
    await page.reload();
    await page.getByRole('button', { name: 'Bukti internal & DASS' }).click();
    assert(
        (await page.locator('#procedure-note').inputValue()) === '',
        'Fixture draft incorrectly persisted across reload',
    );
    results.push('V2 blocker focus and reload draft loss: PASS');

    await page.goto(`${reviewUrl}?scenario=v3`);
    await page
        .getByRole('heading', { name: 'STOP — laporan tidak dibuat' })
        .waitFor();
    assert(
        await page.getByText('VALIDITY_V3', { exact: true }).isVisible(),
        'V3 blocker is missing',
    );
    assert(
        (await page
            .getByText('Ubah level profesional', { exact: false })
            .count()) === 0,
        'V3 exposed override controls',
    );
    assert(
        (await page
            .getByRole('button', { name: /Tanda tangan|Publikasi/ })
            .count()) === 0,
        'V3 exposed signing actions',
    );
    results.push('V3 stop state: PASS');

    const forbiddenWrites = requests.filter(({ method, path }) => {
        const isLivewireUpdate = /^\/livewire(?:-[a-f0-9]+)?\/update$/.test(
            path,
        );

        return !['GET', 'HEAD'].includes(method) && !isLivewireUpdate;
    });

    assert(
        forbiddenWrites.length === 0,
        `Unexpected write requests: ${JSON.stringify(forbiddenWrites)}`,
    );
    assert(
        browserErrors.length === 0,
        `Browser errors: ${browserErrors.join('; ')}`,
    );

    return results;
}
