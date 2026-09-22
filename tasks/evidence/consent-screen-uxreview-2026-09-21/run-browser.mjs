// Playwright CLI run-code entrypoint: verifies PR #83's UI/UX review
// items 1 and 3 (Lead, ui-ux-pro-max domain ux) against the real
// ProctoringConsentScreen — mobile body text at 16px (item 1) and
// restored list-item bullets (item 3). Item 2 (loading feedback while
// requesting camera permission) is covered by the existing unit test
// asserting the 'requesting' label text plus direct source review of
// the aria-busy/spinner/hint JSX bindings — this fixture's simulated
// getUserMedia resolves/rejects immediately, so the 'requesting' status
// is not independently observable here without adding fixture-only
// scaffolding for a single transient state, which was judged not worth
// the added fixture complexity.
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression.
async (page) => {
    const origin = 'http://127.0.0.1:8098';
    const evidenceDir = 'D:/LSI/Web/Psikotes-worktrees/fe-proctoring-consent-impl/tasks/evidence/consent-screen-uxreview-2026-09-21';
    const checks = [];
    const check = (name, passed, detail = '') => {
        checks.push({ name, passed: Boolean(passed), detail });
    };

    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto(origin);
    await page
        .getByRole('heading', { name: 'Sebelum memulai: persetujuan pemantauan' })
        .waitFor();

    const mobileMeasurements = await page.evaluate(() => {
        const policyItem = document.querySelector('ul li');
        const list = document.querySelector('ul');

        return {
            policyItemFontSize: policyItem ? getComputedStyle(policyItem).fontSize : null,
            listStyleType: list ? getComputedStyle(list).listStyleType : null,
            listItemHasMarker: list
                ? getComputedStyle(list, '::marker').content !== 'none' ||
                  getComputedStyle(list).listStyleType !== 'none'
                : false,
        };
    });

    check(
        'Mobile (360px): policy list item body text is >= 16px (text-base)',
        mobileMeasurements.policyItemFontSize === '16px',
        `fontSize=${mobileMeasurements.policyItemFontSize}`,
    );
    check(
        'Mobile: the policy <ul> has a real list-style (bullets), not "none"',
        mobileMeasurements.listStyleType === 'disc',
        `listStyleType=${mobileMeasurements.listStyleType}`,
    );

    await page.screenshot({
        path: `${evidenceDir}/consent-screen-policy-list-360.png`,
        fullPage: true,
    });

    await page.setViewportSize({ width: 1024, height: 900 });
    const desktopMeasurements = await page.evaluate(() => {
        const policyItem = document.querySelector('ul li');

        return {
            policyItemFontSize: policyItem ? getComputedStyle(policyItem).fontSize : null,
        };
    });
    check(
        'Desktop (>= sm breakpoint): policy text may shrink to 14px (sm:text-sm) — confirms the responsive split actually applies, not just present in the class list',
        desktopMeasurements.policyItemFontSize === '14px',
        `fontSize=${desktopMeasurements.policyItemFontSize}`,
    );

    const result = { checks, mobileMeasurements, desktopMeasurements };
    await page.evaluate((payload) => {
        localStorage.setItem('consent-screen-uxreview-result', JSON.stringify(payload));
    }, result);

    return result;
}
