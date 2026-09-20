async function globalSetup(): Promise<void> {
  // Intentionally empty. Database reset/seed now runs inside
  // tests/E2E/setup/start-server.cjs before `php artisan serve` starts, because
  // Playwright waits for webServer readiness before globalSetup would run.
}

export default globalSetup;
