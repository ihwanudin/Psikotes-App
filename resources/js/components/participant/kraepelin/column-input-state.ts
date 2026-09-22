/**
 * Pure cursor/value state for a single Kraepelin column's answer slots —
 * no React, no DOM, testable via `node --test`, same split as
 * session-runner/*-engine.ts modules. A column has
 * `numbers_per_column` numbers (28, per SessionDefinition.php's
 * canonical shape) and `answer_slots_per_column` sum slots (27) between
 * each adjacent pair; the participant sums bottom-to-top and writes the
 * last digit of each sum (SPEC.md §4.3). This module only tracks which
 * slot is focused, what single-digit value (if any) each slot holds,
 * and which slots are locked — it has no idea about columns, timers,
 * submission, or the real grid data (all of that is F2-dependent and
 * deliberately not built here yet; see the Kraepelin runner plan sent
 * to Lead 2026-09-21).
 *
 * Index 0 is the first slot the participant fills (the bottom-most one,
 * per the bottom-to-top rule) — kraepelin-column.tsx is responsible for
 * flipping this into top-to-bottom screen order; this module only ever
 * advances forward through indices 0..N-1.
 *
 * Locking supports BOTH answers to the still-open psychometric question
 * (can a participant correct a Kraepelin answer?) without this module
 * needing to change once that's decided (Lead's 2026-09-21 review):
 * `lockSlot`/`lockFilledSlots` are plain primitives the CALLER invokes
 * whenever its chosen policy says a slot becomes final — immediately
 * after `enterDigit` for "no correction allowed", or only once a column
 * has actually been flushed to the server for "correction allowed until
 * sent". A slot that has never been locked behaves exactly as before
 * (freely re-focusable/re-enterable), so leaving lock calls out entirely
 * reproduces the original always-correctable behavior.
 */

export type ColumnInputState = {
    /** One entry per answer slot; `null` means empty. */
    values: (string | null)[];
    /** Index of the currently focused slot. */
    focusedIndex: number;
    /** One entry per answer slot; `true` means that slot can no longer
     * be focused, typed into, or cleared. */
    locked: boolean[];
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
        locked: new Array<boolean>(slotCount).fill(false),
    };
}

const SINGLE_DIGIT = /^[0-9]$/;

/**
 * Enters a single digit at the focused slot and auto-advances to the
 * next slot (clamped at the last slot — does not wrap around). A no-op
 * if the focused slot is locked (defensive — under normal flow, focus
 * never lands on a locked slot, since locking a slot only happens after
 * it has already been passed).
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

    if (state.locked[state.focusedIndex]) {
        return state;
    }

    const values = state.values.slice();
    values[state.focusedIndex] = digit;

    return {
        ...state,
        values,
        focusedIndex: Math.min(state.focusedIndex + 1, state.values.length - 1),
    };
}

/**
 * The conventional multi-slot "backspace": clears the focused slot if it
 * currently holds a value; otherwise moves back one slot and clears
 * that one instead. A no-op at slot 0 with nothing to clear, if the
 * focused slot is locked, or if clearing would require reaching back
 * into a locked slot.
 */
export function backspace(state: ColumnInputState): ColumnInputState {
    if (state.locked[state.focusedIndex]) {
        return state;
    }

    if (state.values[state.focusedIndex] !== null) {
        const values = state.values.slice();
        values[state.focusedIndex] = null;

        return { ...state, values };
    }

    if (state.focusedIndex === 0) {
        return state;
    }

    const previousIndex = state.focusedIndex - 1;

    if (state.locked[previousIndex]) {
        return state;
    }

    const values = state.values.slice();
    values[previousIndex] = null;

    return { ...state, values, focusedIndex: previousIndex };
}

/**
 * Moves focus to any unlocked slot directly, without altering any
 * values — the correction affordance: tap any slot (filled or empty) to
 * fix a mistyped digit, then type over it. A no-op if the target slot is
 * locked (the "no correction" policy's enforcement point: a locked slot
 * simply cannot be selected).
 */
export function focusSlot(
    state: ColumnInputState,
    index: number,
): ColumnInputState {
    if (index < 0 || index >= state.values.length) {
        throw new RangeError(`focusSlot: index ${index} out of range`);
    }

    if (state.locked[index]) {
        return state;
    }

    if (state.focusedIndex === index) {
        return state;
    }

    return { ...state, focusedIndex: index };
}

/** Locks a single slot. A no-op if it is already locked. */
export function lockSlot(
    state: ColumnInputState,
    index: number,
): ColumnInputState {
    if (index < 0 || index >= state.values.length) {
        throw new RangeError(`lockSlot: index ${index} out of range`);
    }

    if (state.locked[index]) {
        return state;
    }

    const locked = state.locked.slice();
    locked[index] = true;

    return { ...state, locked };
}

/**
 * Locks every currently-filled slot at once — the "correction allowed
 * until sent" policy's enforcement point: call this once a column's
 * batch has been confirmed flushed to the server (ARCHITECTURE.md's
 * "Kraepelin flush per kolom" locks a whole column together, not one
 * slot at a time).
 */
export function lockFilledSlots(state: ColumnInputState): ColumnInputState {
    let changed = false;
    const locked = state.locked.slice();

    for (let i = 0; i < state.values.length; i++) {
        if (state.values[i] !== null && !locked[i]) {
            locked[i] = true;
            changed = true;
        }
    }

    return changed ? { ...state, locked } : state;
}

export function isComplete(state: ColumnInputState): boolean {
    return state.values.every((value) => value !== null);
}
