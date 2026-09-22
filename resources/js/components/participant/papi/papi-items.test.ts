import assert from 'node:assert/strict';
import { test } from 'node:test';

import { papiItemsFromSubtests } from './papi-items.ts';
import type { PapiItem } from './papi-items.ts';

test('unwraps the single ITEMS subtest into a flat items array', () => {
    const items: PapiItem[] = [
        { item: 1, statement_a: 'A1', statement_b: 'B1' },
        { item: 2, statement_a: 'A2', statement_b: 'B2' },
    ];

    const result = papiItemsFromSubtests([{ code: 'ITEMS', items }]);

    assert.equal(result, items, 'must return the same items array, unmodified');
});

test('rejects an envelope with zero subtests', () => {
    assert.throws(() => papiItemsFromSubtests([]), RangeError);
});

test('rejects an envelope with more than one subtest (not PAPI-shaped)', () => {
    assert.throws(
        () =>
            papiItemsFromSubtests([
                { code: 'ITEMS', items: [] },
                { code: 'col_01', items: [] },
            ]),
        RangeError,
    );
});

test('rejects a single subtest that is not coded ITEMS', () => {
    assert.throws(
        () => papiItemsFromSubtests([{ code: 'col_01', items: [] }]),
        RangeError,
    );
});
