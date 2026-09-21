import { createRoot } from 'react-dom/client';
import { PapiRunner } from '../../../resources/js/components/participant/papi/papi-runner';
import './preview.css';

// Standalone fixture: PapiRunner is a plain component (not an Inertia
// page), driven by an `items` prop -- no /items wiring exists yet (see
// the PAPI runner plan). Synthetic 90-item data, not the real
// papi_items.json content: this fixture verifies UI behaviour (layout,
// choice recording, the completeness lock), not statement wording, so
// short predictable strings are enough and keep the harness self-
// contained.
const items = Array.from({ length: 90 }, (_, index) => ({
    item: index + 1,
    statement_a: `Pernyataan A untuk butir ${index + 1}`,
    statement_b: `Pernyataan B untuk butir ${index + 1}`,
}));

const root = document.getElementById('root');

if (root === null) {
    throw new Error('#root not found');
}

createRoot(root).render(
    <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950 sm:py-12">
        <div className="mx-auto w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <PapiRunner items={items} onSubmit={() => {}} />
        </div>
    </main>,
);
