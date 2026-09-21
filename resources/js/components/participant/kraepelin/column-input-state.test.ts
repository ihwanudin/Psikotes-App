import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    backspace,
    createColumnInputState,
    enterDigit,
    focusSlot,
    isComplete,
} from './column-input-state.ts';

test('createColumnInputState builds an all-empty state focused at slot 0', () => {
    const state = createColumnInputState(27);

    assert.equal(state.values.length, 27);
    assert.ok(state.values.every((value) => value === null));
    assert.equal(state.focusedIndex, 0);
});

test('createColumnInputState rejects a non-positive slot count', () => {
    assert.throws(() => createColumnInputState(0), RangeError);
    assert.throws(() => createColumnInputState(-1), RangeError);
});

test('enterDigit fills the focused slot and advances to the next one', () => {
    const state = createColumnInputState(3);
    const next = enterDigit(state, '5');

    assert.deepEqual(next.values, ['5', null, null]);
    assert.equal(next.focusedIndex, 1);
});

test('enterDigit at the last slot fills it and stays there (does not wrap)', () => {
    let state = createColumnInputState(2);
    state = enterDigit(state, '1');
    assert.equal(state.focusedIndex, 1);
    state = enterDigit(state, '2');

    assert.deepEqual(state.values, ['1', '2']);
    assert.equal(
        state.focusedIndex,
        1,
        'must clamp at the last slot, not wrap to 0',
    );
});

test('enterDigit rejects anything that is not exactly one digit', () => {
    const state = createColumnInputState(3);

    assert.throws(() => enterDigit(state, ''), RangeError);
    assert.throws(() => enterDigit(state, '12'), RangeError);
    assert.throws(() => enterDigit(state, 'a'), RangeError);
    assert.throws(() => enterDigit(state, '-1'), RangeError);
});

test('backspace on a filled focused slot clears it without moving the cursor', () => {
    let state = createColumnInputState(3);
    state = enterDigit(state, '7'); // fills slot 0, focus -> 1
    state = focusSlot(state, 0);
    state = backspace(state);

    assert.deepEqual(state.values, [null, null, null]);
    assert.equal(state.focusedIndex, 0);
});

test('backspace on an empty focused slot moves back and clears the previous slot', () => {
    let state = createColumnInputState(3);
    state = enterDigit(state, '7'); // slot 0 = '7', focus -> 1
    state = backspace(state); // slot 1 is empty -> moves back, clears slot 0

    assert.deepEqual(state.values, [null, null, null]);
    assert.equal(state.focusedIndex, 0);
});

test('backspace at slot 0 with nothing filled is a no-op', () => {
    const state = createColumnInputState(3);
    const next = backspace(state);

    assert.deepEqual(next, state);
});

test('focusSlot moves the cursor without altering any values — the correction affordance', () => {
    let state = createColumnInputState(3);
    state = enterDigit(state, '1');
    state = enterDigit(state, '2');
    const beforeValues = state.values.slice();

    state = focusSlot(state, 0);

    assert.equal(state.focusedIndex, 0);
    assert.deepEqual(state.values, beforeValues);
});

test('focusSlot rejects an out-of-range index', () => {
    const state = createColumnInputState(3);

    assert.throws(() => focusSlot(state, -1), RangeError);
    assert.throws(() => focusSlot(state, 3), RangeError);
});

test('focusSlot to the already-focused index returns the same state reference (no redundant update)', () => {
    const state = createColumnInputState(3);
    const next = focusSlot(state, 0);

    assert.equal(next, state);
});

test('correcting a mistyped digit: focus back, retype, and the cursor advances from the corrected slot', () => {
    let state = createColumnInputState(4);
    state = enterDigit(state, '1'); // slot0='1', focus 1
    state = enterDigit(state, '9'); // slot1='9' (mistyped), focus 2
    state = enterDigit(state, '3'); // slot2='3', focus 3

    state = focusSlot(state, 1); // participant taps back to fix slot 1
    state = enterDigit(state, '2'); // corrected to '2', advances to slot 2 again

    assert.deepEqual(state.values, ['1', '2', '3', null]);
    assert.equal(state.focusedIndex, 2);
});

test('isComplete is true only once every slot has a value', () => {
    let state = createColumnInputState(2);
    assert.equal(isComplete(state), false);

    state = enterDigit(state, '1');
    assert.equal(isComplete(state), false);

    state = enterDigit(state, '2');
    assert.equal(isComplete(state), true);
});

test('typing a full sequence advances through every slot in order and stops at the end', () => {
    let state = createColumnInputState(5);
    const digits = ['1', '2', '3', '4', '5'];

    for (const digit of digits) {
        state = enterDigit(state, digit);
    }

    assert.deepEqual(state.values, digits);
    assert.equal(state.focusedIndex, 4);
    assert.equal(isComplete(state), true);
});
