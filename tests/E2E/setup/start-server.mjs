import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

import { chromium } from 'playwright';

const rootDir = process.cwd();
const host = process.env.E2E_HOST || '127.0.0.1';
const port = process.env.E2E_PORT || '8014';
const databasePath = path.join(rootDir, 'storage', 'e2e', 'e2e.sqlite');

const requiredEnv = {
  APP_ENV: 'testing',
  APP_DEBUG: 'true',
  APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  APP_URL: `http://${host}:${port}`,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: databasePath,
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'file',
  QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'array',
  BROADCAST_CONNECTION: 'log',
  LOG_CHANNEL: 'stderr',
  CONSENT_LEGAL_REVIEW_PENDING: 'false',
  XENDIT_SECRET_KEY: '',
  XENDIT_WEBHOOK_TOKEN: 'e2e-synthetic-token',
};

function fail(message) {
  console.error(`\n[E2E preflight] ${message}\n`);
  process.exit(1);
}

function run(command, args) {
  const result = spawnSync(command, args, {
    cwd: rootDir,
    env: process.env,
    stdio: 'inherit',
    shell: process.platform === 'win32',
  });

  if (result.status !== 0) {
    fail(`Command failed: ${command} ${args.join(' ')}`);
  }
}

function assertEnvOverrides() {
  const missing = [];
  const wrong = [];

  for (const [key, expected] of Object.entries(requiredEnv)) {
    const actual = process.env[key];

    if (actual === undefined) {
      missing.push(key);
    } else if (actual !== expected) {
      wrong.push(`${key}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
  }

  if (missing.length > 0 || wrong.length > 0) {
    fail([
      'Missing or incorrect E2E environment overrides. The Playwright webServer',
      'must pass the full tests/E2E/support/env.ts laravelEnv override so Redis',
      'defaults from .env/.env.example do not boot the app into 500 responses.',
      missing.length > 0 ? `Missing: ${missing.join(', ')}` : '',
      wrong.length > 0 ? `Incorrect:\n- ${wrong.join('\n- ')}` : '',
    ].filter(Boolean).join('\n'));
  }
}

function assertBuildManifest() {
  const manifestPath = path.join(rootDir, 'public', 'build', 'manifest.json');

  if (!fs.existsSync(manifestPath)) {
    fail('Missing public/build/manifest.json. Run `npm run build` before `npm run e2e`.');
  }
}

function assertComposerAutoload() {
  const autoloadPath = path.join(rootDir, 'vendor', 'autoload.php');

  if (!fs.existsSync(autoloadPath)) {
    fail('Missing vendor/autoload.php. Run `composer install --no-interaction --prefer-dist` before `npm run e2e`.');
  }
}

function assertChromiumInstalled() {
  const executablePath = chromium.executablePath();

  if (!fs.existsSync(executablePath)) {
    fail(`Playwright Chromium is not installed at ${executablePath}. Run \`npx playwright install chromium\`.`);
  }
}

function ensureRuntimeDirectories() {
  for (const directory of [
    path.join(rootDir, 'storage', 'framework', 'views'),
    path.join(rootDir, 'storage', 'framework', 'cache', 'data'),
    path.join(rootDir, 'storage', 'framework', 'sessions'),
    path.join(rootDir, 'storage', 'framework', 'testing'),
    path.join(rootDir, 'storage', 'e2e'),
    path.join(rootDir, 'bootstrap', 'cache'),
  ]) {
    fs.mkdirSync(directory, { recursive: true });
  }
}

function resetDatabaseFile() {
  if (fs.existsSync(databasePath)) {
    fs.rmSync(databasePath);
  }

  fs.closeSync(fs.openSync(databasePath, 'w'));
}

function prepareDatabase() {
  resetDatabaseFile();
  run('php', ['artisan', 'migrate:fresh', '--force', '--seed']);
  run('php', ['tests/E2E/setup/seed-e2e.php']);
}

function startLaravelServer() {
  const server = spawn('php', ['artisan', 'serve', `--host=${host}`, `--port=${port}`], {
    cwd: rootDir,
    env: process.env,
    stdio: 'inherit',
    shell: process.platform === 'win32',
  });

  const stop = (signal) => {
    if (!server.killed) {
      server.kill(signal);
    }
  };

  process.on('SIGINT', () => stop('SIGINT'));
  process.on('SIGTERM', () => stop('SIGTERM'));
  process.on('exit', () => stop('SIGTERM'));

  server.on('exit', (code, signal) => {
    if (signal) {
      process.exit(0);
    }

    process.exit(code ?? 0);
  });
}

assertEnvOverrides();
assertComposerAutoload();
assertBuildManifest();
assertChromiumInstalled();
ensureRuntimeDirectories();
prepareDatabase();
startLaravelServer();
