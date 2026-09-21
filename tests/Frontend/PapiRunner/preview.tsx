import { createRoot } from 'react-dom/client';

import type { AutosaveBatch, AutosaveSendOutcome } from '../../../resources/js/components/participant/session-runner/autosave-engine';
import type { ResumeAnswersOutcome } from '../../../resources/js/components/participant/session-runner/resume-answers';
import { PapiItemsRunner } from '../../../resources/js/components/participant/papi/papi-items-runner';
import type { PapiItemsOutcome } from '../../../resources/js/components/participant/papi/papi-items';
import './preview.css';

// Standalone fixture: exercises the real PapiItemsRunner (fetch -> loading/
// reconnecting/final states -> instructions -> the 90-item flow -> real
// useAutosave/useResumeAnswers wiring), wired to the real GET
// /sessions/:id/items PAPI shape (PR #71,
// tasks/handoffs/f2/item-delivery-papi-reader.md) -- not the real HTTP
// endpoints (no backend runs in this fixture), synthetic fetchItems/
// fetchResumeAnswers/send matching those shapes exactly, same convention
// as fetchItems always has here. Synthetic statement text, not the real
// papi_items.json content: this fixture verifies UI/wiring behaviour
// (layout, choice recording, autosave, the completeness lock, resume
// rehydration), not statement wording.
const items = Array.from({ length: 90 }, (_, index) => ({
    item: index + 1,
    statement_a: `Pernyataan A untuk butir ${index + 1}`,
    statement_b: `Pernyataan B untuk butir ${index + 1}`,
}));

const availableOutcome: PapiItemsOutcome = {
    type: 'available',
    content: {
        sessionId: 'ses_synthetic',
        instrument: 'papi',
        version: 'fixture-2026.09',
        items,
        instructions: {
            intro: 'Berikut ini terdapat sejumlah pasangan pernyataan tentang diri Anda.',
            example: {
                statement_a: 'Saya suka bekerja dengan orang lain',
                statement_b: 'Saya suka bekerja sendiri',
            },
            answer_sheet_demo: {
                label: 'Contoh cara menjawab di lembar jawaban',
                statement_a: 'Saya suka bekerja dengan orang lain',
                statement_b: 'Saya suka bekerja sendiri',
            },
            closing: 'Pilih pernyataan yang paling menggambarkan diri Anda.',
        },
    },
};

async function fetchItems(): Promise<PapiItemsOutcome> {
    return availableOutcome;
}

// A returning participant who already autosaved item 1 as 'a' before this
// page loaded -- proves useResumeAnswers actually rehydrates the runner
// (item 1 must render pre-selected) instead of always starting blank.
const resumeOutcome: ResumeAnswersOutcome = {
    type: 'available',
    sessionId: 'ses_synthetic',
    answersRevision: 3,
    answers: [{ itemNo: 1, value: 'a' }],
};

async function fetchResumeAnswers(): Promise<ResumeAnswersOutcome> {
    return resumeOutcome;
}

function queueRetry(retry: () => void): () => void {
    // No real connectivity signal in this fixture -- neither fetchItems
    // nor fetchResumeAnswers above ever fail, so queueRetry is never
    // actually invoked.
    void retry;

    return () => {};
}

declare global {
    interface Window {
        // Recorded by the synthetic `send` below, read back by
        // browser.test.mjs via page.evaluate -- the fixture's stand-in
        // for "a POST /sessions/:id/answers actually happened", since no
        // backend runs in this fixture (see module doc above).
        __papiAutosaveCalls: { item_no: number; value: unknown }[][];
    }
}

window.__papiAutosaveCalls = [];

async function send(batch: AutosaveBatch): Promise<AutosaveSendOutcome> {
    // Mirrors the real POST /sessions/:id/answers wire shape
    // (API_CONTRACT.md / Lead's 2026-09-21 review): item_no/value per
    // item, snake_case -- the pure autosave-engine.ts itself only knows
    // the camelCase `itemNo`, so this mapping is deliberately done here,
    // in the transport, the same division of labor autosave-engine.ts's
    // own module doc describes ("the caller supplies the actual HTTP
    // transport").
    window.__papiAutosaveCalls.push(
        batch.items.map((item) => ({ item_no: item.itemNo, value: item.value })),
    );

    return {
        type: 'accepted',
        revision: batch.revision,
        receivedAt: new Date().toISOString(),
        acceptedItemNos: batch.items.map((item) => item.itemNo),
    };
}

const root = document.getElementById('root');

if (root === null) {
    throw new Error('#root not found');
}

createRoot(root).render(
    <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950 sm:py-12">
        <div className="mx-auto w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <PapiItemsRunner
                fetchItems={fetchItems}
                fetchResumeAnswers={fetchResumeAnswers}
                queueRetry={queueRetry}
                send={send}
                onSubmit={() => {}}
            />
        </div>
    </main>,
);
