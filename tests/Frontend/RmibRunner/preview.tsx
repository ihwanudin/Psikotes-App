import { createRoot } from 'react-dom/client';

import type {
    AutosaveBatch,
    AutosaveSendOutcome,
} from '../../../resources/js/components/participant/session-runner/autosave-engine';
import type { ResumeAnswersOutcome } from '../../../resources/js/components/participant/session-runner/resume-answers';
import { RmibItemsRunner } from '../../../resources/js/components/participant/rmib/rmib-items-runner';
import type { RmibItemsOutcome } from '../../../resources/js/components/participant/rmib/rmib-items';
import { rmibItemNo } from '../../../resources/js/components/participant/rmib/rmib-items';
import './preview.css';

// Standalone fixture: exercises the real RmibItemsRunner (fetch -> loading/
// reconnecting/final states -> instructions -> the 9-group flow -> real
// useAutosave/useResumeAnswers wiring), wired to the real GET
// /sessions/:id/items RMIB shape (PR #76,
// tasks/handoffs/f2/item-delivery-rmib-reader.md on
// origin/f2/item-delivery-rmib-reader) -- not the real HTTP endpoints (no
// backend runs in this fixture), synthetic fetchItems/fetchResumeAnswers/
// send matching those shapes exactly, same convention as the PAPI
// fixture. Synthetic job titles, not the real rmib_items.json content:
// this fixture verifies UI/wiring behaviour (drag, keyboard buttons,
// "Simpan urutan ini", autosave, the completeness lock, resume
// rehydration), not job wording.
const positions = Array.from({ length: 9 }, (_, groupIndex) =>
    Array.from({ length: 12 }, (_, positionIndex) => ({
        group: groupIndex + 1,
        group_letter: String.fromCharCode(65 + groupIndex),
        position: positionIndex + 1,
        job: `Pekerjaan ${groupIndex + 1}.${positionIndex + 1}`,
    })),
).flat();

const availableOutcome: RmibItemsOutcome = {
    type: 'available',
    content: {
        sessionId: 'ses_synthetic',
        instrument: 'rmib',
        version: 'fixture-2026.09',
        positions,
        instructions: {
            text: 'Berikut ini terdapat sejumlah kelompok pekerjaan. Urutkan pekerjaan dalam tiap kelompok dari yang paling Anda sukai.',
            write_preferred_jobs_prompt:
                'Tuliskan tiga pekerjaan yang paling Anda sukai.',
        },
    },
};

async function fetchItems(): Promise<RmibItemsOutcome> {
    return availableOutcome;
}

// A returning participant who already confirmed group 1 (reversed order:
// position 12 ranked first, position 1 ranked last) before this page
// loaded -- proves useResumeAnswers/rmibGroupStatesFromResumedAnswers
// actually rehydrate the runner instead of always starting blank.
const group1ResumedAnswers = Array.from({ length: 12 }, (_, positionIndex) => {
    const position = positionIndex + 1;
    const rank = 13 - position; // position 12 -> rank 1, position 1 -> rank 12

    return { itemNo: rmibItemNo(1, position), value: String(rank) };
});

const resumeOutcome: ResumeAnswersOutcome = {
    type: 'available',
    sessionId: 'ses_synthetic',
    answersRevision: 3,
    answers: group1ResumedAnswers,
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
        // backend runs in this fixture (see module doc above). Same
        // pattern as PapiRunner's fixture.
        __rmibAutosaveCalls: { item_no: number; value: unknown }[][];
    }
}

window.__rmibAutosaveCalls = [];

async function send(batch: AutosaveBatch): Promise<AutosaveSendOutcome> {
    window.__rmibAutosaveCalls.push(
        batch.items.map((item) => ({
            item_no: item.itemNo,
            value: item.value,
        })),
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
            <RmibItemsRunner
                fetchItems={fetchItems}
                fetchResumeAnswers={fetchResumeAnswers}
                queueRetry={queueRetry}
                send={send}
                onSubmit={() => {}}
            />
        </div>
    </main>,
);
