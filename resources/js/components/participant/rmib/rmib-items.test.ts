import assert from 'node:assert/strict';
import { test } from 'node:test';

import type { GenericItemsOutcome } from '../session-runner/http-transport.ts';
import {
    groupRmibPositions,
    rmibGroupAndPositionFromItemNo,
    rmibItemNo,
    rmibItemsOutcomeFromGeneric,
    rmibPositionsFromSubtests,
} from './rmib-items.ts';
import type {
    RmibInstructions,
    RmibItemsOutcome,
    RmibPosition,
} from './rmib-items.ts';

function syntheticPositions(): RmibPosition[] {
    const positions: RmibPosition[] = [];

    for (let group = 1; group <= 9; group++) {
        for (let position = 1; position <= 12; position++) {
            positions.push({
                group,
                group_letter: String.fromCharCode(64 + group),
                position,
                job: `Job ${group}-${position}`,
            });
        }
    }

    return positions;
}

test('rmibPositionsFromSubtests unwraps the single POSITIONS subtest', () => {
    const positions = syntheticPositions();
    const result = rmibPositionsFromSubtests([
        { code: 'POSITIONS', items: positions },
    ]);

    assert.equal(result, positions);
});

test('rmibPositionsFromSubtests rejects zero POSITIONS subtests', () => {
    assert.throws(() => rmibPositionsFromSubtests([]), RangeError);
});

test('rmibPositionsFromSubtests rejects more than one POSITIONS subtest', () => {
    const positions = syntheticPositions();

    assert.throws(
        () =>
            rmibPositionsFromSubtests([
                { code: 'POSITIONS', items: positions.slice(0, 12) },
                { code: 'POSITIONS', items: positions.slice(12, 24) },
            ]),
        RangeError,
    );
});

test('rmibPositionsFromSubtests rejects a subtest with the wrong code', () => {
    assert.throws(
        () =>
            rmibPositionsFromSubtests([
                { code: 'ITEMS', items: syntheticPositions() },
            ]),
        RangeError,
    );
});

test('rmibItemNo computes the flat 1-108 sequence group-major', () => {
    assert.equal(rmibItemNo(1, 1), 1);
    assert.equal(rmibItemNo(1, 12), 12);
    assert.equal(rmibItemNo(2, 1), 13);
    assert.equal(rmibItemNo(9, 12), 108);
});

test('groupRmibPositions splits 108 flat positions into 9 groups of 12, in source order', () => {
    const positions = syntheticPositions();
    const groups = groupRmibPositions(positions);

    assert.equal(groups.length, 9);
    groups.forEach((group, groupIndex) => {
        assert.equal(group.length, 12);
        group.forEach((entry, positionIndex) => {
            assert.equal(entry.group, groupIndex + 1);
            assert.equal(entry.position, positionIndex + 1);
        });
    });
});

test('rmibGroupAndPositionFromItemNo is the exact inverse of rmibItemNo', () => {
    for (let group = 1; group <= 9; group++) {
        for (let position = 1; position <= 12; position++) {
            const itemNo = rmibItemNo(group, position);

            assert.deepEqual(rmibGroupAndPositionFromItemNo(itemNo), {
                group,
                position,
            });
        }
    }
});

test('rmibGroupAndPositionFromItemNo rejects an out-of-range itemNo', () => {
    assert.throws(() => rmibGroupAndPositionFromItemNo(0), RangeError);
    assert.throws(() => rmibGroupAndPositionFromItemNo(109), RangeError);
    assert.throws(() => rmibGroupAndPositionFromItemNo(1.5), RangeError);
});

test('groupRmibPositions rejects a list that is not exactly 108 entries', () => {
    assert.throws(
        () => groupRmibPositions(syntheticPositions().slice(0, 107)),
        RangeError,
    );
    assert.throws(
        () =>
            groupRmibPositions([
                ...syntheticPositions(),
                syntheticPositions()[0]!,
            ]),
        RangeError,
    );
});

const INSTRUCTIONS: RmibInstructions = {
    text: 'text',
    write_preferred_jobs_prompt: 'prompt',
};

test('rmibItemsOutcomeFromGeneric unwraps an available generic outcome into RmibItemsOutcome', () => {
    const positions = syntheticPositions();
    const generic: GenericItemsOutcome = {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'rmib',
            version: 'v1',
            subtests: [{ code: 'POSITIONS', items: positions }],
            instructions: INSTRUCTIONS,
        },
    };

    const result = rmibItemsOutcomeFromGeneric(generic);

    assert.deepEqual(result, {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'rmib',
            version: 'v1',
            positions,
            instructions: INSTRUCTIONS,
        },
    } satisfies RmibItemsOutcome);
});

test('rmibItemsOutcomeFromGeneric passes every non-available outcome through unchanged', () => {
    const nonAvailable: GenericItemsOutcome[] = [
        { type: 'not_started' },
        { type: 'closed' },
        { type: 'deadline_exceeded' },
        { type: 'not_found' },
        { type: 'content_unavailable' },
        { type: 'network_error' },
    ];

    for (const outcome of nonAvailable) {
        assert.deepEqual(rmibItemsOutcomeFromGeneric(outcome), outcome);
    }
});

test('rmibItemsOutcomeFromGeneric still throws on a malformed available envelope (not RMIB-shaped)', () => {
    const generic: GenericItemsOutcome = {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'rmib',
            version: 'v1',
            subtests: [{ code: 'ITEMS', items: [] }],
            instructions: INSTRUCTIONS,
        },
    };

    assert.throws(() => rmibItemsOutcomeFromGeneric(generic), RangeError);
});
