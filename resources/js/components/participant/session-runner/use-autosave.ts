import { useCallback, useEffect, useRef, useState } from 'react';

import { createAutosaveEngine } from './autosave-engine.ts';
import type { AutosaveSend, FlushResult } from './autosave-engine.ts';
import { createUlidGenerator } from './ulid.ts';

/**
 * Debounce window after a local answer edit before autosave sends it
 * (Lead's 2026-09-21 decision). This module owns ONLY the timing policy
 * (when to call flush) — the mutation_id/revision state machine lives in
 * autosave-engine.ts, with no React dependency, per the same decision.
 *
 * This hook deliberately does NOT flush on every queueChange(): the
 * caller (a per-instrument page, not built yet) is responsible for
 * calling `flush()` directly when the participant moves to a different
 * item and before submit (ARCHITECTURE.md's "upsert per item saat
 * berpindah soal" — debounce alone would let a fast navigator leave an
 * answer unsent). The shell does not know what "moving to a different
 * item" means for any given instrument, so it cannot decide this itself.
 */
const DEBOUNCE_MS = 2000;

export type UseAutosaveOptions = {
    /** The session's answers_revision at start/resume. */
    initialRevision: number;
    send: AutosaveSend;
};

export type UseAutosaveResult = {
    /** Queues a local edit and (re)starts the debounce timer. Throws if
     * needsReload or isTerminal is true — check both before calling. */
    queueChange: (itemNo: number, value: unknown) => void;
    /** Sends immediately, bypassing any pending debounce timer. Call this
     * on item navigation and before submit. */
    flush: () => Promise<FlushResult>;
    /** Resets the engine to a freshly reloaded server revision after the
     * caller has re-fetched session state (GET /sessions/:id) in response
     * to needsReload. */
    reload: (revision: number) => void;
    isFlushing: boolean;
    hasPendingChanges: boolean;
    needsReload: boolean;
    /** True once a not_found/not_started outcome has been seen — see
     * autosave-engine.ts's AutosaveEngine.isTerminal doc. Unlike
     * needsReload, there is no reload() call that clears this. */
    isTerminal: boolean;
    lastResult: FlushResult | null;
    getRevision: () => number;
};

export function useAutosave({
    initialRevision,
    send,
}: UseAutosaveOptions): UseAutosaveResult {
    // `send` is captured once, at mount — deliberately not kept "latest"
    // via a ref: the project's react-hooks/refs rule (React Compiler)
    // forbids feeding anything ref-derived into a function call that
    // runs during render (which a useState lazy initializer does, even
    // though only once), and there is no other way to give the engine a
    // persistently-updatable send callback without one. In practice this
    // is not a real limitation: `send` closes over the session's id/
    // token, neither of which changes during a single test-taking
    // session, so the caller does not need it to track later renders —
    // same "captured once" contract `useState(initialValue)` itself
    // documents for its own argument.
    const [engine] = useState(() =>
        createAutosaveEngine({
            initialRevision,
            send,
            createMutationId: createUlidGenerator(),
        }),
    );

    const [isFlushing, setIsFlushing] = useState(false);
    const [hasPendingChanges, setHasPendingChanges] = useState(false);
    const [needsReload, setNeedsReload] = useState(false);
    const [isTerminal, setIsTerminal] = useState(false);
    const [lastResult, setLastResult] = useState<FlushResult | null>(null);
    const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const syncState = useCallback(() => {
        setIsFlushing(engine.isFlushing());
        setHasPendingChanges(engine.hasPendingChanges());
        setNeedsReload(engine.needsReload());
        setIsTerminal(engine.isTerminal());
    }, [engine]);

    const clearDebounce = useCallback(() => {
        if (debounceTimer.current !== null) {
            clearTimeout(debounceTimer.current);
            debounceTimer.current = null;
        }
    }, []);

    const flush = useCallback(async (): Promise<FlushResult> => {
        clearDebounce();
        syncState();
        const result = await engine.flush();
        setLastResult(result);
        syncState();

        return result;
    }, [clearDebounce, engine, syncState]);

    const queueChange = useCallback(
        (itemNo: number, value: unknown): void => {
            engine.queueChange(itemNo, value);
            syncState();
            clearDebounce();
            debounceTimer.current = setTimeout(() => {
                debounceTimer.current = null;
                void flush();
            }, DEBOUNCE_MS);
        },
        [clearDebounce, engine, flush, syncState],
    );

    const reload = useCallback(
        (revision: number): void => {
            engine.reload(revision);
            syncState();
        },
        [engine, syncState],
    );

    useEffect(() => clearDebounce, [clearDebounce]);

    return {
        queueChange,
        flush,
        reload,
        isFlushing,
        hasPendingChanges,
        needsReload,
        isTerminal,
        lastResult,
        getRevision: () => engine.getRevision(),
    };
}
