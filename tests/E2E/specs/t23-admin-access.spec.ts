import { expect, test } from '@playwright/test';
import { loginAs, logout } from '../support/auth';
import { assertNoHorizontalScroll, installNetworkGuards } from '../support/guards';

const restrictedSuperAdminPages = [
  '/admin/payment-methods',
  '/admin/test-packages',
  '/admin/integration-clients',
  '/admin/integration-sources',
];

test.beforeEach(async ({ page }) => {
  await installNetworkGuards(page);
});

test.describe('T-23 admin access matrix', () => {
  test('guest is redirected to Filament login for admin resources', async ({ page }) => {
    await page.goto('/admin/payment-methods');
    await expect(page).toHaveURL(/\/admin\/login/);
  });

  test('super_admin can reach configuration and integration resources', async ({ page }) => {
    await loginAs(page, 'superAdmin');

    for (const path of restrictedSuperAdminPages) {
      const response = await page.goto(path);
      expect(response?.status(), `${path} should be reachable by super_admin`).toBe(200);
      await assertNoHorizontalScroll(page);
    }
  });

  for (const role of ['branchAdmin', 'staff', 'psychologist'] as const) {
    test(`${role} receives concealed 404 for super-admin-only resources`, async ({ page }) => {
      await loginAs(page, role);

      for (const path of restrictedSuperAdminPages) {
        const response = await page.goto(path);
        expect(response?.status(), `${path} should be concealed from ${role}`).toBe(404);
      }
    });
  }

  for (const role of ['branchAdmin', 'staff'] as const) {
    test(`${role} sees only own-branch commission and withdrawal rows`, async ({ page }) => {
      await loginAs(page, role);

      await page.goto('/admin/commission-entries');
      await expect(page.getByText('E2E Branch A Participant')).toBeVisible();
      await expect(page.getByText('E2E Branch B Participant')).toHaveCount(0);
      await assertNoHorizontalScroll(page);

      await page.goto('/admin/withdrawal-requests');
      await expect(page.getByText('E2E-WD-A')).toBeVisible();
      await expect(page.getByText('E2E-WD-B')).toHaveCount(0);
      await assertNoHorizontalScroll(page);
    });
  }

  test('psychologist cannot reach branch commission and withdrawal resources', async ({ page }) => {
    await loginAs(page, 'psychologist');

    for (const path of ['/admin/commission-entries', '/admin/withdrawal-requests']) {
      const response = await page.goto(path);
      expect(response?.status(), `${path} should be concealed from psychologist`).toBe(404);
    }
  });

  test.afterEach(async ({ page }) => {
    await logout(page);
  });
});
