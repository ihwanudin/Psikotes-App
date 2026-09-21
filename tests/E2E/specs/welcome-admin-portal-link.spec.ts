import { expect, test } from '@playwright/test';
import { installNetworkGuards } from '../support/guards';

test.beforeEach(async ({ page }) => {
  await installNetworkGuards(page);
});

test.describe('Welcome page admin portal link', () => {
  test('"Portal pengelola" does a real browser navigation to Filament admin login, not an Inertia modal', async ({ page }) => {
    await page.goto('/');

    const link = page.getByRole('link', { name: 'Portal pengelola' });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', '/admin/login');

    // A plain <a> forces a full page load; an Inertia <Link> would instead
    // XHR-visit /admin/login (a non-Inertia Filament/Livewire page) and
    // render its HTML inside Inertia's own modal overlay without changing
    // the address bar -- asserting both the URL and the absence of an
    // Inertia page root proves this really left the SPA, not just that the
    // link's href attribute happens to be right.
    await link.click();
    await expect(page).toHaveURL(/\/admin\/login$/);
    await expect(page.locator('[data-inertia]')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Masuk ke akun Anda' })).toBeVisible();
  });
});
