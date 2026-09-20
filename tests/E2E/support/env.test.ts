import assert from 'node:assert/strict';
import path from 'node:path';
import test from 'node:test';

import { e2eEnv } from './env.ts';

const doubledWindowsDrivePattern = /([A-Za-z]:)[\\/]\1|[A-Za-z]:[\\/][A-Za-z]:/;

test('E2E filesystem paths do not contain a doubled Windows drive marker', () => {
  assert.doesNotMatch(e2eEnv.rootDir, doubledWindowsDrivePattern);
  assert.doesNotMatch(e2eEnv.databasePath, doubledWindowsDrivePattern);
  assert.doesNotMatch(e2eEnv.laravelEnv.DB_DATABASE, doubledWindowsDrivePattern);
});

test('the old URL pathname resolver reproduces the doubled-drive bug on Windows', () => {
  const syntheticMetaUrl = 'file:///C:/Users/ThinkPad/repo/tests/E2E/support/env.ts';
  const oldRootDir = path.resolve(new URL('../../..', syntheticMetaUrl).pathname);
  const oldNormalizedRoot = process.platform === 'win32' && oldRootDir.startsWith('/')
    ? oldRootDir.slice(1)
    : oldRootDir;

  if (process.platform === 'win32') {
    assert.match(oldNormalizedRoot, doubledWindowsDrivePattern);
  } else {
    assert.doesNotMatch(oldNormalizedRoot, doubledWindowsDrivePattern);
  }
});
