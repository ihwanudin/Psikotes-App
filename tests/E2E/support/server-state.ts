import { spawnSync } from 'node:child_process';
import { e2eEnv } from './env';

type ParticipantState = {
  participant: {
    id: number;
    fullName: string;
    email: string;
    testNumber: string;
    birthDate: string;
    branchId: number;
  };
  orders: Array<{
    id: number;
    publicId: string;
    status: string;
    proofObjectKey: string | null;
    paidAt: string | null;
    paymentMethodCode: string | null;
  }>;
  entitlements: Array<{
    testType: string;
    status: string;
    readyAt: string | null;
  }>;
};

type F7Metrics = {
  participants: number;
  pendingBills: number;
  paidBills: number;
  readyEntitlements: number;
  proctoringRisk: number;
  commissionLedgerGaps: number;
  scope: string;
};

function artisanJson<T>(args: string[]): T {
  const result = spawnSync('php', ['tests/E2E/setup/e2e-state.php', ...args], {
    cwd: e2eEnv.rootDir,
    env: { ...process.env, ...e2eEnv.laravelEnv },
    encoding: 'utf8',
    shell: process.platform === 'win32',
  });

  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `E2E state command failed: ${args.join(' ')}`);
  }

  return JSON.parse(result.stdout) as T;
}

export function participantByEmail(email: string): ParticipantState {
  return artisanJson<ParticipantState>(['participant-by-email', email]);
}

export function f7MetricsFor(adminEmail: string): F7Metrics {
  return artisanJson<F7Metrics>(['f7-metrics', adminEmail]);
}
