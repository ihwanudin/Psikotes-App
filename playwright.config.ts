import { defineConfig, devices } from '@playwright/test';
import { e2eEnv } from './tests/E2E/support/env';

const viewports = [
  { name: 'chromium-320', width: 320, height: 720 },
  { name: 'chromium-390', width: 390, height: 844 },
  { name: 'chromium-768', width: 768, height: 1024 },
  { name: 'chromium-1280', width: 1280, height: 900 },
];

export default defineConfig({
  testDir: './tests/E2E/specs',
  outputDir: './storage/e2e/test-results',
  fullyParallel: false,
  workers: 1,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: [
    ['line'],
    ['html', { outputFolder: './storage/e2e/playwright-report', open: 'never' }],
    ['junit', { outputFile: './storage/e2e/junit.xml' }],
  ],
  use: {
    ...devices['Desktop Chrome'],
    baseURL: e2eEnv.baseURL,
    browserName: 'chromium',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: viewports.map((viewport) => ({
    name: viewport.name,
    use: {
      viewport: { width: viewport.width, height: viewport.height },
      deviceScaleFactor: 1,
      isMobile: viewport.width < 768,
      hasTouch: viewport.width < 768,
    },
  })),
  webServer: {
    command: 'node tests/E2E/setup/start-server.cjs',
    // Readiness uses a static public file so the probe proves only that the PHP
    // process is answering; it does not depend on DB state, Redis `/health`, or
    // Vite's built manifest. Lead measured cold `/` at 7.8s and `/admin/login`
    // at 54s, so 300s gives ~5.5x the slow dynamic-page latency plus room for
    // the wrapper's migrate/seed pre-start work on slower Windows/CI machines.
    url: `${e2eEnv.baseURL}/favicon.ico`,
    reuseExistingServer: false,
    timeout: 300_000,
    env: e2eEnv.laravelEnv,
  },
});
