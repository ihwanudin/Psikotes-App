import path from 'node:path';

const rootDir = path.resolve(new URL('../../..', import.meta.url).pathname);
const normalizedRoot = process.platform === 'win32' && rootDir.startsWith('/')
  ? rootDir.slice(1)
  : rootDir;

const port = Number(process.env.E2E_PORT ?? '8014');
const host = process.env.E2E_HOST ?? '127.0.0.1';
const databasePath = path.join(normalizedRoot, 'storage', 'e2e', 'e2e.sqlite');

export const e2eEnv = {
  rootDir: normalizedRoot,
  host,
  port,
  baseURL: `http://${host}:${port}`,
  databasePath,
  laravelEnv: {
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
  },
};

export const e2eUsers = {
  psychologist: { email: 'e2e.psychologist@example.test', password: 'Password123!' },
  superAdmin: { email: 'e2e.super-admin@example.test', password: 'Password123!' },
  branchAdmin: { email: 'e2e.branch-admin@example.test', password: 'Password123!' },
  staff: { email: 'e2e.staff@example.test', password: 'Password123!' },
} as const;
