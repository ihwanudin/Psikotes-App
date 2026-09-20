import { expect, type Page } from '@playwright/test';
import { e2eEnv } from './env';

export async function installNetworkGuards(page: Page): Promise<void> {
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin === e2eEnv.baseURL) {
      await route.continue();
      return;
    }

    if (url.hostname === 'ui-avatars.com') {
      await route.fulfill({
        status: 204,
        body: '',
      });
      return;
    }

    await route.abort('blockedbyclient');
  });
}

export async function assertNoHorizontalScroll(page: Page): Promise<void> {
  await expect
    .poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth))
    .toBe(true);
}
