import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import type {
    AutosaveBatch,
    AutosaveSendOutcome,
} from '../../../resources/js/components/participant/session-runner/autosave-engine';
import { createHttpTransport } from '../../../resources/js/components/participant/session-runner/http-transport';
import type { ResumeAnswersOutcome } from '../../../resources/js/components/participant/session-runner/resume-answers';
import { submitWithVerification } from '../../../resources/js/components/participant/session-runner/submit-session';
import { useOfflineQueue } from '../../../resources/js/components/participant/session-runner/use-offline-queue';
import { RmibItemsRunner } from '../../../resources/js/components/participant/rmib/rmib-items-runner';
import { rmibItemsOutcomeFromGeneric } from '../../../resources/js/components/participant/rmib/rmib-items';
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

// The FIRST call of each fetcher fails with a real network_error, so both
// of RmibItemsRunner's reconnecting states (items' "Tidak dapat
// terhubung" and resume's "Tidak dapat memuat jawaban tersimpan") can
// actually be seen and their "Coba lagi" buttons clicked, not just read
// from source (same convention as the PapiRunner/KraepelinRunner
// fixtures). Because items' check comes first in RmibItemsRunner's
// if-chain, they surface one at a time: items reconnects first; clicking
// its "Coba lagi" resolves it and (since resume's own first attempt also
// already failed, never retried) resume's reconnecting block appears
// next.
let itemsFetchAttempts = 0;

async function fetchItems(): Promise<RmibItemsOutcome> {
    itemsFetchAttempts++;

    if (itemsFetchAttempts === 1) {
        return { type: 'network_error' };
    }

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

let resumeFetchAttempts = 0;

async function fetchResumeAnswers(): Promise<ResumeAnswersOutcome> {
    resumeFetchAttempts++;

    if (resumeFetchAttempts === 1) {
        return { type: 'network_error' };
    }

    return resumeOutcome;
}

function queueRetry(retry: () => void): () => void {
    // No real connectivity signal in this fixture -- neither fetcher
    // above ever auto-recovers, so queueRetry is never actually invoked;
    // only clicking a "Coba lagi" button (which calls retry() directly,
    // bypassing the queue) retries.
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
        acceptedItemNos: batch.items.map((item) => item.itemNo),
    };
}

// --- Real HTTP transport demo (Lead's 2026-09-21 instruction, built on
// this branch's merge of glm/session-http-transport (#86) ahead of any of
// the three source branches landing on main). Mirrors PapiRunner's own
// fixture section exactly (same reasoning, same structure) -- see that
// file's comment for the full rationale. Everything above uses this
// fixture's own long-standing synthetic fetchItems/fetchResumeAnswers/
// send, kept as-is for speed and determinism; this section is wired
// through the REAL createHttpTransport() and RMIB's real
// rmibItemsOutcomeFromGeneric mapping, against a fake HTTP endpoint
// returning the real wire shapes (verified against
// http-transport.test.ts's own fixtures, not guessed).

const HTTP_DEMO_SESSION_ID = 'ses_http_demo';

declare global {
    interface Window {
        __rmibHttpTransportCalls: { method: string; url: string }[];
        __rmibHttpTransportUnauthorizedFired: boolean;
    }
}

window.__rmibHttpTransportCalls = [];
window.__rmibHttpTransportUnauthorizedFired = false;

// Toggled true by the demo's own "Uji token kedaluwarsa" button below --
// makes the very NEXT request (any of the five) come back 401, proving
// createHttpTransport's onUnauthorized wiring works for real.
let httpDemoForceUnauthorized = false;

function rmibHttpDemoFetchImpl(
    input: RequestInfo | URL,
    init?: RequestInit,
): Promise<Response> {
    const url = String(input);
    const method = init?.method ?? 'GET';
    window.__rmibHttpTransportCalls.push({ method, url });

    const json = (status: number, body: unknown): Response =>
        new Response(JSON.stringify(body), {
            status,
            headers: { 'Content-Type': 'application/json' },
        });

    if (httpDemoForceUnauthorized) {
        httpDemoForceUnauthorized = false;

        return Promise.resolve(json(401, { error: { code: 'INVALID_TOKEN' } }));
    }

    if (url.endsWith(`/api/sessions/${HTTP_DEMO_SESSION_ID}/items`)) {
        return Promise.resolve(
            json(200, {
                session_id: HTTP_DEMO_SESSION_ID,
                instrument: 'rmib',
                version: 'http-demo-2026.09',
                subtests: [
                    {
                        code: 'POSITIONS',
                        items: Array.from({ length: 9 }, (_, groupIndex) =>
                            Array.from(
                                { length: 12 },
                                (_valueUnused, positionIndex) => ({
                                    group: groupIndex + 1,
                                    group_letter: String.fromCharCode(
                                        65 + groupIndex,
                                    ),
                                    position: positionIndex + 1,
                                    job: `Pekerjaan (transport HTTP) ${groupIndex + 1}.${positionIndex + 1}`,
                                }),
                            ),
                        ).flat(),
                    },
                ],
                instructions: {
                    text: 'Skenario ini datang lewat createHttpTransport() sungguhan, bukan fixture sintetis.',
                    write_preferred_jobs_prompt:
                        'Tuliskan tiga pekerjaan yang paling Anda sukai.',
                },
            }),
        );
    }

    if (
        url.endsWith(`/api/sessions/${HTTP_DEMO_SESSION_ID}/answers`) &&
        method === 'GET'
    ) {
        return Promise.resolve(
            json(200, {
                session_id: HTTP_DEMO_SESSION_ID,
                answers_revision: 0,
                answers: [],
            }),
        );
    }

    if (
        url.endsWith(`/api/sessions/${HTTP_DEMO_SESSION_ID}/answers`) &&
        method === 'POST'
    ) {
        const sent = init?.body
            ? (JSON.parse(String(init.body)) as {
                  revision: number;
                  items: { item_no: number }[];
              })
            : null;

        return Promise.resolve(
            json(200, {
                session_id: HTTP_DEMO_SESSION_ID,
                status: 'in_progress',
                replayed: false,
                answers_revision: (sent?.revision ?? 0) + 1,
                accepted_item_numbers:
                    sent?.items.map((item) => item.item_no) ?? [],
            }),
        );
    }

    if (
        url.endsWith(`/api/sessions/${HTTP_DEMO_SESSION_ID}/submit`) &&
        method === 'POST'
    ) {
        return Promise.resolve(
            json(200, {
                session_id: HTTP_DEMO_SESSION_ID,
                status: 'submitted',
                submitted_at: new Date().toISOString(),
                answers_revision: 1,
            }),
        );
    }

    if (url.endsWith(`/api/sessions/${HTTP_DEMO_SESSION_ID}`)) {
        return Promise.resolve(
            json(200, {
                session_id: HTTP_DEMO_SESSION_ID,
                test_type: 'rmib',
                status: 'in_progress',
                attempt_no: 1,
                started_at: '2026-09-21T00:00:00Z',
                ends_at: '2099-01-01T00:00:00Z',
                write_deadline: '2099-01-01T00:00:00Z',
                submitted_at: null,
                server_time: new Date().toISOString(),
                remaining_seconds: 1800,
                answers_revision: 1,
                config: null,
                seed: null,
            }),
        );
    }

    return Promise.resolve(json(404, { error: { code: 'NOT_FOUND' } }));
}

function RmibHttpTransportDemo() {
    const offlineQueue = useOfflineQueue();
    const [unauthorized, setUnauthorized] = useState(false);
    const [submitResult, setSubmitResult] = useState<string | null>(null);
    const [transport] = useState(() =>
        createHttpTransport({
            sessionId: HTTP_DEMO_SESSION_ID,
            getToken: () => 'demo-token',
            // Mirrors lobby.tsx's own pattern for a dead/expired token --
            // see PapiRunner's fixture for the full rationale.
            onUnauthorized: () => {
                window.__rmibHttpTransportUnauthorizedFired = true;
                setUnauthorized(true);
            },
            fetchImpl: rmibHttpDemoFetchImpl as typeof fetch,
        }),
    );

    if (unauthorized) {
        return (
            <div role="alert" className="text-sm text-slate-700">
                Sesi psikotes telah berakhir. Buka kembali tautan dari aplikasi
                seleksi.
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <button
                type="button"
                onClick={() => {
                    httpDemoForceUnauthorized = true;
                    // http-transport.ts's request() deliberately THROWS
                    // on 401 after calling onUnauthorized() -- already
                    // handled above; this catch only stops the resulting
                    // rejection from surfacing as an unhandled promise
                    // rejection in the console.
                    transport.fetchResumeAnswers().catch(() => {});
                }}
                className="self-end rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
            >
                Uji token kedaluwarsa (401, uji)
            </button>
            <RmibItemsRunner
                fetchItems={() =>
                    transport.fetchItems().then(rmibItemsOutcomeFromGeneric)
                }
                fetchResumeAnswers={transport.fetchResumeAnswers}
                queueRetry={offlineQueue.queueRetry}
                send={transport.autosaveSend}
                onSubmit={() => {
                    void submitWithVerification(
                        transport.submitSend,
                        transport.fetchSession,
                    ).then((result) => {
                        setSubmitResult(result.status);
                    });
                }}
            />
            {submitResult !== null && (
                <p role="status" className="text-sm text-teal-700">
                    Hasil submit (transport HTTP sungguhan): {submitResult}
                </p>
            )}
        </div>
    );
}

const root = document.getElementById('root');

if (root === null) {
    throw new Error('#root not found');
}

createRoot(root).render(
    <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950 sm:py-12">
        <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <RmibItemsRunner
                    fetchItems={fetchItems}
                    fetchResumeAnswers={fetchResumeAnswers}
                    queueRetry={queueRetry}
                    send={send}
                    onSubmit={() => {}}
                />
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <p className="mb-4 text-sm font-medium text-slate-700">
                    Skenario transport HTTP sungguhan (createHttpTransport,
                    endpoint tiruan)
                </p>
                <RmibHttpTransportDemo />
            </div>
        </div>
    </main>,
);
