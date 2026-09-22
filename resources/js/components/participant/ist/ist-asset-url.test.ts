import assert from 'node:assert/strict';
import { test } from 'node:test';

import { isIstAssetUrlExpired } from './ist-asset-url.ts';

test('isIstAssetUrlExpired is false when now is before expiresAt', () => {
    assert.equal(
        isIstAssetUrlExpired(
            '2026-09-21T01:00:00+00:00',
            new Date('2026-09-21T00:59:00Z'),
        ),
        false,
    );
});

test('isIstAssetUrlExpired is true when now is after expiresAt', () => {
    assert.equal(
        isIstAssetUrlExpired(
            '2026-09-21T01:00:00+00:00',
            new Date('2026-09-21T01:00:01Z'),
        ),
        true,
    );
});

test('isIstAssetUrlExpired is true at the exact expiry instant (>=, not >)', () => {
    assert.equal(
        isIstAssetUrlExpired(
            '2026-09-21T01:00:00+00:00',
            new Date('2026-09-21T01:00:00Z'),
        ),
        true,
    );
});
