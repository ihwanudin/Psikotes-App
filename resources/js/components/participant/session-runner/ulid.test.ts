import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createUlidGenerator } from './ulid.ts';

const CROCKFORD_BASE32 = /^[0-9A-HJKMNP-TV-Z]{26}$/;

test('produces a 26-character Crockford base32 string', () => {
    const ulid = createUlidGenerator();
    const id = ulid();

    assert.equal(id.length, 26);
    assert.match(id, CROCKFORD_BASE32);
});

test('never contains the excluded letters I, L, O, U', () => {
    const ulid = createUlidGenerator();

    for (let i = 0; i < 200; i++) {
        const id = ulid();
        assert.doesNotMatch(id, /[ILOU]/);
    }
});

test('is monotonically increasing for repeated calls at the same millisecond', () => {
    const ulid = createUlidGenerator();
    const now = 1_726_800_000_000;

    const first = ulid(now);
    const second = ulid(now);
    const third = ulid(now);

    assert.equal(first.slice(0, 10), second.slice(0, 10));
    assert.equal(second.slice(0, 10), third.slice(0, 10));
    assert.ok(second > first);
    assert.ok(third > second);
});

test('increments the random component by exactly one within the same millisecond', () => {
    const ulid = createUlidGenerator();
    const now = 1_726_800_000_000;

    const first = ulid(now);
    const second = ulid(now);

    // Reconstruct the random suffix as a base-32 integer and confirm the
    // second call's value is exactly the first's plus one, not merely
    // "greater than" (which a fresh re-randomization could also satisfy).
    const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    const toBigInt = (random: string): bigint =>
        [...random].reduce(
            (accumulator, char) =>
                accumulator * 32n + BigInt(alphabet.indexOf(char)),
            0n,
        );

    const firstRandom = toBigInt(first.slice(10));
    const secondRandom = toBigInt(second.slice(10));

    assert.equal(secondRandom, firstRandom + 1n);
});

test('generates a fresh random component when the millisecond changes', () => {
    const ulid = createUlidGenerator();

    const first = ulid(1_726_800_000_000);
    const second = ulid(1_726_800_000_001);

    assert.notEqual(first.slice(0, 10), second.slice(0, 10));
});

test('encodes time as the first 10 characters, matching a known reference value', () => {
    const ulid = createUlidGenerator();
    const timestamp = 1_767_225_600_000; // 2026-01-01T00:00:00.000Z
    const id = ulid(timestamp);

    // Computed independently via BigInt division (a different code path
    // than the module's Number-based division/modulo loop), not copied
    // from the module's own output.
    const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    let remaining = BigInt(timestamp);
    const digits: number[] = [];

    for (let i = 0; i < 10; i++) {
        digits.unshift(Number(remaining % 32n));
        remaining /= 32n;
    }

    const expectedTimePart = digits.map((digit) => alphabet[digit]).join('');

    assert.equal(id.length, 26);
    assert.match(id, CROCKFORD_BASE32);
    assert.equal(id.slice(0, 10), expectedTimePart);
});

test('two independent generators never collide across 500 calls each', () => {
    const ulidA = createUlidGenerator();
    const ulidB = createUlidGenerator();
    const seen = new Set<string>();

    for (let i = 0; i < 500; i++) {
        seen.add(ulidA());
        seen.add(ulidB());
    }

    assert.equal(seen.size, 1000);
});

test('uses crypto.getRandomValues, not Math.random, for the random component', () => {
    const originalGetRandomValues = crypto.getRandomValues.bind(crypto);
    let callCount = 0;
    crypto.getRandomValues = ((array: Uint8Array) => {
        callCount++;

        return originalGetRandomValues(array);
    }) as typeof crypto.getRandomValues;

    try {
        const ulid = createUlidGenerator();
        ulid(1_000);
        ulid(2_000);
        ulid(3_000);

        assert.equal(
            callCount,
            3,
            'expected one getRandomValues call per fresh (non-monotonic) id',
        );
    } finally {
        crypto.getRandomValues = originalGetRandomValues;
    }
});

test('rejects a time outside the representable 48-bit range', () => {
    const ulid = createUlidGenerator();

    assert.throws(() => ulid(2 ** 48), RangeError);
    assert.throws(() => ulid(-1), RangeError);
});
