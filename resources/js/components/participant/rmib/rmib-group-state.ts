import {
    RMIB_GROUP_COUNT,
    RMIB_POSITIONS_PER_GROUP,
    rmibGroupAndPositionFromItemNo,
} from './rmib-items.ts';

/**
 * Pure state for one RMIB group's 12-item ranking — no React, no DOM,
 * testable via `node --test`. Mirrors the pure-state/thin-hook split
 * `papi-navigation-state.ts` uses.
 *
 * `order` holds the group's 12 position numbers (1-12, matching
 * `RmibPosition.position`) IN CURRENT RANK ORDER: `order[0]` is rank 1
 * (most preferred, per RMIB's standard direction), `order[11]` is rank
 * 12. This is a deliberate design choice (Lead approved, 2026-09-21
 * plan): representing rank as array position, rather than a separate
 * position->rank map, makes "every rank 1-12 used exactly once" true BY
 * CONSTRUCTION — there is no reachable state where a rank is skipped or
 * duplicated, so nothing needs to validate that invariant on the client.
 * `rankOf(state, position)` derives a position's rank as
 * `order.indexOf(position) + 1` whenever needed.
 *
 * This matters because the server enforces exactly that invariant, and
 * fails the ENTIRE RMIB result (not just one group) if it's ever violated
 * — see `RmibRawScoreCalculator.php:151-153,170-172`, cited in the RMIB
 * runner plan sent to Lead 2026-09-21.
 *
 * `confirmed` is the group's answered flag. Lead's 2026-09-21 correction
 * to the original plan: marking a group "answered" only from reordering
 * would trap a participant who genuinely wants to KEEP the shown order —
 * they'd have to shuffle and undo just to unlock submit, which changes
 * their behavior to satisfy the UI. So `confirmed` becomes true from
 * EITHER (a) any reorder action (`moveUp`/`moveDown`/`reorder` all set
 * it), or (b) the explicit "Simpan urutan ini" action
 * (`confirmCurrentOrder`, which changes nothing about `order`). It is
 * NEVER true by default — `createRmibGroupState`'s initial state and
 * `rmibGroupStateFromRanks` reconstructing an incomplete/absent resume
 * both start `confirmed: false`. A group's answered status always comes
 * from a participant action, never a default.
 */

export type RmibGroupState = {
    order: number[];
    confirmed: boolean;
};

/** A fresh, unconfirmed group: positions 1-12 in that document order. */
export function createRmibGroupState(): RmibGroupState {
    return {
        order: Array.from(
            { length: RMIB_POSITIONS_PER_GROUP },
            (_, index) => index + 1,
        ),
        confirmed: false,
    };
}

/**
 * Reconstructs a group's state from a resumed session's per-position
 * ranks (position -> rank, both 1-12) — used on mount so a returning
 * participant sees their already-autosaved order instead of a blank
 * group. `confirmed: true`, since a full valid set of 12 resumed ranks
 * can only exist if the participant already confirmed this group before
 * (autosave never sends a group's answers until `confirmed` was true
 * client-side — see rmib-runner.tsx).
 *
 * Throws if `ranksByPosition` isn't exactly a permutation of positions
 * 1-12 to ranks 1-12 — a partial or corrupt resumed group is a bug
 * elsewhere (the server itself only accepts a full valid group at
 * scoring time), not something to silently patch over here.
 */
export function rmibGroupStateFromRanks(
    ranksByPosition: Map<number, number>,
): RmibGroupState {
    if (ranksByPosition.size !== RMIB_POSITIONS_PER_GROUP) {
        throw new RangeError(
            `rmibGroupStateFromRanks: expected ${RMIB_POSITIONS_PER_GROUP} entries, got ${ranksByPosition.size}`,
        );
    }

    const order = new Array<number>(RMIB_POSITIONS_PER_GROUP);
    const seenRanks = new Set<number>();

    for (const [position, rank] of ranksByPosition) {
        if (
            !Number.isInteger(position) ||
            position < 1 ||
            position > RMIB_POSITIONS_PER_GROUP ||
            !Number.isInteger(rank) ||
            rank < 1 ||
            rank > RMIB_POSITIONS_PER_GROUP ||
            seenRanks.has(rank)
        ) {
            throw new RangeError(
                `rmibGroupStateFromRanks: invalid or duplicated rank for position ${position}`,
            );
        }

        seenRanks.add(rank);
        order[rank - 1] = position;
    }

    return { order, confirmed: true };
}

/** The rank (1-12) currently assigned to `position`. */
export function rankOf(state: RmibGroupState, position: number): number {
    const index = state.order.indexOf(position);

    if (index === -1) {
        throw new RangeError(
            `rankOf: position ${position} is not in this group`,
        );
    }

    return index + 1;
}

/** Moves `position` one rank better (toward rank 1). No-op if already
 * rank 1. Marks the group confirmed — see this module's doc. */
export function moveUp(
    state: RmibGroupState,
    position: number,
): RmibGroupState {
    const index = state.order.indexOf(position);

    if (index <= 0) {
        return { ...state, confirmed: true };
    }

    const order = state.order.slice();
    [order[index - 1], order[index]] = [order[index]!, order[index - 1]!];

    return { order, confirmed: true };
}

/** Moves `position` one rank worse (toward rank 12). No-op if already
 * rank 12. Marks the group confirmed — see this module's doc. */
export function moveDown(
    state: RmibGroupState,
    position: number,
): RmibGroupState {
    const index = state.order.indexOf(position);

    if (index === -1 || index >= state.order.length - 1) {
        return { ...state, confirmed: true };
    }

    const order = state.order.slice();
    [order[index], order[index + 1]] = [order[index + 1]!, order[index]!];

    return { order, confirmed: true };
}

/** Moves the item at `fromIndex` to `toIndex` (both 0-based rank
 * positions), shifting everything between them — the drag-and-drop
 * result shape `@dnd-kit/sortable`'s `arrayMove` already produces, kept
 * as a thin pure wrapper here so the group-list component never touches
 * `order` directly. Marks the group confirmed — see this module's doc. */
export function reorder(
    state: RmibGroupState,
    fromIndex: number,
    toIndex: number,
): RmibGroupState {
    if (
        fromIndex < 0 ||
        fromIndex >= state.order.length ||
        toIndex < 0 ||
        toIndex >= state.order.length
    ) {
        throw new RangeError('reorder: fromIndex/toIndex out of range');
    }

    if (fromIndex === toIndex) {
        return { ...state, confirmed: true };
    }

    const order = state.order.slice();
    const [moved] = order.splice(fromIndex, 1);
    order.splice(toIndex, 0, moved!);

    return { order, confirmed: true };
}

/** The explicit "Simpan urutan ini" action: confirms the group as
 * answered WITHOUT changing `order` — Lead's 2026-09-21 correction, see
 * this module's doc for why this exists alongside reorder-triggered
 * confirmation. */
export function confirmCurrentOrder(state: RmibGroupState): RmibGroupState {
    return { ...state, confirmed: true };
}

/** A group counts as answered once `confirmed` is true — `order` is
 * always a valid 1-12 permutation by construction (see this module's
 * doc), so there is no separate "is this order valid" check needed. */
export function isGroupComplete(state: RmibGroupState): boolean {
    return state.confirmed;
}

/** A valid RMIB rank string, per `ScoreSealedRmibAnswerSet.php:187`'s
 * exact regex: "1".."12", no leading zero. */
const RANK_VALUE_PATTERN = /^(?:[1-9]|1[0-2])$/;

/**
 * Builds all 9 groups' state from a resumed session's flat 1-108 answers
 * list (`resume-answers.ts`'s `ResumedAnswer[]`) — used on mount so a
 * returning participant sees their already-autosaved rankings instead of
 * 9 blank groups.
 *
 * A group is only reconstructed (`confirmed: true`) when its resumed
 * answers form a complete, valid 12-rank permutation — autosave itself
 * doesn't enforce per-group uniqueness (only `RmibRawScoreCalculator`
 * does, at scoring time, per the RMIB runner plan), so a stored-but-
 * technically-invalid group is reachable in principle. Any group that
 * isn't a full valid permutation (including simply "fewer than 12
 * answers were ever autosaved for it") falls back to
 * `createRmibGroupState()` — unconfirmed, document order — the same
 * "drop malformed data rather than crash" choice
 * `papiAnswersFromResumedAnswers` makes.
 */
export function rmibGroupStatesFromResumedAnswers(
    resumedAnswers: { itemNo: number; value: unknown }[],
): RmibGroupState[] {
    const ranksByGroup: Map<number, number>[] = Array.from(
        { length: RMIB_GROUP_COUNT },
        () => new Map<number, number>(),
    );

    for (const { itemNo, value } of resumedAnswers) {
        if (typeof value !== 'string' || !RANK_VALUE_PATTERN.test(value)) {
            continue;
        }

        let groupAndPosition: { group: number; position: number };

        try {
            groupAndPosition = rmibGroupAndPositionFromItemNo(itemNo);
        } catch {
            continue;
        }

        ranksByGroup[groupAndPosition.group - 1]!.set(
            groupAndPosition.position,
            Number(value),
        );
    }

    return ranksByGroup.map((ranks) => {
        if (ranks.size !== RMIB_POSITIONS_PER_GROUP) {
            return createRmibGroupState();
        }

        try {
            return rmibGroupStateFromRanks(ranks);
        } catch {
            return createRmibGroupState();
        }
    });
}
