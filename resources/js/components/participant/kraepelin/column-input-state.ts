/**
 * Pure cursor/value state for a single Kraepelin column's answer slots —
 * no React, no DOM, testable via `node --test`, same split as
 * session-runner/*-engine.ts modules. A column has
 * `numbers_per_column` numbers (28, per SessionDefinition.php's
 * canonical shape) and `answer_slots_per_column` sum slots (27) between
 * each adjacent pair; the participant sums bottom-to-top and writes the
 * last digit of each sum (SPEC.md §4.3). This module only tracks which
 * slot is focused and what single-digit value (if any) each slot holds —
 * it has no idea about columns, timers, submission, or the real grid
 * data (all of that is F2-dependent and deliberately not built here yet;
 * see the Kraepelin runner plan sent to Lead 2026-09-21).
 *
 * Index 0 is the first slot the participant fills (the bottom-most one,
 * per the bottom-to-top rule) — kraepelin-column.tsx is responsible for
 * flipping this into top-to-bottom screen order; this module only ever
 * advances forward through indices 0..N-1.
 */

export type ColumnInputState = {
    /** One entry per answer slot; `null` means empty. */
    values: (string | null)[];
    /** Index of the currently focused slot. */
    focusedIndex: number;
};

export function createColumnInputState(slotCount: number): ColumnInputState {
    if (slotCount <= 0) {
        throw new RangeError(
            'createColumnInputState: slotCount must be positive',
        );
    }

    return {
        values: new Array<string | null>(slotCount).fill(null),
        focusedIndex: 0,
    };
}

const SINGLE_DIGIT = /^[0-9]$/;

/**
 * Enters a single digit at the focused slot and auto-advances to the
 * next slot (clamped at the last slot — does not wrap around).
 */
export function enterDigit(
    state: ColumnInputState,
    digit: string,
): ColumnInputState {
    if (!SINGLE_DIGIT.test(digit)) {
        throw new RangeError(
            `enterDigit: expected a single digit 0-9, got ${JSON.stringify(digit)}`,
        );
    }

    const values = state.values.slice();
    values[state.focusedIndex] = digit;

    return {
        values,
        focusedIndex: Math.min(state.focusedIndex + 1, state.values.length - 1),
    };
}

/**
 * The conventional multi-slot "backspace": clears the focused slot if it
 * currently holds a value; otherwise moves back one slot and clears
 * that one instead. A no-op at slot 0 with nothing to clear.
 */
export function backspace(state: ColumnInputState): ColumnInputState {
    if (state.values[state.focusedIndex] !== null) {
        const values = state.values.slice();
        values[state.focusedIndex] = null;

        return { values, focusedIndex: state.focusedIndex };
    }

    if (state.focusedIndex === 0) {
        return state;
    }

    const values = state.values.slice();
    const previousIndex = state.focusedIndex - 1;
    values[previousIndex] = null;

    return { values, focusedIndex: previousIndex };
}

/**
 * Moves focus to any slot directly, without altering any values — the
 * correction affordance: tap any slot (filled or empty) to fix a
 * mistyped digit, then type over it.
 */
export function focusSlot(
    state: ColumnInputState,
    index: number,
): ColumnInputState {
    if (index < 0 || index >= state.values.length) {
        throw new RangeError(`focusSlot: index ${index} out of range`);
    }

    if (state.focusedIndex === index) {
        return state;
    }

    return { ...state, focusedIndex: index };
}

export function isComplete(state: ColumnInputState): boolean {
    return state.values.every((value) => value !== null);
}
