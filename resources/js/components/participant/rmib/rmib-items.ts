import type { GenericItemsOutcome } from '../session-runner/http-transport.ts';

/**
 * Pure types + wire-envelope helpers for the RMIB item content — no React,
 * no fetch, testable via `node --test`. Mirrors
 * `resources/js/components/participant/papi/papi-items.ts`'s shape (this
 * instrument's own version of the same `GET /sessions/:id/items` pattern).
 *
 * Verified against `RmibItemContentReader.php`
 * (`origin/f2/item-delivery-rmib-reader`, PR #76, not yet merged but the
 * shape is final — commit `b9004d6`), not guessed:
 * - One subtest, `code: 'POSITIONS'`, 108 `{group, group_letter, position,
 *   job}` entries.
 * - `job` is already the single, gender-locked label the server chose —
 *   the client never sees `job_male`/`job_female` and never picks a
 *   track.
 * - Source order is fixed and group-major (group 1 positions 1-12, group
 *   2 positions 1-12, ...) — the reader itself re-derives and asserts
 *   `group`/`position` from each entry's array offset as a structural
 *   proof of exact, unshuffled order, so this module trusts array order
 *   completely and never sorts it.
 * - There is no `item_no` field in the wire response. The scoring side
 *   (`ScoreSealedRmibAnswerSet.php:166-199`) expects a flat sequential
 *   `item_no` 1-108 in that same group-major order, so `rmibItemNo` below
 *   re-derives it the same way the reader derives `group`/`position` —
 *   from position in the (trusted-unshuffled) array, never invented
 *   independently.
 */

export type RmibPosition = {
    group: number;
    group_letter: string;
    position: number;
    job: string;
};

export type RmibInstructions = {
    text: string;
    write_preferred_jobs_prompt: string;
};

export type RmibItemsContent = {
    sessionId: string;
    instrument: string;
    version: string;
    positions: RmibPosition[];
    instructions: RmibInstructions;
};

export type RmibItemsOutcome =
    | { type: 'available'; content: RmibItemsContent }
    | { type: 'not_started' }
    | { type: 'closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'not_found' }
    | { type: 'content_unavailable' }
    | { type: 'network_error' };

export type FetchRmibItems = () => Promise<RmibItemsOutcome>;

/**
 * Unwraps the single `code: 'POSITIONS'` subtest `GET /sessions/:id/items`
 * returns for RMIB into a flat 108-entry position list. Throws (does not
 * silently pick one) if the subtest isn't exactly what's expected —
 * mirrors `papiItemsFromSubtests`'s same fail-loud contract.
 */
export function rmibPositionsFromSubtests(
    subtests: { code: string; items: RmibPosition[] }[],
): RmibPosition[] {
    const positionsSubtests = subtests.filter(
        (subtest) => subtest.code === 'POSITIONS',
    );

    if (positionsSubtests.length !== 1) {
        throw new RangeError(
            `rmibPositionsFromSubtests: expected exactly one POSITIONS subtest, got ${positionsSubtests.length}`,
        );
    }

    return positionsSubtests[0]!.items;
}

/**
 * Maps `http-transport.ts`'s generic `GET /sessions/:id/items` outcome
 * into RMIB's own `RmibItemsOutcome` — mirrors
 * `papi-items.ts`'s `papiItemsOutcomeFromGeneric` exactly (same reasoning:
 * every non-`available` member is identical between the two unions by
 * construction, so only `available` needs unwrapping via
 * `rmibPositionsFromSubtests` and the `instructions` cast).
 */
export function rmibItemsOutcomeFromGeneric(
    outcome: GenericItemsOutcome,
): RmibItemsOutcome {
    if (outcome.type !== 'available') {
        return outcome;
    }

    return {
        type: 'available',
        content: {
            sessionId: outcome.content.sessionId,
            instrument: outcome.content.instrument,
            version: outcome.content.version,
            positions: rmibPositionsFromSubtests(
                outcome.content.subtests as {
                    code: string;
                    items: RmibPosition[];
                }[],
            ),
            instructions: outcome.content.instructions as RmibInstructions,
        },
    };
}

export const RMIB_GROUP_COUNT = 9;
export const RMIB_POSITIONS_PER_GROUP = 12;

/**
 * The sequential 1-108 item number `ScoreSealedRmibAnswerSet.php` expects
 * for a given (group, position) pair — see this module's doc.
 */
export function rmibItemNo(group: number, position: number): number {
    return (group - 1) * RMIB_POSITIONS_PER_GROUP + position;
}

/** The inverse of `rmibItemNo`: which (group, position) a flat 1-108
 * item number refers to. Used to map a resumed session's flat answers
 * list back onto per-group state. */
export function rmibGroupAndPositionFromItemNo(itemNo: number): {
    group: number;
    position: number;
} {
    if (
        !Number.isInteger(itemNo) ||
        itemNo < 1 ||
        itemNo > RMIB_GROUP_COUNT * RMIB_POSITIONS_PER_GROUP
    ) {
        throw new RangeError(
            `rmibGroupAndPositionFromItemNo: itemNo ${itemNo} is out of range`,
        );
    }

    const zeroBased = itemNo - 1;

    return {
        group: Math.floor(zeroBased / RMIB_POSITIONS_PER_GROUP) + 1,
        position: (zeroBased % RMIB_POSITIONS_PER_GROUP) + 1,
    };
}

/**
 * Splits the flat 108-entry position list into 9 groups of 12, in source
 * (group-major) order — never re-sorted, per this module's doc. Throws if
 * the list isn't exactly 108 entries or a group doesn't have exactly 12,
 * rather than silently producing a short/uneven group.
 */
export function groupRmibPositions(
    positions: RmibPosition[],
): RmibPosition[][] {
    if (positions.length !== RMIB_GROUP_COUNT * RMIB_POSITIONS_PER_GROUP) {
        throw new RangeError(
            `groupRmibPositions: expected ${RMIB_GROUP_COUNT * RMIB_POSITIONS_PER_GROUP} positions, got ${positions.length}`,
        );
    }

    const groups: RmibPosition[][] = [];

    for (let groupIndex = 0; groupIndex < RMIB_GROUP_COUNT; groupIndex++) {
        const group = positions.slice(
            groupIndex * RMIB_POSITIONS_PER_GROUP,
            (groupIndex + 1) * RMIB_POSITIONS_PER_GROUP,
        );

        if (group.length !== RMIB_POSITIONS_PER_GROUP) {
            throw new RangeError(
                `groupRmibPositions: group ${groupIndex + 1} has ${group.length} positions, expected ${RMIB_POSITIONS_PER_GROUP}`,
            );
        }

        groups.push(group);
    }

    return groups;
}
