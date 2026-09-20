import { expect, type Page, test } from '@playwright/test';
import { loginAs, logout } from '../support/auth';
import { assertNoHorizontalScroll, installNetworkGuards } from '../support/guards';
import { e2eUsers } from '../support/env';
import { f7MetricsFor } from '../support/server-state';

test.beforeEach(async ({ page }) => {
  await installNetworkGuards(page);
});

test.describe('E2E-3 F7 operational dashboard, ledger, and withdrawal surfaces', () => {
  test('dashboard overview widget renders server metrics for central and branch scopes', async ({ page }) => {
    await loginAs(page, 'superAdmin');
    const centralMetrics = f7MetricsFor(e2eUsers.superAdmin.email);
    await page.goto('/admin');
    await assertMetric(page, 'Peserta', centralMetrics.participants, 'Semua cabang');
    await assertMetric(page, 'Entitlement siap tes', centralMetrics.readyEntitlements, 'Status ready');
    await assertMetric(page, 'Gap ledger komisi', centralMetrics.commissionLedgerGaps, 'Transaksi paid belum masuk ledger');
    await assertNoHorizontalScroll(page);

    await logout(page);
    await loginAs(page, 'branchAdmin');
    const branchMetrics = f7MetricsFor(e2eUsers.branchAdmin.email);
    await page.goto('/admin');
    await assertMetric(page, 'Peserta', branchMetrics.participants, 'Cabang sendiri');
    await assertMetric(page, 'Entitlement siap tes', branchMetrics.readyEntitlements, 'Status ready');
    await assertMetric(page, 'Gap ledger komisi', branchMetrics.commissionLedgerGaps, 'Transaksi paid belum masuk ledger');
    await assertNoHorizontalScroll(page);
  });

  test('branch_admin and staff see detailed own-branch ledger rows, while super_admin sees both branches', async ({
    page,
  }) => {
    await loginAs(page, 'branchAdmin');
    await assertOwnBranchLedger(page);

    await logout(page);
    await loginAs(page, 'staff');
    await assertOwnBranchLedger(page);

    await logout(page);
    await loginAs(page, 'superAdmin');
    await page.goto('/admin/commission-entries');
    await expect(page.getByText('E2E Branch A Participant')).toBeVisible();
    await expect(page.getByText('E2E Branch B Participant')).toBeVisible();
    await expect(page.getByText(/IDR\s*5\.000|Rp\s*5\.000|5\.000/i).first()).toBeVisible();
    await expect(page.getByText(/withdrawal_pending/i).first()).toBeVisible();
    await assertNoHorizontalScroll(page);
  });

  test('withdrawal request lifecycle visibility keeps staff read-only and central review actions central-only', async ({
    page,
  }) => {
    await loginAs(page, 'branchAdmin');
    await page.goto('/admin/withdrawal-requests');
    await expect(page.getByText('E2E-WD-A')).toBeVisible();
    await expect(page.getByText('E2E-WD-B')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Ajukan pencairan/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Setujui$/i })).toHaveCount(0);
    await assertNoHorizontalScroll(page);

    await logout(page);
    await loginAs(page, 'staff');
    await page.goto('/admin/withdrawal-requests');
    await expect(page.getByText('E2E-WD-A')).toBeVisible();
    await expect(page.getByText('E2E-WD-B')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Ajukan pencairan/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Setujui$/i })).toHaveCount(0);
    await assertNoHorizontalScroll(page);

    await logout(page);
    await loginAs(page, 'superAdmin');
    await page.goto('/admin/withdrawal-requests');
    await expect(page.getByText('E2E-WD-A')).toBeVisible();
    await expect(page.getByText('E2E-WD-B')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Setujui$/i }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /^Tolak$/i }).first()).toBeVisible();
    await assertNoHorizontalScroll(page);
  });
});

async function assertOwnBranchLedger(page: Page): Promise<void> {
  await page.goto('/admin/commission-entries');
  await expect(page.getByText('E2E Branch A Participant')).toBeVisible();
  await expect(page.getByText('E2E Branch B Participant')).toHaveCount(0);
  await expect(page.getByText(/Direct order/i)).toBeVisible();
  await expect(page.getByText(/IDR\s*5\.000|Rp\s*5\.000|5\.000/i).first()).toBeVisible();
  await expect(page.getByText(/withdrawal_pending/i).first()).toBeVisible();
  await assertNoHorizontalScroll(page);
}

async function assertMetric(page: Page, label: string, value: number, description: string): Promise<void> {
  const card = page.locator('div').filter({ hasText: label }).filter({ hasText: description }).first();
  await expect(card).toBeVisible();
  await expect(card).toContainText(formatInteger(value));
}

function formatInteger(value: number): string {
  return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value);
}
