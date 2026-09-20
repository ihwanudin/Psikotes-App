import { expect, test } from '@playwright/test';
import { assertNoHorizontalScroll, installNetworkGuards } from '../support/guards';

test.beforeEach(async ({ page }) => {
  await installNetworkGuards(page);
});

test.describe('T-24 DASS-21 mandatory inclusion in main package', () => {
  test('registration catalog shows DASS-21 bundled in the main package, not optional', async ({ page }) => {
    const response = await page.goto('/register');
    expect(response?.status()).toBe(200);

    const packageCard = page.locator('label').filter({ hasText: 'E2E Paket Psikotes Utama' });
    await expect(packageCard).toBeVisible();
    await expect(packageCard.getByText('IST')).toBeVisible();
    await expect(packageCard.getByText('PAPI Kostick')).toBeVisible();
    await expect(packageCard.getByText('RMIB')).toBeVisible();
    await expect(packageCard.getByText('Kraepelin')).toBeVisible();
    await expect(packageCard.getByText('DASS-21')).toBeVisible();

    await packageCard.locator('input[name="package_id"]').check();
    await expect(page.locator('input[name="include_dass"], input[name="dass_optional"], input[name="dass21"]')).toHaveCount(0);
    await expect(page.getByLabel(/DASS-21/i)).toBeVisible();
    await assertNoHorizontalScroll(page);
  });
});
