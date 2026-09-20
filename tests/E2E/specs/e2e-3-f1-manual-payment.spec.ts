import { expect, type Page, test } from '@playwright/test';
import { loginAs, logout } from '../support/auth';
import { assertNoHorizontalScroll, installNetworkGuards } from '../support/guards';
import { participantByEmail } from '../support/server-state';

const participant = {
  fullName: 'E2E Manual Activation Participant',
  email: 'e2e.manual.activation@example.test',
  birthDate: '2001-04-15',
  phone: '+6281234567890',
};

test.beforeEach(async ({ page }) => {
  await installNetworkGuards(page);
});

test.describe('E2E-3 F1 manual-payment activation flow', () => {
  test('participant registers, uploads transfer proof, admin approves, and ready entitlements open the lobby', async ({
    page,
  }) => {
    await registerWithManualTransfer(page);

    let state = participantByEmail(participant.email);
    expect(state.orders).toHaveLength(1);
    expect(state.orders[0].status).toBe('pending');
    expect(state.orders[0].paymentMethodCode).toBe('manual_transfer');
    expect(state.orders[0].proofObjectKey).toBeNull();
    expect(state.entitlements.map((row) => row.status).every((status) => status === 'locked')).toBe(true);

    await uploadManualTransferProof(page);

    state = participantByEmail(participant.email);
    expect(state.orders[0].status).toBe('pending');
    expect(state.orders[0].proofObjectKey).toEqual(expect.any(String));
    expect(state.entitlements.map((row) => row.status).every((status) => status === 'locked')).toBe(true);

    await approveManualTransferAsBranchAdmin(page);

    state = participantByEmail(participant.email);
    expect(state.orders[0].status).toBe('paid');
    expect(state.orders[0].paidAt).toEqual(expect.any(String));
    expect(state.entitlements.map((row) => row.status).every((status) => status === 'ready')).toBe(true);
    expect(state.entitlements.map((row) => row.readyAt).every((readyAt) => readyAt !== null)).toBe(true);

    await openParticipantLobbyWithReadyEntitlements(page, state.participant.testNumber, state.participant.birthDate);
    await assertNoHorizontalScroll(page);
  });
});

async function registerWithManualTransfer(page: Page): Promise<void> {
  await page.goto('/register');

  const packageCard = page.locator('label').filter({ hasText: 'E2E Paket Psikotes Utama' }).first();
  await expect(packageCard).toBeVisible();
  await packageCard.locator('input[name="package_id"]').check();

  await page.locator('input[name="full_name"]').fill(participant.fullName);
  await page.locator('select[name="gender"]').selectOption('female');
  await page.locator('input[name="birth_date"]').fill(participant.birthDate);
  await page.locator('input[name="education_level"]').fill('SMA');
  await page.locator('select[name="intended_field"]').selectOption('KAIGO');
  await page.locator('input[name="phone"]').fill(participant.phone);
  await page.locator('input[name="email"]').fill(participant.email);
  await page.locator('input[name="include_consultation"]').check();
  await page.locator('input[name="payment_method_code"][value="manual_transfer"]').check();
  await page.locator('input[name="consent_psychotest"]').check();
  await page.locator('input[name="consent_dass"]').check();

  await page.getByRole('button', { name: /Simpan pendaftaran/i }).click();
  await expect(page).toHaveURL(/\/registration\/received/);
  await expect(page.getByText(/Bukti transfer manual/i)).toBeVisible();
  await assertNoHorizontalScroll(page);
}

async function uploadManualTransferProof(page: Page): Promise<void> {
  await page.locator('input[name="payment_proof"]').setInputFiles({
    name: 'e2e-transfer-proof.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n'),
  });
  await page.getByRole('button', { name: /Simpan bukti transfer/i }).click();
  await expect(page.getByText(/Bukti tersimpan dan menunggu verifikasi/i)).toBeVisible();
  await assertNoHorizontalScroll(page);
}

async function approveManualTransferAsBranchAdmin(page: Page): Promise<void> {
  await logout(page);
  await loginAs(page, 'branchAdmin');

  await page.goto('/admin/orders');
  await expect(page.getByText(participant.fullName)).toBeVisible();
  await expect(page.getByText(/Tersedia/i).first()).toBeVisible();

  await page.getByRole('button', { name: /^Setujui$/i }).first().click();
  await page.getByRole('button', { name: /^Setujui$/i }).last().click();
  await expect(page.getByText(/Transfer disetujui/i)).toBeVisible();
  await assertNoHorizontalScroll(page);
}

async function openParticipantLobbyWithReadyEntitlements(
  page: Page,
  testNumber: string,
  birthDate: string,
): Promise<void> {
  const login = await page.request.post('/api/auth/participant/login', {
    data: { test_number: testNumber, birth_date: birthDate },
  });
  expect(login.ok()).toBe(true);
  const body = (await login.json()) as { jwt: string };

  await page.addInitScript((jwt) => {
    window.sessionStorage.setItem('participant_access_token', jwt as string);
  }, body.jwt);
  await page.goto('/participant/lobby');

  await expect(page.getByRole('heading', { name: /Ruang psikotes/i })).toBeVisible();
  await expect(page.getByText(participant.fullName)).toBeVisible();
  await expect(page.getByText(testNumber)).toBeVisible();
  for (const label of ['Tes Inteligensi IST', 'Tes Kepribadian PAPI', 'RMIB', 'Kraepelin', 'DASS-21']) {
    await expect(page.getByText(label, { exact: false })).toBeVisible();
  }
}
