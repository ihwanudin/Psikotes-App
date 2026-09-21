import { expect, test } from '@playwright/test';
import { installNetworkGuards } from '../support/guards';

test.beforeEach(async ({ page }) => {
  await installNetworkGuards(page);
});

test.describe('Welcome page admin portal link', () => {
  test('"Portal pengelola" points at Filament admin login, not the unused web-guard login', async ({ page }) => {
    await page.goto('/');

    const link = page.getByRole('link', { name: 'Portal pengelola' });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', '/admin/login');
  });
});
