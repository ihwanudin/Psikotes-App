// Playwright CLI run-code entrypoint: verifies the 360px header layout
// for PR #91's UI/UX review item 5 (Lead, ui-ux-pro-max domain ux,
// "Layout & Responsive") — the timer, camera status badge, and the new
// "Coba aktifkan kamera lagi" retry button must all fit without
// horizontal scroll and without the button being clipped, wrapping onto
// separate lines instead where needed. Runs against
// tests/Frontend/CameraCapture/ (a real MediaStream from
// canvas.captureStream(), no camera hardware needed).
// prettier-ignore
// eslint-disable-next-line @typescript-eslint/no-unused-expressions -- CLI evaluates this function expression.
async (page) => {
    const origin = 'http://127.0.0.1:8097';
    const evidenceDir = 'D:/LSI/Web/Psikotes-worktrees/fe-proctoring-camera-reactivation/tasks/evidence/camera-shell-360px-2026-09-21';
    const checks = [];
    const check = (name, passed, detail = '') => {
        checks.push({ name, passed: Boolean(passed), detail });
    };

    await page.setViewportSize({ width: 360, height: 640 });
    await page.goto(origin);
    await page.getByRole('button', { name: 'Aktifkan kamera' }).click();
    await page.getByText('Status kamera: active', { exact: true }).waitFor();

    await page.getByRole('button', {
        name: 'Putuskan kamera (simulasikan track berakhir)',
    }).click();
    await page.getByText('Status kamera: interrupted', { exact: true }).waitFor();

    await page.getByRole('button', {
        name: 'Simulasikan peserta kembali (focus)',
    }).click();
    await page
        .getByText('Status kamera: reactivation_failed', { exact: true })
        .waitFor();

    const retryButton = page.getByRole('button', {
        name: 'Coba aktifkan kamera lagi',
    });
    await retryButton.waitFor();

    const geometry = await page.evaluate(() => {
        const badge = document.querySelector('header [role="status"]');
        const retry = [...document.querySelectorAll('header button')].find(
            (button) => button.textContent.includes('Coba aktifkan kamera lagi'),
        );
        const timer = document.querySelector('header span[aria-live="off"]');

        return {
            viewport: document.documentElement.clientWidth,
            documentScrollWidth: document.documentElement.scrollWidth,
            bodyScrollWidth: document.body.scrollWidth,
            badgeRect: badge?.getBoundingClientRect().toJSON() ?? null,
            retryRect: retry?.getBoundingClientRect().toJSON() ?? null,
            timerRect: timer?.getBoundingClientRect().toJSON() ?? null,
            retryHeight: retry?.getBoundingClientRect().height ?? 0,
        };
    });

    check(
        'No horizontal overflow at 360px',
        geometry.documentScrollWidth === geometry.viewport &&
            geometry.bodyScrollWidth === geometry.viewport,
        JSON.stringify({
            viewport: geometry.viewport,
            documentScrollWidth: geometry.documentScrollWidth,
            bodyScrollWidth: geometry.bodyScrollWidth,
        }),
    );
    check(
        'Retry button is fully within the viewport (not clipped off the right edge)',
        geometry.retryRect &&
            geometry.retryRect.right <= geometry.viewport &&
            geometry.retryRect.left >= 0,
        JSON.stringify(geometry.retryRect),
    );
    check(
        'Retry button meets the 44px touch target minimum',
        geometry.retryHeight >= 44,
        `height=${geometry.retryHeight}`,
    );
    check(
        'Retry button does not overlap the timer (wrapped onto its own line, not stacked on top)',
        geometry.timerRect &&
            geometry.retryRect &&
            geometry.retryRect.top >= geometry.timerRect.bottom,
        JSON.stringify({ timer: geometry.timerRect, retry: geometry.retryRect }),
    );

    await page.screenshot({
        path: `${evidenceDir}/session-runner-shell-header-reactivation-failed-360.png`,
    });

    const result = { checks, geometry };
    await page.evaluate((payload) => {
        localStorage.setItem('camera-shell-360px-result', JSON.stringify(payload));
    }, result);

    return result;
}
