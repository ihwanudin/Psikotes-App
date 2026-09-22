import assert from 'node:assert/strict';
import { test } from 'node:test';

import type { GenericItemsOutcome } from '../session-runner/http-transport.ts';
import {
    papiItemsFromSubtests,
    papiItemsOutcomeFromGeneric,
} from './papi-items.ts';
import type {
    PapiInstructions,
    PapiItem,
    PapiItemsOutcome,
} from './papi-items.ts';

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

const INSTRUCTIONS: PapiInstructions = {
    intro: 'intro',
    example: { statement_a: 'ex-a', statement_b: 'ex-b' },
    answer_sheet_demo: {
        label: 'demo',
        statement_a: 'demo-a',
        statement_b: 'demo-b',
    },
    closing: 'closing',
};

test('papiItemsOutcomeFromGeneric unwraps an available generic outcome into PapiItemsOutcome', () => {
    const items: PapiItem[] = [
        { item: 1, statement_a: 'A1', statement_b: 'B1' },
    ];
    const generic: GenericItemsOutcome = {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'papi',
            version: 'v1',
            subtests: [{ code: 'ITEMS', items }],
            instructions: INSTRUCTIONS,
        },
    };

    const result = papiItemsOutcomeFromGeneric(generic);

    assert.deepEqual(result, {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'papi',
            version: 'v1',
            items,
            instructions: INSTRUCTIONS,
        },
    } satisfies PapiItemsOutcome);
});

test('papiItemsOutcomeFromGeneric passes every non-available outcome through unchanged', () => {
    const nonAvailable: GenericItemsOutcome[] = [
        { type: 'not_started' },
        { type: 'closed' },
        { type: 'deadline_exceeded' },
        { type: 'not_found' },
        { type: 'content_unavailable' },
        { type: 'network_error' },
    ];

    for (const outcome of nonAvailable) {
        assert.deepEqual(papiItemsOutcomeFromGeneric(outcome), outcome);
    }
});

test('papiItemsOutcomeFromGeneric still throws on a malformed available envelope (not PAPI-shaped)', () => {
    const generic: GenericItemsOutcome = {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'papi',
            version: 'v1',
            subtests: [{ code: 'col_01', items: [] }],
            instructions: INSTRUCTIONS,
        },
    };

    assert.throws(() => papiItemsOutcomeFromGeneric(generic), RangeError);
});
