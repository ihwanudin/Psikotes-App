import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { e2eEnv } from './support/env';

function run(command: string, args: string[]): void {
  const result = spawnSync(command, args, {
    cwd: e2eEnv.rootDir,
    env: { ...process.env, ...e2eEnv.laravelEnv },
    stdio: 'inherit',
    shell: process.platform === 'win32',
  });

  if (result.status !== 0) {
    throw new Error(`Command failed: ${command} ${args.join(' ')}`);
  }
}

function ensureSyntheticEnv(): void {
  const envPath = path.join(e2eEnv.rootDir, '.env');
  if (fs.existsSync(envPath)) {
    return;
  }

  const lines = Object.entries(e2eEnv.laravelEnv).map(([key, value]) => `${key}=${value}`);
  fs.writeFileSync(envPath, `${lines.join('\n')}\n`, 'utf8');
}

async function globalSetup(): Promise<void> {
  ensureSyntheticEnv();

  for (const directory of [
    path.join(e2eEnv.rootDir, 'storage', 'framework', 'views'),
    path.join(e2eEnv.rootDir, 'storage', 'framework', 'cache', 'data'),
    path.join(e2eEnv.rootDir, 'storage', 'framework', 'sessions'),
    path.join(e2eEnv.rootDir, 'storage', 'framework', 'testing'),
    path.join(e2eEnv.rootDir, 'storage', 'e2e'),
    path.join(e2eEnv.rootDir, 'bootstrap', 'cache'),
  ]) {
    fs.mkdirSync(directory, { recursive: true });
  }

  if (fs.existsSync(e2eEnv.databasePath)) {
    fs.rmSync(e2eEnv.databasePath);
  }
  fs.closeSync(fs.openSync(e2eEnv.databasePath, 'w'));

  const manifestPath = path.join(e2eEnv.rootDir, 'public', 'build', 'manifest.json');
  if (!fs.existsSync(manifestPath)) {
    throw new Error('Missing public/build/manifest.json. Run `npm run build` before `npm run e2e`.');
  }

  run('php', ['artisan', 'migrate:fresh', '--force', '--seed']);
  run('php', ['tests/E2E/setup/seed-e2e.php']);
}

export default globalSetup;
