import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import type {
    AutosaveBatch,
    AutosaveSendOutcome,
} from '../../../resources/js/components/participant/session-runner/autosave-engine';
import type { ResumeAnswersOutcome } from '../../../resources/js/components/participant/session-runner/resume-answers';
import { IstAssetImage } from '../../../resources/js/components/participant/ist/ist-asset-image';
import type { IstAssetUrlOutcome } from '../../../resources/js/components/participant/ist/ist-asset-url';
import { IstSubtestScreen } from '../../../resources/js/components/participant/ist/ist-subtest-screen';
import type { IstSubtestContent } from '../../../resources/js/components/participant/ist/ist-items';
import './preview.css';

// Standalone fixture: exercises the real IstSubtestScreen (ONE subtest at
// a time, no orchestration -- see that module's doc) with real
// useAutosave/useResumeAnswers wiring, plus IstAssetImage's real
// onError-triggered reload path. Not the real HTTP endpoints (no backend
// runs in this fixture), synthetic fetchResumeAnswers/send/fetchAssetUrl
// matching the real wire shapes exactly, same convention as the PAPI/RMIB
// fixtures. Synthetic item text, not real ist_items.json content.

const SE_SUBTEST: IstSubtestContent = {
    code: 'SE',
    answerType: 'multiple_choice',
    instructions: 'Pilih kata yang paling tepat melengkapi kalimat.',
    items: [
        {
            item: 1,
            text: 'Lawannya "hemat" ialah ...',
            options: {
                a: 'murah',
                b: 'kikir',
                c: 'boros',
                d: 'bernilai',
                e: 'kaya',
            },
        },
        {
            item: 2,
            text: 'Seekor kuda selalu mempunyai ...',
            options: {
                a: 'kandang',
                b: 'ladam',
                c: 'pelana',
                d: 'kuku',
                e: 'surai',
            },
        },
        {
            item: 3,
            text: 'Pengaruh seseorang terhadap orang lain seharusnya bergantung pada ...',
            options: {
                a: 'kekuasaan',
                b: 'bujukan',
                c: 'kekayaan',
                d: 'keberanian',
                e: 'kewibawaan',
            },
        },
    ],
};

const GE_SUBTEST: IstSubtestContent = {
    code: 'GE',
    answerType: 'fill_in_word',
    instructions: 'Temukan satu kata yang menghubungkan kedua kata ini.',
    items: [
        { item: 61, text: 'mawar - melati' },
        { item: 62, text: 'kucing - harimau' },
        { item: 63, text: 'meja - kursi' },
    ],
};

// Resumed state: SE item 1 already answered 'b' before this page loaded --
// proves useResumeAnswers rehydrates IstSubtestScreen for real.
const resumeOutcome: ResumeAnswersOutcome = {
    type: 'available',
    sessionId: 'ses_synthetic',
    answersRevision: 3,
    answers: [{ itemNo: 1, value: 'b' }],
};

async function fetchResumeAnswers(): Promise<ResumeAnswersOutcome> {
    return resumeOutcome;
}

function queueRetry(retry: () => void): () => void {
    void retry;

    return () => {};
}

declare global {
    interface Window {
        __istAutosaveCalls: { item_no: number; value: unknown }[][];
        __istCompletedSubtests: string[];
    }
}

window.__istAutosaveCalls = [];
window.__istCompletedSubtests = [];

async function send(batch: AutosaveBatch): Promise<AutosaveSendOutcome> {
    window.__istAutosaveCalls.push(
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

// --- Asset image demo: the FIRST fetch returns a URL that 404s on this
// fixture's own dev server (a real, unhandled network failure), so the
// <img>'s real onError fires and IstAssetImage really calls reload() --
// the SECOND fetch then returns a tiny inline data: URI that always
// renders, proving the whole onError -> reload -> success path end to
// end, not just the pure loader logic (already covered by
// ist-asset-url-loader.test.ts).
const WORKING_IMAGE_DATA_URI =
    'data:image/svg+xml;base64,' +
    btoa(
        '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#0d9488"/></svg>',
    );

let assetFetchCount = 0;

async function fetchAssetUrl(assetId: string): Promise<IstAssetUrlOutcome> {
    void assetId;
    assetFetchCount++;

    if (assetFetchCount === 1) {
        return {
            type: 'available',
            url: '/this-asset-does-not-exist.png',
            expiresAt: '2099-01-01T00:00:00+00:00',
        };
    }

    return {
        type: 'available',
        url: WORKING_IMAGE_DATA_URI,
        expiresAt: '2099-01-01T00:00:00+00:00',
    };
}

function Fixture() {
    const [activeCode, setActiveCode] = useState<'SE' | 'GE'>('SE');
    const subtest = activeCode === 'SE' ? SE_SUBTEST : GE_SUBTEST;

    return (
        <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950 sm:py-12">
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-10">
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div className="mb-4 flex justify-end">
                        <button
                            type="button"
                            onClick={() =>
                                setActiveCode(activeCode === 'SE' ? 'GE' : 'SE')
                            }
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Ganti ke subtes {activeCode === 'SE' ? 'GE' : 'SE'}
                        </button>
                    </div>
                    <IstSubtestScreen
                        key={activeCode}
                        subtest={subtest}
                        fetchResumeAnswers={fetchResumeAnswers}
                        send={send}
                        queueRetry={queueRetry}
                        remainingSeconds={1800}
                        onComplete={() => {
                            window.__istCompletedSubtests.push(activeCode);
                        }}
                    />
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <p className="mb-3 text-sm font-medium text-slate-700">
                        Contoh opsi gambar (IstAssetImage)
                    </p>
                    <IstAssetImage
                        assetId="asset_demo_1"
                        fetchAssetUrl={fetchAssetUrl}
                        alt="Contoh gambar aset"
                        className="size-20 rounded-lg object-cover"
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
