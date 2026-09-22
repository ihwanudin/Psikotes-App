import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { KraepelinColumnRunner } from '../../../resources/js/components/participant/kraepelin/kraepelin-column-runner.tsx';
import type {
    AssessmentSessionItems,
    AssessmentSessionItemsOutcome,
} from '../../../resources/js/components/participant/kraepelin/items.ts';
import './preview.css';

// Standalone fixture: exercises the real KraepelinColumnRunner (fetch ->
// loading/reconnecting/final states -> one column's keypad flow), wired to
// the real GET /sessions/:id/items Kraepelin shape (PR #67,
// KraepelinItemContentReader.php: 50 subtests `col_01..col_50`, each 28
// {position, value} entries ALREADY in administration order — position 1
// is the bottom-most number on the sheet, the first one the participant
// works from; the server has already reversed sheet order into
// administration order once, server-side). This fixture's data is built
// directly in that already-reversed shape and deliberately proves the
// client does NOT reverse it again: see browser.test.mjs's order
// assertions. Not the real HTTP endpoint (no backend runs in this
// fixture), a synthetic fetchItems matching the wire shape exactly, same
// convention as the PAPI/RMIB/IST fixtures. Synthetic values, not the
// real kraepelin_grid.json content.
//
// No timer/column-selection logic lives here or in the component --
// CLAUDE.md forbids that in the frontend. `columnIndex` is plain local
// React state in this fixture only, advanced by a debug button (mirrors
// IstSubtestScreen's fixture-only "Ganti ke subtes GE" control) --
// KraepelinColumnRunner itself only ever receives whatever columnIndex its
// caller passes, same as production would.

const COLUMN_COUNT = 50;
const NUMBERS_PER_COLUMN = 28;

// Deterministic per (column, position) so browser.test.mjs can recompute
// the exact expected value without a hardcoded table, and so cycling the
// debug column switcher visibly changes the numbers on screen.
function valueFor(columnNumber: number, position: number): number {
    return ((position - 1 + (columnNumber - 1)) % 9) + 1;
}

const subtests: AssessmentSessionItems['subtests'] = Array.from(
    { length: COLUMN_COUNT },
    (_, columnIndex) => {
        const columnNumber = columnIndex + 1;

        return {
            code: `col_${String(columnNumber).padStart(2, '0')}`,
            items: Array.from({ length: NUMBERS_PER_COLUMN }, (_, i) => ({
                position: i + 1,
                value: valueFor(columnNumber, i + 1),
            })),
        };
    },
);

const availableOutcome: AssessmentSessionItemsOutcome = {
    type: 'available',
    content: {
        sessionId: 'ses_synthetic',
        instrument: 'kraepelin',
        version: 'fixture-2026.09',
        subtests,
    },
};

// The FIRST fetch fails with a real network_error (not a thrown
// exception), landing KraepelinColumnRunner in its 'reconnecting' state so
// the "Coba lagi" button can actually be seen and clicked, not just read
// from source. This fixture's queueRetry never calls the retry callback
// (same "no real connectivity signal" convention as the PAPI/IST
// fixtures), so nothing auto-recovers -- only clicking "Coba lagi" (which
// calls the loader's retry() directly, bypassing the queue) retries, and
// that second attempt succeeds.
let fetchAttempts = 0;

async function fetchItems(): Promise<AssessmentSessionItemsOutcome> {
    fetchAttempts++;

    if (fetchAttempts === 1) {
        return { type: 'network_error' };
    }

    return availableOutcome;
}

function queueRetry(retry: () => void): () => void {
    void retry;

    return () => {};
}

const DEMO_COLUMN_INDICES = [0, 1, 2];

function Fixture() {
    const [columnPointer, setColumnPointer] = useState(0);
    const columnIndex = DEMO_COLUMN_INDICES[columnPointer]!;

    return (
        <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950 sm:py-12">
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={() =>
                            setColumnPointer(
                                (current) =>
                                    (current + 1) % DEMO_COLUMN_INDICES.length,
                            )
                        }
                        className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Kolom berikutnya (uji) — kolom {columnIndex + 1}
                    </button>
                </div>
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <KraepelinColumnRunner
                        key={columnIndex}
                        fetchItems={fetchItems}
                        queueRetry={queueRetry}
                        columnIndex={columnIndex}
                    />
                </div>
            </div>
        </main>
    );
}

const root = document.getElementById('root');

if (root === null) {
    throw new Error('#root not found');
}

createRoot(root).render(<Fixture />);
