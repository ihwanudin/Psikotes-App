import type {
    AutosaveBatch,
    AutosaveSend,
    AutosaveSendOutcome,
} from './autosave-engine.ts';
import type {
    FetchResumeAnswers,
    ResumeAnswersOutcome,
} from './resume-answers.ts';
import type { SubmitOutcome, SubmitSend } from './submit-session.ts';
import type { SubtestNextOutcome, SubtestNextSend } from './subtest-next.ts';
import type {
    AssessmentSessionState,
    FetchSession,
} from './use-assessment-session.ts';

/**
 * The real `fetch()`-based transport for the five participant session
 * endpoints (`GET /sessions/:id`, `GET /sessions/:id/items`,
 * `GET /sessions/:id/answers`, `POST /sessions/:id/answers`,
 * `POST /sessions/:id/submit`) — one shared module for every instrument,
 * per the plan Lead approved 2026-09-21. Endpoints, auth, and every error
 * code below were read from `routes/api.php`, `API_CONTRACT.md`, and each
 * controller (`GetAssessmentSessionController`,
 * `GetAssessmentSessionItemsController`,
 * `GetAssessmentSessionAnswersController`,
 * `AutosaveAssessmentAnswersController`, `SubmitAssessmentSessionController`)
 * on `origin/main` — not guessed.
 *
 * **Auth**: `Authorization: Bearer <jwt>` — `AuthenticateParticipantJwt.php`
 * reads `$request->bearerToken()`. No CSRF: these routes go through
 * Laravel's stateless `api` middleware group, never `web`
 * (`preventRequestForgery()` in `bootstrap/app.php` is only attached to
 * `web`). `getToken` mirrors the existing `sessionStorage` convention
 * already used by `resources/js/pages/participant/lobby.tsx` — this
 * module never reads `sessionStorage` itself, so it stays fetch-mockable
 * without a DOM. The token is never logged and never placed in an error
 * message anywhere in this file.
 *
 * **`GET /sessions/:id/items` is intentionally generic** (`fetchItems`
 * below returns raw `subtests`/`instructions`, not an instrument-typed
 * shape) — every instrument's own `xxxItemsFromSubtests` (already built
 * for PAPI/RMIB/Kraepelin) still does the per-instrument unwrapping on
 * top of this. The four-copies-of-a-retry-loader debt
 * (`tasks/handoffs/f2/papi-runner-retry-loader-extraction-debt.md`) is
 * about a different layer (the loader/retry state machine around each
 * `fetchXxxItems` call) and is unaffected by this file.
 *
 * **401 (`INVALID_TOKEN`) is cross-cutting, not per-outcome-type**: an
 * expired/invalid token can happen on any of the five calls, and retrying
 * it is never correct (a dead token does not recover on its own — unlike
 * a `network_error`, which a connectivity-restored signal can
 * legitimately retry). Lead's 2026-09-21 decision: `onUnauthorized()`
 * fires exactly once, and the transport goes fully dead afterward — every
 * later call from ANY of the five functions this module returns rejects
 * immediately, without ever calling `fetchImpl` again. This is
 * deliberately NOT modeled as a variant on `AutosaveSendOutcome`/
 * `ResumeAnswersOutcome`/etc.: a dead token means the whole page is about
 * to be torn down (same `sessionStorage`-clear-and-redirect shape
 * `lobby.tsx` already uses), not "one call failed, show a status for
 * it" — extending every outcome union for a case where the per-call UI
 * underneath no longer matters would be the wrong fix for the wrong
 * layer.
 *
 * A genuine `fetch()` failure (offline, DNS, timeout — a REJECTED
 * promise) is never swallowed into a synthetic resolved outcome here; it
 * propagates as a rejection from every one of these five functions. Every
 * consumer that already exists (autosave-engine.ts, the resume/items
 * retry loaders) already treats a rejected promise identically to an
 * explicit `network_error` outcome — this module relies on that
 * established convention rather than inventing a second one. A
 * successful HTTP response with an unparseable body or an error `code`
 * outside the known table below resolves as `network_error` too (a safe
 * default: retry, not crash) for every function except `fetchSession`,
 * whose existing contract (`FetchSession`, from before this module
 * existed) has no outcome union at all and simply throws on any failure.
 */

export type GenericItemsContent = {
    sessionId: string;
    instrument: string;
    version: string;
    subtests: { code: string; items: unknown[] }[];
    instructions: unknown;
};

export type GenericItemsOutcome =
    | { type: 'available'; content: GenericItemsContent }
    | { type: 'not_started' }
    | { type: 'closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'not_found' }
    | { type: 'content_unavailable' }
    | { type: 'network_error' };

export type FetchGenericItems = () => Promise<GenericItemsOutcome>;

export type HttpTransportOptions = {
    sessionId: string;
    /** Reads the participant's bearer token — production callers pass
     * `() => sessionStorage.getItem('participant_access_token')`, the
     * exact key `lobby.tsx` already uses. Injected (not read directly by
     * this module) so it stays testable without a DOM/sessionStorage. */
    getToken: () => string | null;
    /** Called exactly once, the first time any of this transport's calls
     * gets a 401. See this module's doc for why the transport goes fully
     * dead afterward instead of retrying. */
    onUnauthorized: () => void;
    /** Injectable for testing; defaults to the global `fetch`. */
    fetchImpl?: typeof fetch;
};

export type HttpTransport = {
    fetchSession: FetchSession;
    fetchItems: FetchGenericItems;
    fetchResumeAnswers: FetchResumeAnswers;
    autosaveSend: AutosaveSend;
    submitSend: SubmitSend;
    subtestNextSend: SubtestNextSend;
};

type ApiErrorBody = { error?: { code?: string } };

function errorCode(body: unknown): string | null {
    return (body as ApiErrorBody | null)?.error?.code ?? null;
}

export function createHttpTransport(
    options: HttpTransportOptions,
): HttpTransport {
    const { sessionId, getToken, onUnauthorized } = options;
    const fetchImpl = options.fetchImpl ?? fetch;
    let dead = false;

    async function request(
        path: string,
        init: RequestInit = {},
    ): Promise<{ status: number; body: unknown }> {
        if (dead) {
            throw new Error(
                'http-transport: transport is dead after an earlier 401 — no further requests are made',
            );
        }

        const token = getToken();
        const headers: Record<string, string> = {
            Accept: 'application/json',
        };

        if (init.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        if (token !== null) {
            headers.Authorization = `Bearer ${token}`;
        }

        const response = await fetchImpl(path, { ...init, headers });

        if (response.status === 401) {
            if (!dead) {
                dead = true;
                onUnauthorized();
            }

            throw new Error('http-transport: unauthorized (401)');
        }

        let body: unknown = null;

        try {
            body = await response.json();
        } catch {
            body = null;
        }

        return { status: response.status, body };
    }

    const fetchSession: FetchSession = async () => {
        const { status, body } = await request(`/api/sessions/${sessionId}`, {
            method: 'GET',
        });

        if (status < 200 || status >= 300 || body === null) {
            throw new Error(
                `http-transport: GET /sessions/:id failed (status ${status})`,
            );
        }

        return sessionStateFromBody(body);
    };

    const fetchItems: FetchGenericItems = async () => {
        const { status, body } = await request(
            `/api/sessions/${sessionId}/items`,
            {
                method: 'GET',
            },
        );

        if (status >= 200 && status < 300 && body !== null) {
            const b = body as Record<string, unknown>;

            return {
                type: 'available',
                content: {
                    sessionId: String(b.session_id),
                    instrument: String(b.instrument),
                    version: String(b.version),
                    subtests: b.subtests as {
                        code: string;
                        items: unknown[];
                    }[],
                    instructions: b.instructions,
                },
            };
        }

        switch (errorCode(body)) {
            case 'SESSION_NOT_FOUND':
                return { type: 'not_found' };
            case 'SESSION_NOT_STARTED':
                return { type: 'not_started' };
            case 'SESSION_CLOSED':
                return { type: 'closed' };
            case 'DEADLINE_EXCEEDED':
                return { type: 'deadline_exceeded' };
            case 'ASSESSMENT_ITEM_CONTENT_UNAVAILABLE':
                return { type: 'content_unavailable' };
            default:
                return { type: 'network_error' };
        }
    };

    const fetchResumeAnswers: FetchResumeAnswers = async () => {
        const { status, body } = await request(
            `/api/sessions/${sessionId}/answers`,
            {
                method: 'GET',
            },
        );

        if (status >= 200 && status < 300 && body !== null) {
            const b = body as Record<string, unknown>;
            const rawAnswers =
                (b.answers as { item_no: unknown; value: unknown }[]) ?? [];

            return {
                type: 'available',
                sessionId: String(b.session_id),
                answersRevision: Number(b.answers_revision),
                answers: rawAnswers.map((entry) => ({
                    itemNo: Number(entry.item_no),
                    value: entry.value,
                })),
            } satisfies ResumeAnswersOutcome;
        }

        switch (errorCode(body)) {
            case 'SESSION_NOT_FOUND':
                return { type: 'not_found' };
            case 'SESSION_NOT_STARTED':
                return { type: 'not_started' };
            case 'SESSION_CLOSED':
                return { type: 'closed' };
            case 'DEADLINE_EXCEEDED':
                return { type: 'deadline_exceeded' };
            default:
                return { type: 'network_error' };
        }
    };

    const autosaveSend: AutosaveSend = async (batch: AutosaveBatch) => {
        const { status, body } = await request(
            `/api/sessions/${sessionId}/answers`,
            {
                method: 'POST',
                body: JSON.stringify({
                    mutation_id: batch.mutationId,
                    revision: batch.revision,
                    items: batch.items.map((item) => ({
                        item_no: item.itemNo,
                        value: item.value,
                    })),
                }),
            },
        );

        if (status >= 200 && status < 300 && body !== null) {
            const b = body as Record<string, unknown>;

            // The real POST /sessions/:id/answers success response
            // (session_id, status, replayed, answers_revision,
            // accepted_item_numbers) has no server timestamp field —
            // confirmed by reading AutosaveAssessmentAnswersController.php,
            // not assumed. AutosaveSendOutcome's accepted variant used to
            // require a receivedAt field; Lead's 2026-09-21 review caught
            // that the first version of this file filled it with a
            // client-side capture time, which would eventually be misread
            // as a server-authoritative timestamp given the field's name.
            // The field was removed from the type entirely rather than
            // fabricated here — see autosave-engine.ts's doc on it.
            return {
                type: 'accepted',
                revision: Number(b.answers_revision),
                acceptedItemNos: (b.accepted_item_numbers as number[]) ?? [],
            } satisfies AutosaveSendOutcome;
        }

        switch (errorCode(body)) {
            case 'SESSION_NOT_FOUND':
                return { type: 'not_found' };
            case 'SESSION_NOT_STARTED':
                return { type: 'not_started' };
            case 'SESSION_CLOSED':
                return { type: 'session_closed' };
            case 'DEADLINE_EXCEEDED':
                return { type: 'deadline_exceeded' };
            case 'AUTOSAVE_STALE_REVISION':
                return { type: 'stale_revision' };
            case 'AUTOSAVE_REVISION_GAP':
                return { type: 'revision_gap' };
            case 'MUTATION_PAYLOAD_MISMATCH':
                return { type: 'payload_mismatch' };
            case 'INVALID_ANSWER_BATCH':
                return { type: 'invalid_batch' };
            default:
                return { type: 'network_error' };
        }
    };

    const submitSend: SubmitSend = async () => {
        const { status, body } = await request(
            `/api/sessions/${sessionId}/submit`,
            {
                method: 'POST',
            },
        );

        if (status >= 200 && status < 300 && body !== null) {
            const b = body as Record<string, unknown>;

            return {
                type: 'accepted',
                sessionId: String(b.session_id),
                status: b.status as 'submitted' | 'scored',
                submittedAt: (b.submitted_at as string | null) ?? null,
                answersRevision: Number(b.answers_revision),
            } satisfies SubmitOutcome;
        }

        switch (errorCode(body)) {
            case 'SESSION_NOT_FOUND':
                return { type: 'not_found' };
            case 'SESSION_NOT_STARTED':
                return { type: 'not_started' };
            case 'SESSION_CLOSED':
                return { type: 'closed' };
            case 'DEADLINE_EXCEEDED':
                return { type: 'deadline_exceeded' };
            default:
                return { type: 'network_error' };
        }
    };

    const subtestNextSend: SubtestNextSend = async () => {
        const { status, body } = await request(
            `/api/sessions/${sessionId}/subtest/next`,
            {
                method: 'POST',
            },
        );

        if (status >= 200 && status < 300 && body !== null) {
            const b = body as Record<string, unknown>;
            const segment = b.current_segment as Record<string, unknown>;

            return {
                type: 'accepted',
                segmentIndex: Number(segment.index),
                becameCurrentAt: String(segment.became_current_at),
                startedAt: (segment.started_at as string | null) ?? null,
            } satisfies SubtestNextOutcome;
        }

        switch (errorCode(body)) {
            case 'SESSION_NOT_FOUND':
                return { type: 'not_found' };
            case 'SESSION_NOT_STARTED':
                return { type: 'not_started' };
            case 'SESSION_CLOSED':
                return { type: 'closed' };
            case 'DEADLINE_EXCEEDED':
                return { type: 'deadline_exceeded' };
            case 'INVALID_SESSION_TRANSITION':
                return { type: 'invalid_transition' };
            default:
                return { type: 'network_error' };
        }
    };

    return {
        fetchSession,
        fetchItems,
        fetchResumeAnswers,
        autosaveSend,
        submitSend,
        subtestNextSend,
    };
}

function sessionStateFromBody(body: unknown): AssessmentSessionState {
    const b = body as Record<string, unknown>;

    return {
        sessionId: String(b.session_id),
        testType: b.test_type as AssessmentSessionState['testType'],
        status: b.status as AssessmentSessionState['status'],
        attemptNo: Number(b.attempt_no),
        startedAt: (b.started_at as string | null) ?? null,
        endsAt: (b.ends_at as string | null) ?? null,
        writeDeadline: (b.write_deadline as string | null) ?? null,
        submittedAt: (b.submitted_at as string | null) ?? null,
        serverTime: String(b.server_time),
        remainingSeconds: Number(b.remaining_seconds),
        answersRevision: Number(b.answers_revision),
        config: b.config ?? null,
        seed: (b.seed as string | null) ?? null,
        currentSegment: currentSegmentFromBody(b.current_segment),
    };
}

function currentSegmentFromBody(
    value: unknown,
): AssessmentSessionState['currentSegment'] {
    if (value === null || value === undefined) {
        return null;
    }

    const s = value as Record<string, unknown>;

    return {
        code: String(s.code),
        index: Number(s.index),
        startedAt: (s.started_at as string | null) ?? null,
        endsAt: (s.ends_at as string | null) ?? null,
        remainingSeconds: (s.remaining_seconds as number | null) ?? null,
    };
}
