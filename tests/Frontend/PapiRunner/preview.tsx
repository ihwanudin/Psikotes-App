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
import { PapiItemsRunner } from '../../../resources/js/components/participant/papi/papi-items-runner';
import { papiItemsOutcomeFromGeneric } from '../../../resources/js/components/participant/papi/papi-items';
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

// The FIRST call of each fetcher fails with a real network_error, so both
// of PapiItemsRunner's reconnecting states (items' "Tidak dapat terhubung"
// and resume's "Tidak dapat memuat jawaban tersimpan") can actually be
// seen and their "Coba lagi" buttons clicked, not just read from source
// (same convention as the KraepelinRunner fixture). Because items' check
// comes first in PapiItemsRunner's if-chain, they surface one at a time:
// items reconnects first; clicking its "Coba lagi" resolves it and (since
// resume's own first attempt also already failed, never retried) resume's
// reconnecting block appears next.
let itemsFetchAttempts = 0;

async function fetchItems(): Promise<PapiItemsOutcome> {
    itemsFetchAttempts++;

    if (itemsFetchAttempts === 1) {
        return { type: 'network_error' };
    }

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
// the three source branches landing on main). Everything above uses this
// fixture's own long-standing synthetic fetchItems/fetchResumeAnswers/
// send, kept as-is for speed and determinism. This section is the one
// Lead specifically asked for: a scenario wired through the REAL
// createHttpTransport() and this instrument's real
// papiItemsOutcomeFromGeneric mapping, against a fake HTTP endpoint that
// returns the real wire shapes (verified against http-transport.test.ts's
// own fixtures, not guessed) -- proving the mapping is actually exercised
// inside a rendered PapiItemsRunner, not just tested in isolation. The
// distinctive "(transport HTTP)" wording in the item text below is what
// lets browser.test.mjs prove this section's content really came through
// this separate path, not a reused synthetic string.

const HTTP_DEMO_SESSION_ID = 'ses_http_demo';

declare global {
    interface Window {
        __papiHttpTransportCalls: { method: string; url: string }[];
        __papiHttpTransportUnauthorizedFired: boolean;
    }
}

window.__papiHttpTransportCalls = [];
window.__papiHttpTransportUnauthorizedFired = false;

// Toggled true by the demo's own "Uji token kedaluwarsa" button below --
// makes the very NEXT request (any of the five) come back 401, proving
// createHttpTransport's onUnauthorized wiring works for real, the same
// cross-cutting way it would for any of the five calls in production.
let httpDemoForceUnauthorized = false;

function papiHttpDemoFetchImpl(
    input: RequestInfo | URL,
    init?: RequestInit,
): Promise<Response> {
    const url = String(input);
    const method = init?.method ?? 'GET';
    window.__papiHttpTransportCalls.push({ method, url });

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
                instrument: 'papi',
                version: 'http-demo-2026.09',
                subtests: [
                    {
                        code: 'ITEMS',
                        items: Array.from({ length: 90 }, (_, index) => ({
                            item: index + 1,
                            statement_a: `Pernyataan A (transport HTTP) untuk butir ${index + 1}`,
                            statement_b: `Pernyataan B (transport HTTP) untuk butir ${index + 1}`,
                        })),
                    },
                ],
                instructions: {
                    intro: 'Skenario ini datang lewat createHttpTransport() sungguhan, bukan fixture sintetis.',
                    example: {
                        statement_a: 'Saya suka bekerja dengan orang lain',
                        statement_b: 'Saya suka bekerja sendiri',
                    },
                    answer_sheet_demo: {
                        label: 'Contoh cara menjawab di lembar jawaban',
                        statement_a: 'Saya suka bekerja dengan orang lain',
                        statement_b: 'Saya suka bekerja sendiri',
                    },
                    closing:
                        'Pilih pernyataan yang paling menggambarkan diri Anda.',
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
                test_type: 'papi',
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

function PapiHttpTransportDemo() {
    const offlineQueue = useOfflineQueue();
    const [unauthorized, setUnauthorized] = useState(false);
    const [submitResult, setSubmitResult] = useState<string | null>(null);
    const [transport] = useState(() =>
        createHttpTransport({
            sessionId: HTTP_DEMO_SESSION_ID,
            getToken: () => 'demo-token',
            // Mirrors lobby.tsx's own pattern for a dead/expired token:
            // no production runner page exists yet to redirect FROM (this
            // is still fixture-only wiring), so this shows the same
            // inline "session ended" message lobby.tsx shows for its own
            // /api/me failures, rather than inventing a redirect target
            // that isn't backed by a real route.
            onUnauthorized: () => {
                window.__papiHttpTransportUnauthorizedFired = true;
                setUnauthorized(true);
            },
            fetchImpl: papiHttpDemoFetchImpl as typeof fetch,
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
                    // on 401 after calling onUnauthorized() (see its
                    // module doc) -- onUnauthorized() is what updates
                    // this demo's UI, already handled below; this catch
                    // only stops the resulting rejection from surfacing
                    // as an unhandled promise rejection in the console.
                    transport.fetchResumeAnswers().catch(() => {});
                }}
                className="self-end rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
            >
                Uji token kedaluwarsa (401, uji)
            </button>
            <PapiItemsRunner
                fetchItems={() =>
                    transport.fetchItems().then(papiItemsOutcomeFromGeneric)
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
                <PapiItemsRunner
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
                <PapiHttpTransportDemo />
            </div>
        </div>
    </main>,
);
