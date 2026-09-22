/**
 * `GET /sessions/:id/items` for PAPI (API_CONTRACT.md, PR #71 —
 * `tasks/handoffs/f2/item-delivery-papi-reader.md`; not yet merged to
 * `main` at time of writing, CI still down for the account billing
 * issue, but the shape is final per Lead 2026-09-21). Transport (HTTP
 * call, auth headers, mapping the wire envelope into this shape) is the
 * caller's job, same as `FetchAssessmentSessionItems` in
 * kraepelin/items.ts — this file only defines the shape and the one
 * mapping every caller needs.
 *
 * **Wire envelope** (`PapiItemContentReader.php`,
 * `GetAssessmentSessionItemsController.php`):
 * `{session_id, instrument, version, subtests:[{code:'ITEMS', items:[{item,
 * statement_a, statement_b}] x90}], instructions:{intro, example:
 * {statement_a, statement_b}, answer_sheet_demo:{label, statement_a,
 * statement_b}, closing}}`. PAPI has exactly one subtest (`code: 'ITEMS'`)
 * unlike Kraepelin's 50 (`col_01`..`col_50`) — `papiItemsFromContent()`
 * below unwraps that single subtest so callers work with a flat 90-item
 * array directly, matching `papi-runner.tsx`'s existing `items` prop
 * shape unchanged (no reshaping needed there — confirmed by reading the
 * real reader's output shape against what was already built).
 *
 * `instructions` is part of the base `/items` envelope for every
 * instrument (nullable — Kraepelin's reader doesn't supply any), but
 * PapiItemContentReader always populates it for PAPI, so this type
 * requires it rather than allowing null.
 *
 * Same readable-exactly-when-writable gate as every other `/items`/
 * `/answers` read: `not_started`/`closed`/`deadline_exceeded`/
 * `not_found`, plus `content_unavailable` for the 503
 * `ASSESSMENT_ITEM_CONTENT_UNAVAILABLE` case (no reader registered, a
 * registered reader failed, or — new for this reader, no Kraepelin
 * precedent — the seeded `papi_items.json` isn't `status: final`).
 */

export type PapiItem = {
    item: number;
    statement_a: string;
    statement_b: string;
};

export type PapiStatementPair = {
    statement_a: string;
    statement_b: string;
};

export type PapiInstructions = {
    intro: string;
    example: PapiStatementPair;
    answer_sheet_demo: PapiStatementPair & { label: string };
    closing: string;
};

export type PapiItemsContent = {
    sessionId: string;
    instrument: string;
    version: string;
    items: PapiItem[];
    instructions: PapiInstructions;
};

export type PapiItemsOutcome =
    | { type: 'available'; content: PapiItemsContent }
    | { type: 'not_started' }
    | { type: 'closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'not_found' }
    | { type: 'content_unavailable' }
    | { type: 'network_error' };

export type FetchPapiItems = () => Promise<PapiItemsOutcome>;

/**
 * Unwraps the wire envelope's single `{code: 'ITEMS', items}` subtest
 * into the flat shape `PapiItemsContent`/`papi-runner.tsx` expect.
 * Throws if the envelope doesn't have exactly that one subtest — a
 * shape mismatch here means the contract changed underneath this file,
 * not a data problem worth failing softly on.
 */
export function papiItemsFromSubtests(
    subtests: { code: string; items: PapiItem[] }[],
): PapiItem[] {
    if (subtests.length !== 1 || subtests[0]!.code !== 'ITEMS') {
        throw new RangeError(
            `papiItemsFromSubtests: expected exactly one subtest coded 'ITEMS', got ${JSON.stringify(subtests.map((s) => s.code))}`,
        );
    }

    return subtests[0]!.items;
}
