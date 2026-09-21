import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    createPapiNavigationState,
    goToItem,
    isComplete,
    next,
    papiAnswersFromResumedAnswers,
    previous,
    selectChoice,
    unansweredIndices,
} from './papi-navigation-state.ts';

test('createPapiNavigationState builds an all-unanswered state at item 0', () => {
    const state = createPapiNavigationState(90);

    assert.equal(state.answers.length, 90);
    assert.ok(state.answers.every((answer) => answer === null));
    assert.equal(state.currentIndex, 0);
});

test('createPapiNavigationState rejects a non-positive item count', () => {
    assert.throws(() => createPapiNavigationState(0), RangeError);
    assert.throws(() => createPapiNavigationState(-1), RangeError);
});

test('selectChoice records the choice at the current item without moving the cursor', () => {
    let state = createPapiNavigationState(3);
    state = selectChoice(state, 'a');

    assert.deepEqual(state.answers, ['a', null, null]);
    assert.equal(state.currentIndex, 0);
});

test('selectChoice overwrites a previous choice for the same item', () => {
    let state = createPapiNavigationState(3);
    state = selectChoice(state, 'a');
    state = selectChoice(state, 'b');

    assert.deepEqual(state.answers, ['b', null, null]);
});

test('goToItem moves the cursor without altering any answers', () => {
    let state = createPapiNavigationState(3);
    state = selectChoice(state, 'a');
    const beforeAnswers = state.answers.slice();

    state = goToItem(state, 2);

    assert.equal(state.currentIndex, 2);
    assert.deepEqual(state.answers, beforeAnswers);
});

test('goToItem rejects an out-of-range index', () => {
    const state = createPapiNavigationState(3);

    assert.throws(() => goToItem(state, -1), RangeError);
    assert.throws(() => goToItem(state, 3), RangeError);
});

test('goToItem to the already-current index returns the same state reference', () => {
    const state = createPapiNavigationState(3);
    const result = goToItem(state, 0);

    assert.equal(result, state);
});

test('next advances by one and clamps at the last item (does not wrap)', () => {
    let state = createPapiNavigationState(3);
    state = next(state);
    assert.equal(state.currentIndex, 1);
    state = next(state);
    assert.equal(state.currentIndex, 2);
    state = next(state);
    assert.equal(
        state.currentIndex,
        2,
        'must clamp at the last item, not wrap to 0',
    );
});

test('previous moves back by one and clamps at the first item (does not wrap)', () => {
    let state = createPapiNavigationState(3);
    state = goToItem(state, 2);
    state = previous(state);
    assert.equal(state.currentIndex, 1);
    state = previous(state);
    assert.equal(state.currentIndex, 0);
    state = previous(state);
    assert.equal(
        state.currentIndex,
        0,
        'must clamp at the first item, not wrap to the last',
    );
});

test('unansweredIndices lists every unanswered item in ascending order', () => {
    let state = createPapiNavigationState(5);
    state = goToItem(state, 1);
    state = selectChoice(state, 'a');
    state = goToItem(state, 3);
    state = selectChoice(state, 'b');

    assert.deepEqual(unansweredIndices(state), [0, 2, 4]);
});

test('unansweredIndices is empty once every item is answered', () => {
    let state = createPapiNavigationState(2);
    state = selectChoice(state, 'a');
    state = goToItem(state, 1);
    state = selectChoice(state, 'b');

    assert.deepEqual(unansweredIndices(state), []);
});

test('unansweredIndices lists everything when nothing is answered yet', () => {
    const state = createPapiNavigationState(3);

    assert.deepEqual(unansweredIndices(state), [0, 1, 2]);
});

test('isComplete is false until every item has an answer, then true', () => {
    let state = createPapiNavigationState(2);
    assert.equal(isComplete(state), false);

    state = selectChoice(state, 'a');
    assert.equal(isComplete(state), false);

    state = goToItem(state, 1);
    state = selectChoice(state, 'b');
    assert.equal(isComplete(state), true);
});

test('createPapiNavigationState seeds answers from initialAnswers when given', () => {
    const state = createPapiNavigationState(3, ['a', null, 'b']);

    assert.deepEqual(state.answers, ['a', null, 'b']);
    assert.equal(state.currentIndex, 0);
});

test('createPapiNavigationState rejects initialAnswers of the wrong length', () => {
    assert.throws(() => createPapiNavigationState(3, ['a', null]), RangeError);
});

test('createPapiNavigationState copies initialAnswers rather than aliasing it', () => {
    const seed: ('a' | 'b' | null)[] = ['a', null, null];
    const state = createPapiNavigationState(3, seed);
    const afterSelect = selectChoice(state, 'b');

    assert.deepEqual(
        seed,
        ['a', null, null],
        "the caller's array must be untouched",
    );
    assert.deepEqual(afterSelect.answers, ['b', null, null]);
});

test('papiAnswersFromResumedAnswers maps itemNo 1-based to a 0-based answers array', () => {
    const answers = papiAnswersFromResumedAnswers(4, [
        { itemNo: 1, value: 'a' },
        { itemNo: 3, value: 'b' },
    ]);

    assert.deepEqual(answers, ['a', null, 'b', null]);
});

test('papiAnswersFromResumedAnswers is all-null when there is nothing to resume', () => {
    assert.deepEqual(papiAnswersFromResumedAnswers(3, []), [null, null, null]);
});

test('papiAnswersFromResumedAnswers drops an out-of-range itemNo instead of throwing', () => {
    const answers = papiAnswersFromResumedAnswers(2, [
        { itemNo: 0, value: 'a' },
        { itemNo: 3, value: 'a' },
        { itemNo: 1, value: 'b' },
    ]);

    assert.deepEqual(answers, ['b', null]);
});

test('papiAnswersFromResumedAnswers drops a value that is not exactly "a" or "b"', () => {
    const answers = papiAnswersFromResumedAnswers(3, [
        { itemNo: 1, value: 'A' },
        { itemNo: 2, value: 1 },
        { itemNo: 3, value: null },
    ]);

    assert.deepEqual(answers, [null, null, null]);
});

test('a typical flow: navigate, answer out of order, jump via unanswered links, finish', () => {
    let state = createPapiNavigationState(4);

    state = selectChoice(state, 'a'); // item 0
    state = next(state); // item 1
    state = selectChoice(state, 'b'); // item 1
    state = next(state); // item 2 (skip answering)
    state = next(state); // item 3
    state = selectChoice(state, 'a'); // item 3

    assert.deepEqual(unansweredIndices(state), [2]);
    assert.equal(isComplete(state), false);

    // Summary screen's jump-to-unanswered-item link.
    state = goToItem(state, 2);
    state = selectChoice(state, 'b');

    assert.deepEqual(unansweredIndices(state), []);
    assert.equal(isComplete(state), true);
    assert.deepEqual(state.answers, ['a', 'b', 'b', 'a']);
});
