import { useState } from 'react';

import { PapiInstructionsScreen } from './papi-instructions-screen.tsx';
import type { PapiChoice } from './papi-navigation-state.ts';
import { PapiRunner } from './papi-runner.tsx';
import { usePapiItems } from './use-papi-items.ts';
import type { UsePapiItemsOptions } from './use-papi-items.ts';

/**
 * Wires the PAPI prototype (papi-runner.tsx and everything it composes)
 * to the real `GET /sessions/:id/items` PAPI reader (PR #71). Mirrors
 * kraepelin/kraepelin-column-runner.tsx's composition shape: fetch via
 * usePapiItems, handle loading/reconnecting/final states, then hand the
 * real data to the existing presentational runner unchanged —
 * `PapiRunner`'s `items` prop already matched the real reader's
 * `{item, statement_a, statement_b}` shape exactly, so no reshaping was
 * needed there.
 *
 * `POST /sessions/:id/submit` and the answer-completeness check it will
 * eventually gate on are still not wired here — `onSubmit` stays the
 * caller's honest stub, same as before this file existed.
 */

export type PapiItemsRunnerProps = UsePapiItemsOptions & {
    onAnswerChange?: (itemNo: number, value: PapiChoice) => void;
    onSubmit: () => void;
    submitting?: boolean;
};

const FINAL_MESSAGE: Record<string, string> = {
    not_started: 'Sesi belum dimulai.',
    closed: 'Sesi sudah ditutup.',
    deadline_exceeded: 'Waktu pengerjaan sudah habis.',
    not_found: 'Sesi tidak ditemukan.',
    content_unavailable: 'Isi soal belum tersedia. Coba lagi nanti.',
};

export function PapiItemsRunner({
    fetchItems,
    queueRetry,
    onAnswerChange,
    onSubmit,
    submitting,
}: PapiItemsRunnerProps) {
    const { state, retry } = usePapiItems({ fetchItems, queueRetry });
    const [showInstructions, setShowInstructions] = useState(true);

    if (state.status === 'loading') {
        return (
            <p role="status" aria-busy="true">
                Memuat soal…
            </p>
        );
    }

    if (state.status === 'reconnecting') {
        return (
            <div role="alert">
                <p>Tidak dapat terhubung. Coba lagi.</p>
                <button type="button" onClick={retry}>
                    Coba lagi
                </button>
            </div>
        );
    }

    const { outcome } = state;

    if (outcome.type !== 'available') {
        return <p role="alert">{FINAL_MESSAGE[outcome.type]}</p>;
    }

    if (showInstructions) {
        return (
            <PapiInstructionsScreen
                instructions={outcome.content.instructions}
                onStart={() => setShowInstructions(false)}
            />
        );
    }

    return (
        <PapiRunner
            items={outcome.content.items}
            onAnswerChange={onAnswerChange}
            onSubmit={onSubmit}
            submitting={submitting}
        />
    );
}
