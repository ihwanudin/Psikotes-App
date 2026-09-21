/**
 * Pure autosave state machine for `POST /sessions/:id/answers`
 * (f2-assessment-session-contract.md §Autosave contract,
 * API_CONTRACT.md ~line 95-98). No React, no fetch, no DOM: this module
 * owns mutation_id/revision bookkeeping and interprets server outcomes;
 * the caller (a hook) supplies the actual HTTP transport, debounce
 * timing (2s, per Lead's 2026-09-21 decision), and immediate-flush
 * triggers (item change, pre-submit).
 *
 * Design choices forced by the contract, not arbitrary:
 * - A batch in flight is never mutated. New local edits that arrive while
 *   a batch is in flight are queued separately for the NEXT flush, never
 *   merged into the in-flight mutation_id's payload — merging would risk
 *   MUTATION_PAYLOAD_MISMATCH on a transport retry of that same
 *   mutation_id, since the contract requires "same mutation_id, same
 *   payload" for a safe replay.
 * - A network failure (no definitive server answer) is retried with the
 *   SAME mutation_id/revision/items — the contract requires mutation_id
 *   to be "reused unchanged for every transport retry". A definitive
 *   rejection (stale revision, revision gap, payload mismatch) is never
 *   retried with the same identity; retrying identical content would
 *   just repeat the same rejection.
 * - `needs_reload` blocks further local edits until the caller reloads
 *   session state and calls `reload()`: continuing to layer new answers
 *   on top of a revision the engine knows is wrong would only deepen the
 *   desync.
 */

export type AutosaveItem = { itemNo: number; value: unknown };

export type AutosaveSendOutcome =
    | {
          // No receivedAt/timestamp field here on purpose: the real
          // POST /sessions/:id/answers success response
          // (session_id, status, replayed, answers_revision,
          // accepted_item_numbers) never sends one, and CLAUDE.md
          // forbids the client inventing server-authoritative time.
          // An earlier version of this type required one and a
          // client-side capture time was used to fill it -- Lead's
          // 2026-09-21 review caught that a field named receivedAt
          // holding a device clock reading would eventually be
          // misread as server fact. Removed rather than left optional,
          // since nothing in resources/js/ has ever read it.
          type: 'accepted';
          revision: number;
          acceptedItemNos: number[];
      }
    | { type: 'stale_revision' }
    | { type: 'revision_gap' }
    | { type: 'payload_mismatch' }
    | { type: 'session_closed' }
    | { type: 'deadline_exceeded' }
    /** SESSION_NOT_FOUND (404) / SESSION_NOT_STARTED (409) — Lead's
     * 2026-09-21 HTTP transport review: terminal, same as
     * `session_closed`/`deadline_exceeded` (no requeue, no retry), but
     * ALSO blocks any further `queueChange` the same way `needsReload`
     * does — unlike a revision desync, there is no `reload()` that can
     * recover a session that doesn't exist or was never started, so
     * pretending one is available would be misleading. See `terminal`
     * below. */
    | { type: 'not_started' }
    | { type: 'not_found' }
    /** INVALID_ANSWER_BATCH (422) — a client-side bug (a malformed
     * item_no/value reached the server), not a connectivity problem.
     * Lead's 2026-09-21 instruction: never retry the same payload (an
     * unfixed bug would retry-loop forever and hammer the server), but
     * this is per-BATCH, not terminal for the engine — new edits (a
     * fresh batch) are still accepted normally afterward. */
    | { type: 'invalid_batch' }
    | { type: 'network_error' };

export type AutosaveBatch = {
    mutationId: string;
    revision: number;
    items: AutosaveItem[];
};

export type AutosaveSend = (
    batch: AutosaveBatch,
) => Promise<AutosaveSendOutcome>;

export type FlushResult =
    | { status: 'skipped' }
    | { status: 'in_flight' }
    | { status: 'needs_reload' }
    | {
          status: 'accepted';
          revision: number;
          acceptedItemNos: number[];
      }
    | { status: 'stale_revision' }
    | { status: 'revision_gap' }
    | { status: 'payload_mismatch' }
    | { status: 'session_closed' }
    | { status: 'deadline_exceeded' }
    /** See AutosaveSendOutcome's matching variants for the terminal-vs-
     * per-batch distinction between these three. */
    | { status: 'not_started' }
    | { status: 'not_found' }
    | { status: 'invalid_batch' }
    | { status: 'network_error' };

export type AutosaveEngineOptions = {
    /** The session's answers_revision at the time this engine was created
     * (from the AssessmentSession DTO on start/resume). */
    initialRevision: number;
    send: AutosaveSend;
    /** Injected so callers share one ULID generator instance per session,
     * not one per engine method call. */
    createMutationId: () => string;
};

export type AutosaveEngine = {
    /** Queues a local edit. Throws if the engine currently needs a reload
     * (see module doc), or if it has hit a terminal outcome
     * (`not_found`/`not_started` — see AutosaveSendOutcome's doc): in
     * both cases the caller must not let the participant keep editing
     * blind against a session the engine already knows is unusable. */
    queueChange: (itemNo: number, value: unknown) => void;
    hasPendingChanges: () => boolean;
    isFlushing: () => boolean;
    needsReload: () => boolean;
    /** True once a `not_found`/`not_started` outcome has been seen —
     * unlike `needsReload`, there is no `reload()` call that can recover
     * from this; the caller should show the session's terminal state and
     * stop offering to retry. */
    isTerminal: () => boolean;
    /** Sends the current pending batch, or retries the last batch that
     * failed with a network error. No-ops (`skipped`) when there is
     * nothing to send and no retry pending. Once terminal, short-circuits
     * to the same terminal status every time without contacting the
     * server again. */
    flush: () => Promise<FlushResult>;
    getRevision: () => number;
    /** Resets the engine to a freshly reloaded server revision and clears
     * `needsReload`. Does not resend or discard queued local edits made
     * before the reload was needed — those remain pending so nothing the
     * participant typed is silently dropped. Does not clear a terminal
     * outcome — there is nothing to reload into for those. */
    reload: (revision: number) => void;
};

export function createAutosaveEngine(
    options: AutosaveEngineOptions,
): AutosaveEngine {
    let currentRevision = options.initialRevision;
    const pendingChanges = new Map<number, unknown>();
    let retriableBatch: AutosaveBatch | null = null;
    let inFlight = false;
    let needsReloadFlag = false;
    let terminalStatus: 'not_started' | 'not_found' | null = null;

    function queueChange(itemNo: number, value: unknown): void {
        if (terminalStatus !== null) {
            throw new Error(
                `autosave: engine is terminal (${terminalStatus}) and can no longer accept changes`,
            );
        }

        if (needsReloadFlag) {
            throw new Error(
                'autosave: engine needs reload() before accepting more changes',
            );
        }

        pendingChanges.set(itemNo, value);
    }

    function requeue(items: AutosaveItem[]): void {
        for (const item of items) {
            if (!pendingChanges.has(item.itemNo)) {
                pendingChanges.set(item.itemNo, item.value);
            }
        }
    }

    async function flush(): Promise<FlushResult> {
        if (terminalStatus !== null) {
            return { status: terminalStatus };
        }

        if (needsReloadFlag) {
            return { status: 'needs_reload' };
        }

        if (inFlight) {
            return { status: 'in_flight' };
        }

        let batch: AutosaveBatch;

        if (retriableBatch !== null) {
            batch = retriableBatch;
        } else if (pendingChanges.size > 0) {
            const items: AutosaveItem[] = [...pendingChanges.entries()].map(
                ([itemNo, value]) => ({ itemNo, value }),
            );

            for (const item of items) {
                pendingChanges.delete(item.itemNo);
            }

            batch = {
                mutationId: options.createMutationId(),
                revision: currentRevision + 1,
                items,
            };
        } else {
            return { status: 'skipped' };
        }

        inFlight = true;
        let outcome: AutosaveSendOutcome;

        try {
            outcome = await options.send(batch);
        } catch {
            inFlight = false;
            retriableBatch = batch;

            return { status: 'network_error' };
        }

        inFlight = false;

        switch (outcome.type) {
            case 'accepted':
                retriableBatch = null;
                currentRevision = outcome.revision;

                return {
                    status: 'accepted',
                    revision: outcome.revision,
                    acceptedItemNos: outcome.acceptedItemNos,
                };
            case 'stale_revision':
            case 'revision_gap':
                retriableBatch = null;
                needsReloadFlag = true;
                requeue(batch.items);

                return { status: outcome.type };
            case 'payload_mismatch':
                // Should be unreachable for this engine's own retries (see
                // module doc); if the server reports it anyway, treat it
                // the same as a desync rather than guess at a resolution.
                retriableBatch = null;
                needsReloadFlag = true;

                return { status: 'payload_mismatch' };
            case 'session_closed':
                retriableBatch = null;

                return { status: 'session_closed' };
            case 'deadline_exceeded':
                retriableBatch = null;

                return { status: 'deadline_exceeded' };
            case 'not_started':
            case 'not_found':
                // Terminal (Lead's 2026-09-21 instruction): the session
                // doesn't exist or was never started, so there is nothing
                // to requeue against and no reload that could fix it —
                // every later queueChange/flush must keep reporting this
                // same status without touching the server again.
                retriableBatch = null;
                terminalStatus = outcome.type;

                return { status: outcome.type };
            case 'invalid_batch':
                // A client-side bug rejected this exact payload, not a
                // connectivity problem — Lead's 2026-09-21 instruction:
                // never resend the same batch (an unfixed bug would
                // retry-loop forever). Deliberately NOT requeued into
                // pendingChanges, unlike stale_revision/revision_gap
                // above. Not terminal: a genuinely new edit (a fresh
                // queueChange call) is still accepted normally.
                retriableBatch = null;

                return { status: 'invalid_batch' };
            case 'network_error':
                retriableBatch = batch;

                return { status: 'network_error' };
        }
    }

    return {
        queueChange,
        hasPendingChanges: () => pendingChanges.size > 0,
        isFlushing: () => inFlight,
        needsReload: () => needsReloadFlag,
        isTerminal: () => terminalStatus !== null,
        flush,
        getRevision: () => currentRevision,
        reload: (revision: number) => {
            currentRevision = revision;
            needsReloadFlag = false;
            retriableBatch = null;
        },
    };
}
