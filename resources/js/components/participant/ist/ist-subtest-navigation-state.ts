/**
 * Pure navigation/answer state for ONE IST subtest's items — no React, no
 * fetch, testable via `node --test`. Mirrors `papi-navigation-state.ts`'s
 * shape and contract exactly; the only difference is IST's answer is a
 * free string (a single option letter for multiple_choice, or free text
 * for fill_in_word/fill_in_numeric) rather than PAPI's closed `'a'|'b'`
 * union, since a fill-in answer isn't a fixed enum.
 *
 * Deliberately scoped to ONE subtest (Lead's 2026-09-21 review: "menerima
 * satu subtes... Navigasi antar-butir hanya di dalam subtes itu") — moving
 * BETWEEN subtests is a server transition
 * (`POST /sessions/:id/subtest/next`, not built yet) that this module has
 * no involvement in.
 *
 * **Answer value contract (verified against the real scoring code, not
 * guessed):** `ScoreSealedIstAnswerSet.php:210` requires `is_string($answer['value'])`
 * — for multiple_choice this is the single option letter (`'a'`-`'e'`,
 * matching the `options` object's own keys); for fill_in_word/fill_in_numeric
 * it's the participant's free-text answer as typed (GE is matched against
 * a word-association dictionary, not a single fixed key —
 * `IstRawScoreCalculator.php`).
 */

export type IstItemAnswer = string;

export type IstSubtestNavigationState = {
    /** One entry per item, in the same order as the subtest's `items`
     * array; `null` means not yet answered. */
    answers: (IstItemAnswer | null)[];
    /** Index of the item currently shown. */
    currentIndex: number;
};

export function createIstSubtestNavigationState(
    itemCount: number,
    initialAnswers?: (IstItemAnswer | null)[],
): IstSubtestNavigationState {
    if (itemCount <= 0) {
        throw new RangeError(
            'createIstSubtestNavigationState: itemCount must be positive',
        );
    }

    if (initialAnswers !== undefined && initialAnswers.length !== itemCount) {
        throw new RangeError(
            'createIstSubtestNavigationState: initialAnswers length must equal itemCount',
        );
    }

    return {
        answers: initialAnswers
            ? initialAnswers.slice()
            : new Array<IstItemAnswer | null>(itemCount).fill(null),
        currentIndex: 0,
    };
}

/** Records `answer` for the currently-shown item. Does not move the
 * cursor — navigation is a separate, explicit action. An empty string is
 * treated as "not yet answered" (`null`), the same as never having typed
 * anything — a fill-in input cleared back to blank must not count as a
 * real answer. */
export function selectAnswer(
    state: IstSubtestNavigationState,
    answer: string,
): IstSubtestNavigationState {
    const answers = state.answers.slice();
    answers[state.currentIndex] = answer === '' ? null : answer;

    return { ...state, answers };
}

/** Moves directly to any item, without altering any answers — used for
 * both forward/back navigation and the summary screen's "jump to this
 * unanswered item" links. */
export function goToItem(
    state: IstSubtestNavigationState,
    index: number,
): IstSubtestNavigationState {
    if (index < 0 || index >= state.answers.length) {
        throw new RangeError(`goToItem: index ${index} out of range`);
    }

    if (state.currentIndex === index) {
        return state;
    }

    return { ...state, currentIndex: index };
}

/** Moves to the next item, clamped at the last one (does not wrap). */
export function next(
    state: IstSubtestNavigationState,
): IstSubtestNavigationState {
    return goToItem(
        state,
        Math.min(state.currentIndex + 1, state.answers.length - 1),
    );
}

/** Moves to the previous item, clamped at the first one (does not wrap). */
export function previous(
    state: IstSubtestNavigationState,
): IstSubtestNavigationState {
    return goToItem(state, Math.max(state.currentIndex - 1, 0));
}

/** Indices (0-based) of every item still unanswered, in ascending order —
 * what a pre-submit summary would list as jump links. */
export function unansweredIndices(state: IstSubtestNavigationState): number[] {
    const indices: number[] = [];

    state.answers.forEach((answer, index) => {
        if (answer === null) {
            indices.push(index);
        }
    });

    return indices;
}

/** True only once every item in this subtest has an answer. */
export function isSubtestComplete(state: IstSubtestNavigationState): boolean {
    return state.answers.every((answer) => answer !== null);
}

/**
 * Builds the `answers` seed array for `createIstSubtestNavigationState`
 * from a resumed `GET /sessions/:id/answers` outcome (session-wide,
 * `resume-answers.ts`'s `ResumedAnswer[]`) — filtered down to just this
 * subtest's own items by matching `itemNo` against each item's own
 * `item` number (which IS the global item_no — see `ist-items.ts`'s
 * doc). A malformed entry (a non-string value) is dropped rather than
 * thrown, same "resumed data already passed server validation when
 * originally saved" reasoning `papiAnswersFromResumedAnswers` uses.
 */
export function istAnswersFromResumedAnswers(
    items: { item: number }[],
    resumedAnswers: { itemNo: number; value: unknown }[],
): (IstItemAnswer | null)[] {
    const valueByItemNo = new Map<number, string>();

    for (const { itemNo, value } of resumedAnswers) {
        if (typeof value === 'string' && value !== '') {
            valueByItemNo.set(itemNo, value);
        }
    }

    return items.map((item) => valueByItemNo.get(item.item) ?? null);
}
