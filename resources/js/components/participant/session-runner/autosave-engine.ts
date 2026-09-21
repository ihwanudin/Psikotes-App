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
          type: 'accepted';
          revision: number;
          receivedAt: string;
          acceptedItemNos: number[];
      }
    | { type: 'stale_revision' }
    | { type: 'revision_gap' }
    | { type: 'payload_mismatch' }
    | { type: 'session_closed' }
    | { type: 'deadline_exceeded' }
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
          receivedAt: string;
          acceptedItemNos: number[];
      }
    | { status: 'stale_revision' }
    | { status: 'revision_gap' }
    | { status: 'payload_mismatch' }
    | { status: 'session_closed' }
    | { status: 'deadline_exceeded' }
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
     * (see module doc) — the caller must not let the participant keep
     * editing blind against a known-desynced revision. */
    queueChange: (itemNo: number, value: unknown) => void;
    hasPendingChanges: () => boolean;
    isFlushing: () => boolean;
    needsReload: () => boolean;
    /** Sends the current pending batch, or retries the last batch that
     * failed with a network error. No-ops (`skipped`) when there is
     * nothing to send and no retry pending. */
    flush: () => Promise<FlushResult>;
    getRevision: () => number;
    /** Resets the engine to a freshly reloaded server revision and clears
     * `needsReload`. Does not resend or discard queued local edits made
     * before the reload was needed — those remain pending so nothing the
     * participant typed is silently dropped. */
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

    function queueChange(itemNo: number, value: unknown): void {
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
                    receivedAt: outcome.receivedAt,
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
        flush,
        getRevision: () => currentRevision,
        reload: (revision: number) => {
            currentRevision = revision;
            needsReloadFlag = false;
            retriableBatch = null;
        },
    };
}
