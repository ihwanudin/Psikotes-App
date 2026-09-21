import { useState } from 'react';

import { createColumnInputState } from './column-input-state.ts';
import type { ColumnInputState } from './column-input-state.ts';
import { columnNumbersFromItems } from './items.ts';
import type { KraepelinSubtest } from './items.ts';
import { KraepelinColumn } from './kraepelin-column.tsx';
import { KraepelinKeypad } from './kraepelin-keypad.tsx';
import { useItems } from './use-items.ts';
import type { UseItemsOptions } from './use-items.ts';

/**
 * Displays one Kraepelin column fetched from `GET /sessions/:id/items`,
 * with its keypad and local answer-slot state — the scope Lead approved
 * for this increment (2026-09-21), deliberately narrower than a full
 * runner page:
 *
 * - **No answer submission.** `POST /sessions/:id/events` doesn't exist
 *   yet (its design is approved but implementation waits on the
 *   psychologist's correction-policy decision — see the Kraepelin runner
 *   plan). Typed digits update local state, visibly, but nothing is ever
 *   sent anywhere. This is an honest stub, not a hidden limitation.
 * - **No client-computed "current column".** `columnIndex` is an
 *   explicit prop, not derived from elapsed time or any local timer —
 *   CLAUDE.md forbids frontend timer/column-selection logic, and the
 *   server will supply `current_column`/`column_remaining_seconds` once
 *   `/events` and its accompanying session fields exist. Until then, the
 *   caller (not built yet) decides which column to show.
 */

export type KraepelinColumnRunnerProps = UseItemsOptions & {
    /** 0-indexed into the `/items` response's `subtests` array
     * (`col_01` = 0). See module doc: never computed here from time. */
    columnIndex: number;
};

const FINAL_MESSAGE: Record<string, string> = {
    not_started: 'Sesi belum dimulai.',
    closed: 'Sesi sudah ditutup.',
    deadline_exceeded: 'Waktu pengerjaan sudah habis.',
    not_found: 'Sesi tidak ditemukan.',
    content_unavailable: 'Isi soal belum tersedia. Coba lagi nanti.',
};

export function KraepelinColumnRunner({
    fetchItems,
    queueRetry,
    columnIndex,
}: KraepelinColumnRunnerProps) {
    const { state, retry } = useItems({ fetchItems, queueRetry });

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

    const subtest = outcome.content.subtests[columnIndex];

    if (!subtest) {
        return <p role="alert">Kolom tidak ditemukan.</p>;
    }

    // Keyed by subtest.code so switching columns starts with fresh input
    // state rather than carrying over the previous column's values.
    return <KraepelinColumnDisplay key={subtest.code} subtest={subtest} />;
}

function KraepelinColumnDisplay({ subtest }: { subtest: KraepelinSubtest }) {
    const numbers = columnNumbersFromItems(subtest.items);
    const [inputState, setInputState] = useState<ColumnInputState>(() =>
        createColumnInputState(numbers.length - 1),
    );

    return (
        <div className="flex flex-col items-center gap-4">
            <KraepelinColumn
                numbers={numbers}
                state={inputState}
                onChange={setInputState}
            />
            <KraepelinKeypad state={inputState} onChange={setInputState} />
        </div>
    );
}
