/**
 * `GET /sessions/:id/items` (API_CONTRACT.md, PR #67,
 * tasks/handoffs/f2/item-delivery-items-endpoint.md) — delivers a
 * Kraepelin column's stimulus numbers. Transport (HTTP call, auth
 * headers) is the caller's job, same as `FetchSession`/`FetchResumeAnswers`
 * — this file only defines the shape and the one mapping every caller
 * needs, no fetch/DOM.
 *
 * **Order contract (do not "fix" this — read
 * item-delivery-items-endpoint.md first):** the server already reverses
 * each Kraepelin column into administration order before it reaches the
 * client — `position=1` is the bottom-most number on the printed sheet
 * (the first one the participant works from), `position=28` is the
 * top-most. `columnNumbersFromItems` below copies `items` in position
 * order with NO reversal of its own. Reversing here on top of the
 * server's reversal silently pairs every participant's answer with the
 * wrong pair of numbers — see grid-column.test.ts's mandatory test,
 * which fails under either a double reversal or a missing one.
 * `grid-column.ts`'s `columnNumbersFromGrid()` (which DOES reverse) is
 * for the raw, sheet-order `kraepelin_grid.json` — never call it on an
 * `/items` response.
 *
 * The endpoint is readable "exactly when writable", same gate as
 * `/sessions/:id/answers`: `created` -> `not_started`;
 * `submitted`/`scored`/`expired`/`void` -> `closed`; `in_progress` in
 * storage but past `ends_at` -> `deadline_exceeded`; nonexistent/
 * foreign-owned session -> `not_found`. `content_unavailable` is new
 * here (503 `ASSESSMENT_ITEM_CONTENT_UNAVAILABLE`): no reader registered
 * for the instrument, or a registered reader couldn't produce content —
 * fails closed rather than serving something stale or guessed.
 */

export type KraepelinItem = { position: number; value: number };

export type KraepelinSubtest = { code: string; items: KraepelinItem[] };

export type AssessmentSessionItems = {
    sessionId: string;
    instrument: string;
    version: string;
    subtests: KraepelinSubtest[];
};

export type AssessmentSessionItemsOutcome =
    | { type: 'available'; content: AssessmentSessionItems }
    | { type: 'not_started' }
    | { type: 'closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'not_found' }
    | { type: 'content_unavailable' }
    | { type: 'network_error' };

export type FetchAssessmentSessionItems =
    () => Promise<AssessmentSessionItemsOutcome>;

/**
 * `items` must be exactly 28 entries forming a contiguous 1..28
 * `position` sequence (order in the array is NOT trusted — sorted here
 * defensively). Returns the values in position order, unmodified:
 * `numbers[0]` is position 1 (bottom-most on the sheet, first worked),
 * `numbers[27]` is position 28 (top-most, last worked) — exactly the
 * order `kraepelin-column.tsx`'s `numbers` prop expects.
 */
export function columnNumbersFromItems(items: KraepelinItem[]): number[] {
    if (items.length !== 28) {
        throw new RangeError(
            `columnNumbersFromItems: expected 28 items, got ${items.length}`,
        );
    }

    const sorted = [...items].sort((a, b) => a.position - b.position);

    sorted.forEach((item, index) => {
        if (item.position !== index + 1) {
            throw new RangeError(
                'columnNumbersFromItems: items do not form a contiguous 1..28 position sequence',
            );
        }
    });

    return sorted.map((item) => item.value);
}
