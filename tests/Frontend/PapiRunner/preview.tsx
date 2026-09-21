import { createRoot } from 'react-dom/client';
import { PapiItemsRunner } from '../../../resources/js/components/participant/papi/papi-items-runner';
import type { PapiItemsOutcome } from '../../../resources/js/components/participant/papi/papi-items';
import './preview.css';

// Standalone fixture: exercises the real PapiItemsRunner (fetch -> loading/
// reconnecting/final states -> instructions -> the 90-item flow), wired to
// the real GET /sessions/:id/items PAPI shape (PR #71,
// tasks/handoffs/f2/item-delivery-papi-reader.md) -- not the real HTTP
// endpoint (no backend runs in this fixture), a synthetic fetchItems
// resolving immediately with 90 items + instructions, matching that shape
// exactly. Synthetic statement text, not the real papi_items.json content:
// this fixture verifies UI behaviour (layout, choice recording, the
// completeness lock, instructions display), not statement wording.
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

function queueRetry(retry: () => void): () => void {
    // No real connectivity signal in this fixture -- fetchItems above
    // never fails, so queueRetry is never actually invoked.
    void retry;

    return () => {};
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
                queueRetry={queueRetry}
                onSubmit={() => {}}
            />
        </div>
    </main>,
);
