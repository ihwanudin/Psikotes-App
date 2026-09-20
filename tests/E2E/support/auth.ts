import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

import { e2eUsers } from './env';

export type AdminUser = keyof typeof e2eUsers;

export async function loginAs(page: Page, user: AdminUser): Promise<void> {
  const credentials = e2eUsers[user];

  await page.goto('/admin/login');
  await page.locator('input[name="email"]').fill(credentials.email);
  await page.locator('input[name="password"]').fill(credentials.password);
  await page.getByRole('button').filter({ hasText: /sign in|log in|masuk/i }).click();
  await expect(page).toHaveURL(/\/admin(?:\/)?$/);
}

export async function logout(page: Page): Promise<void> {
  await page.context().clearCookies();
}
