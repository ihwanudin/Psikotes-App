import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    createIstSubtestNavigationState,
    goToItem,
    isSubtestComplete,
    istAnswersFromResumedAnswers,
    next,
    previous,
    selectAnswer,
    unansweredIndices,
} from './ist-subtest-navigation-state.ts';

test('createIstSubtestNavigationState builds an all-unanswered state at item 0', () => {
    const state = createIstSubtestNavigationState(20);

    assert.equal(state.answers.length, 20);
    assert.ok(state.answers.every((answer) => answer === null));
    assert.equal(state.currentIndex, 0);
});

test('createIstSubtestNavigationState rejects a non-positive item count', () => {
    assert.throws(() => createIstSubtestNavigationState(0), RangeError);
    assert.throws(() => createIstSubtestNavigationState(-1), RangeError);
});

test('createIstSubtestNavigationState seeds from initialAnswers when given', () => {
    const state = createIstSubtestNavigationState(3, ['a', null, '35']);

    assert.deepEqual(state.answers, ['a', null, '35']);
});

test('createIstSubtestNavigationState rejects initialAnswers of the wrong length', () => {
    assert.throws(() => createIstSubtestNavigationState(3, ['a']), RangeError);
});

test('selectAnswer records a multiple_choice letter without moving the cursor', () => {
    let state = createIstSubtestNavigationState(3);
    state = selectAnswer(state, 'c');

    assert.deepEqual(state.answers, ['c', null, null]);
    assert.equal(state.currentIndex, 0);
});

test('selectAnswer records free text for a fill-in item', () => {
    let state = createIstSubtestNavigationState(3);
    state = selectAnswer(state, '35');

    assert.deepEqual(state.answers, ['35', null, null]);
});

test('selectAnswer overwrites a previous answer for the same item', () => {
    let state = createIstSubtestNavigationState(3);
    state = selectAnswer(state, '35');
    state = selectAnswer(state, '42');

    assert.deepEqual(state.answers, ['42', null, null]);
});

test('selectAnswer treats an empty string as unanswered, not a real answer', () => {
    let state = createIstSubtestNavigationState(3);
    state = selectAnswer(state, '35');
    state = selectAnswer(state, '');

    assert.deepEqual(state.answers, [null, null, null]);
    assert.equal(isSubtestComplete(state), false);
});

test('goToItem moves the cursor without altering any answers', () => {
    let state = createIstSubtestNavigationState(3);
    state = selectAnswer(state, 'a');
    const beforeAnswers = state.answers.slice();

    state = goToItem(state, 2);

    assert.equal(state.currentIndex, 2);
    assert.deepEqual(state.answers, beforeAnswers);
});

test('goToItem rejects an out-of-range index', () => {
    const state = createIstSubtestNavigationState(3);

    assert.throws(() => goToItem(state, -1), RangeError);
    assert.throws(() => goToItem(state, 3), RangeError);
});

test('next advances by one and clamps at the last item (does not wrap)', () => {
    let state = createIstSubtestNavigationState(3);
    state = next(state);
    state = next(state);
    state = next(state);
    assert.equal(state.currentIndex, 2, 'must clamp, not wrap to 0');
});

test('previous moves back by one and clamps at the first item (does not wrap)', () => {
    let state = createIstSubtestNavigationState(3);
    state = goToItem(state, 2);
    state = previous(state);
    state = previous(state);
    state = previous(state);
    assert.equal(state.currentIndex, 0, 'must clamp, not wrap to the last');
});

test('unansweredIndices lists every unanswered item in ascending order', () => {
    let state = createIstSubtestNavigationState(5);
    state = goToItem(state, 1);
    state = selectAnswer(state, 'a');
    state = goToItem(state, 3);
    state = selectAnswer(state, 'b');

    assert.deepEqual(unansweredIndices(state), [0, 2, 4]);
});

test('isSubtestComplete is false until every item has an answer, then true', () => {
    let state = createIstSubtestNavigationState(2);
    assert.equal(isSubtestComplete(state), false);

    state = selectAnswer(state, 'a');
    assert.equal(isSubtestComplete(state), false);

    state = goToItem(state, 1);
    state = selectAnswer(state, '10');
    assert.equal(isSubtestComplete(state), true);
});

test('a typical flow: navigate, answer out of order, jump via unanswered links, finish', () => {
    let state = createIstSubtestNavigationState(4);

    state = selectAnswer(state, 'a'); // item 0
    state = next(state); // item 1
    state = selectAnswer(state, '12'); // item 1
    state = next(state); // item 2 (skip answering)
    state = next(state); // item 3
    state = selectAnswer(state, 'e'); // item 3

    assert.deepEqual(unansweredIndices(state), [2]);
    assert.equal(isSubtestComplete(state), false);

    state = goToItem(state, 2);
    state = selectAnswer(state, 'ayam');

    assert.deepEqual(unansweredIndices(state), []);
    assert.equal(isSubtestComplete(state), true);
    assert.deepEqual(state.answers, ['a', '12', 'ayam', 'e']);
});

test('istAnswersFromResumedAnswers filters session-wide resumed answers down to this subtest by item number', () => {
    const items = [{ item: 21 }, { item: 22 }, { item: 23 }];
    const resumed = [
        { itemNo: 1, value: 'a' }, // a different subtest (SE) — must be ignored
        { itemNo: 22, value: 'c' },
        { itemNo: 41, value: 'b' }, // a different subtest (AN) — must be ignored
    ];

    assert.deepEqual(istAnswersFromResumedAnswers(items, resumed), [
        null,
        'c',
        null,
    ]);
});

test('istAnswersFromResumedAnswers is all-null when nothing was resumed for this subtest', () => {
    const items = [{ item: 61 }, { item: 62 }];

    assert.deepEqual(istAnswersFromResumedAnswers(items, []), [null, null]);
});

test('istAnswersFromResumedAnswers drops a non-string or empty-string value', () => {
    const items = [{ item: 77 }, { item: 78 }, { item: 79 }];
    const resumed = [
        { itemNo: 77, value: 42 },
        { itemNo: 78, value: '' },
        { itemNo: 79, value: '15' },
    ];

    assert.deepEqual(istAnswersFromResumedAnswers(items, resumed), [
        null,
        null,
        '15',
    ]);
});
