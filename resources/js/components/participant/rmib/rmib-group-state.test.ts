import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    confirmCurrentOrder,
    createRmibGroupState,
    isGroupComplete,
    moveDown,
    moveUp,
    rankOf,
    reorder,
    rmibGroupStateFromRanks,
    rmibGroupStatesFromResumedAnswers,
} from './rmib-group-state.ts';
import { rmibItemNo } from './rmib-items.ts';

test('createRmibGroupState starts in document order 1..12 and unconfirmed', () => {
    const state = createRmibGroupState();

    assert.deepEqual(state.order, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
    assert.equal(state.confirmed, false);
});

test('an untouched, unconfirmed group is never counted as answered', () => {
    const state = createRmibGroupState();

    assert.equal(isGroupComplete(state), false);
});

test('rankOf reads the 1-based rank of a position from its array index', () => {
    const state = createRmibGroupState();

    assert.equal(rankOf(state, 1), 1);
    assert.equal(rankOf(state, 12), 12);
});

test('rankOf throws for a position outside this group', () => {
    const state = createRmibGroupState();

    assert.throws(() => rankOf(state, 13), RangeError);
    assert.throws(() => rankOf(state, 0), RangeError);
});

// --- Path 1: reordering auto-confirms the group. ---

test('moveUp swaps a position with the one ranked just above it, and confirms the group', () => {
    let state = createRmibGroupState();
    state = moveUp(state, 5); // position 5 starts at rank 5 (index 4)

    assert.deepEqual(state.order, [1, 2, 3, 5, 4, 6, 7, 8, 9, 10, 11, 12]);
    assert.equal(rankOf(state, 5), 4);
    assert.equal(state.confirmed, true);
});

test('moveUp on the already-first-rank position is a no-op on order, but still confirms', () => {
    let state = createRmibGroupState();
    state = moveUp(state, 1);

    assert.deepEqual(state.order, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
    assert.equal(state.confirmed, true);
});

test('moveDown swaps a position with the one ranked just below it, and confirms the group', () => {
    let state = createRmibGroupState();
    state = moveDown(state, 5);

    assert.deepEqual(state.order, [1, 2, 3, 4, 6, 5, 7, 8, 9, 10, 11, 12]);
    assert.equal(rankOf(state, 5), 6);
    assert.equal(state.confirmed, true);
});

test('moveDown on the already-last-rank position is a no-op on order, but still confirms', () => {
    let state = createRmibGroupState();
    state = moveDown(state, 12);

    assert.deepEqual(state.order, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
    assert.equal(state.confirmed, true);
});

test('every rank remains a permutation of 1-12 after a sequence of moves', () => {
    let state = createRmibGroupState();
    state = moveUp(state, 12);
    state = moveUp(state, 12);
    state = moveDown(state, 1);
    state = moveDown(state, 3);

    assert.deepEqual(
        [...state.order].sort((a, b) => a - b),
        [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
    );
});

test('reorder moves an item from one rank to another, shifting the rest, and confirms', () => {
    let state = createRmibGroupState();
    state = reorder(state, 11, 0); // position 12 (rank 12) -> rank 1

    assert.deepEqual(state.order, [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
    assert.equal(state.confirmed, true);
});

test('reorder to the same index is a no-op on order, but still confirms', () => {
    let state = createRmibGroupState();
    state = reorder(state, 3, 3);

    assert.deepEqual(state.order, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
    assert.equal(state.confirmed, true);
});

test('reorder rejects an out-of-range index', () => {
    const state = createRmibGroupState();

    assert.throws(() => reorder(state, -1, 0), RangeError);
    assert.throws(() => reorder(state, 0, 12), RangeError);
});

// --- Path 2: "Simpan urutan ini" confirms without touching order. ---

test('confirmCurrentOrder marks the group answered without changing order', () => {
    const state = createRmibGroupState();
    const confirmed = confirmCurrentOrder(state);

    assert.deepEqual(confirmed.order, state.order);
    assert.equal(confirmed.confirmed, true);
});

test('confirmCurrentOrder lets a participant keep the document order as their real answer', () => {
    let state = createRmibGroupState();
    assert.equal(isGroupComplete(state), false, 'must start unanswered');

    state = confirmCurrentOrder(state);

    assert.equal(isGroupComplete(state), true);
    assert.deepEqual(state.order, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
});

// --- Resume rehydration. ---

test('rmibGroupStateFromRanks reconstructs order from resumed (position, rank) pairs, confirmed', () => {
    // A cyclic permutation: position i is ranked (i % 12) + 1, e.g.
    // position 1 -> rank 2, ..., position 11 -> rank 12, position 12 ->
    // rank 1. Valid (every rank 1-12 used exactly once) and easy to
    // hand-verify: rank 1 is held by position 12, so order[0] === 12.
    const ranks = new Map<number, number>();

    for (let position = 1; position <= 12; position++) {
        ranks.set(position, (position % 12) + 1);
    }

    const state = rmibGroupStateFromRanks(ranks);

    assert.equal(state.confirmed, true);
    assert.deepEqual(state.order, [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
});

test('rmibGroupStateFromRanks rejects a partial set of ranks', () => {
    const ranks = new Map<number, number>();

    for (let position = 1; position <= 11; position++) {
        ranks.set(position, position);
    }

    assert.throws(() => rmibGroupStateFromRanks(ranks), RangeError);
});

test('rmibGroupStateFromRanks rejects a duplicated rank', () => {
    const ranks = new Map<number, number>();

    for (let position = 1; position <= 12; position++) {
        ranks.set(position, position === 12 ? 11 : position);
    }

    assert.throws(() => rmibGroupStateFromRanks(ranks), RangeError);
});

// --- rmibGroupStatesFromResumedAnswers: resume rehydration across all 9 groups. ---

function identityAnswersForGroup(
    group: number,
): { itemNo: number; value: string }[] {
    const answers: { itemNo: number; value: string }[] = [];

    for (let position = 1; position <= 12; position++) {
        answers.push({
            itemNo: rmibItemNo(group, position),
            value: String(position),
        });
    }

    return answers;
}

test('rmibGroupStatesFromResumedAnswers reconstructs a fully-answered group as confirmed', () => {
    const states = rmibGroupStatesFromResumedAnswers(
        identityAnswersForGroup(3),
    );

    assert.equal(states.length, 9);
    assert.equal(states[2]!.confirmed, true);
    assert.deepEqual(states[2]!.order, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
});

test('rmibGroupStatesFromResumedAnswers leaves every other group unconfirmed and in document order', () => {
    const states = rmibGroupStatesFromResumedAnswers(
        identityAnswersForGroup(3),
    );

    for (let groupIndex = 0; groupIndex < 9; groupIndex++) {
        if (groupIndex === 2) {
            continue;
        }

        assert.equal(
            states[groupIndex]!.confirmed,
            false,
            `group ${groupIndex + 1}`,
        );
        assert.deepEqual(
            states[groupIndex]!.order,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
            `group ${groupIndex + 1}`,
        );
    }
});

test('rmibGroupStatesFromResumedAnswers with nothing resumed returns 9 fresh unconfirmed groups', () => {
    const states = rmibGroupStatesFromResumedAnswers([]);

    assert.equal(states.length, 9);
    assert.ok(states.every((state) => state.confirmed === false));
});

test('rmibGroupStatesFromResumedAnswers falls back to unconfirmed for a group with fewer than 12 answers', () => {
    const partial = identityAnswersForGroup(5).slice(0, 11);
    const states = rmibGroupStatesFromResumedAnswers(partial);

    assert.equal(states[4]!.confirmed, false);
});

test('rmibGroupStatesFromResumedAnswers falls back to unconfirmed for a group with a duplicated rank', () => {
    const invalid = identityAnswersForGroup(7).map((answer, index) =>
        index === 0 ? { ...answer, value: '2' } : answer,
    );
    const states = rmibGroupStatesFromResumedAnswers(invalid);

    assert.equal(states[6]!.confirmed, false);
});

test('rmibGroupStatesFromResumedAnswers drops an entry whose value is not a valid rank string', () => {
    const withBadValue = identityAnswersForGroup(2);
    withBadValue[0] = { itemNo: withBadValue[0]!.itemNo, value: '13' };
    const states = rmibGroupStatesFromResumedAnswers(withBadValue);

    // Only 11 of the 12 entries for group 2 are valid now, so it must
    // fall back to unconfirmed rather than reconstruct a bogus order.
    assert.equal(states[1]!.confirmed, false);
});
