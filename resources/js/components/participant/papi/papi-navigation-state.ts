/**
 * Pure navigation/answer state for the PAPI runner — no React, no fetch,
 * testable via `node --test`, same split as the session-runner and
 * Kraepelin pure modules. Tracks which item is currently shown and each
 * item's forced-choice answer; has no idea about fetching items,
 * autosave, or submission — those are the caller's job (this page reuses
 * session-runner's autosave-engine.ts/use-autosave.ts and
 * resume-answers.ts as-is; see the PAPI runner plan sent to Lead
 * 2026-09-21).
 *
 * **Answer value contract (verified against the real scoring code, not
 * guessed):** each item's answer is exactly the string `'a'` or `'b'` —
 * `app/Services/Scoring/PapiRawScoreCalculator.php:123` rejects anything
 * else (`in_array($response['choice'], ['a', 'b'], true)`), and
 * `app/Services/AssessmentResults/ScoreSealedPapiAnswerSet.php:171,174`
 * passes `answers[i].value` straight through as that `choice`. Sending
 * `0`/`1`, a boolean, or the statement text instead of `'a'`/`'b'` would
 * make every PAPI answer score wrong with no error anywhere.
 */

export type PapiChoice = 'a' | 'b';

export type PapiNavigationState = {
    /** One entry per item; `null` means not yet answered. */
    answers: (PapiChoice | null)[];
    /** Index of the item currently shown. */
    currentIndex: number;
};

export function createPapiNavigationState(
    itemCount: number,
    initialAnswers?: (PapiChoice | null)[],
): PapiNavigationState {
    if (itemCount <= 0) {
        throw new RangeError(
            'createPapiNavigationState: itemCount must be positive',
        );
    }

    if (initialAnswers !== undefined && initialAnswers.length !== itemCount) {
        throw new RangeError(
            'createPapiNavigationState: initialAnswers length must equal itemCount',
        );
    }

    return {
        answers: initialAnswers
            ? initialAnswers.slice()
            : new Array<PapiChoice | null>(itemCount).fill(null),
        currentIndex: 0,
    };
}

/**
 * Builds the `answers` seed array for `createPapiNavigationState` from a
 * resumed `GET /sessions/:id/answers` outcome (resume-answers.ts's
 * `ResumedAnswer` list) — used on mount so a returning participant sees
 * their already-autosaved choices instead of a blank runner.
 *
 * `itemNo - 1` maps straight to the answers array index: `papi_items.json`'s
 * `item` field is structurally guaranteed to equal its own array offset + 1
 * (`PapiItemContentReader.php`'s `$item['item'] !== $offset + 1` check), so
 * item order and item number always agree.
 *
 * A malformed entry (out-of-range itemNo, or a value that isn't exactly
 * `'a'`/`'b'`) is dropped rather than thrown — resumed answers already
 * passed `AutosaveAssessmentAnswersRequest`'s validation when originally
 * saved, so treating a genuinely unexpected shape as "not yet answered" is
 * safer than crashing the whole runner on stale/foreign data.
 */
export function papiAnswersFromResumedAnswers(
    itemCount: number,
    resumedAnswers: { itemNo: number; value: unknown }[],
): (PapiChoice | null)[] {
    const answers = new Array<PapiChoice | null>(itemCount).fill(null);

    for (const { itemNo, value } of resumedAnswers) {
        if (
            !Number.isInteger(itemNo) ||
            itemNo < 1 ||
            itemNo > itemCount ||
            (value !== 'a' && value !== 'b')
        ) {
            continue;
        }

        answers[itemNo - 1] = value;
    }

    return answers;
}

/** Records `choice` for the currently-shown item. Does not move the
 * cursor — navigation is a separate, explicit action. */
export function selectChoice(
    state: PapiNavigationState,
    choice: PapiChoice,
): PapiNavigationState {
    const answers = state.answers.slice();
    answers[state.currentIndex] = choice;

    return { ...state, answers };
}

/** Moves directly to any item, without altering any answers — used for
 * both forward/back navigation and the summary screen's "jump to this
 * unanswered item" links. */
export function goToItem(
    state: PapiNavigationState,
    index: number,
): PapiNavigationState {
    if (index < 0 || index >= state.answers.length) {
        throw new RangeError(`goToItem: index ${index} out of range`);
    }

    if (state.currentIndex === index) {
        return state;
    }

    return { ...state, currentIndex: index };
}

/** Moves to the next item, clamped at the last one (does not wrap). */
export function next(state: PapiNavigationState): PapiNavigationState {
    return goToItem(
        state,
        Math.min(state.currentIndex + 1, state.answers.length - 1),
    );
}

/** Moves to the previous item, clamped at the first one (does not wrap). */
export function previous(state: PapiNavigationState): PapiNavigationState {
    return goToItem(state, Math.max(state.currentIndex - 1, 0));
}

/** Indices (0-based) of every item still unanswered, in ascending order —
 * what the pre-submit summary screen lists as jump links. */
export function unansweredIndices(state: PapiNavigationState): number[] {
    const indices: number[] = [];

    state.answers.forEach((answer, index) => {
        if (answer === null) {
            indices.push(index);
        }
    });

    return indices;
}

/** True only once every item has an answer — the submit-button lock
 * condition (Lead's 2026-09-21 decision: client mirrors this as a UI
 * safeguard; the authoritative check belongs server-side, to be added
 * by F2). */
export function isComplete(state: PapiNavigationState): boolean {
    return state.answers.every((answer) => answer !== null);
}
