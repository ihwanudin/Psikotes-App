/**
 * Pure resolver for app.tsx's `createInertiaApp({ layout })` option —
 * pulled out of app.tsx so it can be exercised by a plain `node --test`
 * run (app.tsx itself isn't testable that way: it calls
 * `createInertiaApp` at import time, which needs a real DOM). See
 * resolve-page-layout.test.ts, which iterates every real file under
 * resources/js/pages/** and asserts none of them throw here.
 *
 * Every currently known page name resolves to `null` (each page owns
 * its own chrome) now that `dashboard.tsx`/`auth/*`/`settings/*` — the
 * only pages that ever used a shared AppLayout/AuthLayout/SettingsLayout
 * wrapper — are gone (owner decision item 13.2: the unused starter-kit
 * login cluster; see
 * tasks/handoffs/f7/starter-auth-removal-plan-2026-09-21.md). There is
 * no legitimate case left that needs anything other than `null` here.
 *
 * A name that matches none of the known prefixes THROWS rather than
 * silently falling back to `null` — deliberate, not an oversight (Lead's
 * explicit requirement on the plan doc, 2026-09-21/22): treating an
 * unrecognized future page the same as the deliberate `null` cases would
 * let a page that actually needs chrome (nav, auth-gate, whatever a
 * future admin-facing Inertia page turns out to require) silently ship
 * without it. Throwing here means a page added without a matching case
 * fails loudly — caught by the page-inventory test in CI, not
 * discovered as a blank/broken screen on a participant's device.
 */
export function resolvePageLayout(name: string): null {
    if (
        name === 'welcome' ||
        name.startsWith('registration/') ||
        name.startsWith('participant/')
    ) {
        return null;
    }

    throw new Error(
        `resolvePageLayout: unrecognized Inertia page "${name}" has no assigned layout. ` +
            'Add a case for it in resolve-page-layout.ts before shipping this page.',
    );
}
