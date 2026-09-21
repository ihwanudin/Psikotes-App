import { expect, test } from '@playwright/test';
import { loginAs } from '../support/auth';
import { assertNoHorizontalScroll, installNetworkGuards } from '../support/guards';

const casePublicId = '01K000000000000000000E2E2A';

test.beforeEach(async ({ page }) => {
  await installNetworkGuards(page);
});

test.describe('F5 ReportSigning browser reachability', () => {
  test('guest is redirected to admin login', async ({ page }) => {
    await page.goto(`/admin/report-signing?case=${casePublicId}`);
    await expect(page).toHaveURL(/\/admin\/login/);
  });

  test('psychologist can reach the per-case signing page from the case list entry point', async ({ page }) => {
    await loginAs(page, 'psychologist');

    const listResponse = await page.goto('/admin/assessment-participants');
    expect(listResponse?.status()).toBe(200);
    await expect(page.getByText('E2E Branch A Participant')).toBeVisible();

    // Correct eventual behavior: this per-case target renders the signing page.
    // On facb2bb this fails red with HTTP 500 because ReportSigning has no
    // route {case} parameter while mount(string $case, ...) requires one.
    const signingResponse = await page.goto(`/admin/report-signing?case=${casePublicId}`);
    expect(signingResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: /Tanda Tangan Laporan/i })).toBeVisible();
    await expect(page.getByText('E2E Branch A Participant')).toBeVisible();
    await assertNoHorizontalScroll(page);
  });

  test('super_admin follows the current main contract and receives concealed 404', async ({ page }) => {
    await loginAs(page, 'superAdmin');

    const response = await page.goto(`/admin/report-signing?case=${casePublicId}`);
    expect(response?.status()).toBe(404);
  });

  for (const role of ['branchAdmin', 'staff'] as const) {
    test(`${role} receives concealed 404 for ReportSigning`, async ({ page }) => {
      await loginAs(page, role);

      const response = await page.goto(`/admin/report-signing?case=${casePublicId}`);
      expect(response?.status()).toBe(404);
    });
  }
});
