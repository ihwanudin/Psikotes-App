import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createHttpTransport } from './http-transport.ts';

type CapturedCall = { url: string; init: RequestInit };
type QueuedResponse =
    { status: number; body: unknown } | { networkError: true };

function fakeFetch(responses: QueuedResponse[]): {
    fetchImpl: typeof fetch;
    calls: CapturedCall[];
} {
    const calls: CapturedCall[] = [];
    let index = 0;

    const fetchImpl = (async (
        input: RequestInfo | URL,
        init?: RequestInit,
    ): Promise<Response> => {
        calls.push({ url: String(input), init: init ?? {} });
        const queued = responses[Math.min(index, responses.length - 1)]!;
        index++;

        if ('networkError' in queued) {
            throw new TypeError('Failed to fetch');
        }

        return new Response(JSON.stringify(queued.body), {
            status: queued.status,
            headers: { 'Content-Type': 'application/json' },
        });
    }) as typeof fetch;

    return { fetchImpl, calls };
}

function noop(): void {}

// --- fetchSession ---

test('fetchSession maps the snake_case response to AssessmentSessionState', async () => {
    const { fetchImpl } = fakeFetch([
        {
            status: 200,
            body: {
                session_id: 'ses_1',
                test_type: 'papi',
                status: 'in_progress',
                attempt_no: 1,
                started_at: '2026-09-21T00:00:00Z',
                ends_at: '2026-09-21T01:00:00Z',
                write_deadline: '2026-09-21T01:00:00Z',
                submitted_at: null,
                server_time: '2026-09-21T00:30:00Z',
                remaining_seconds: 1800,
                answers_revision: 5,
                config: { total_duration_seconds: 3600 },
                seed: 'seed-1',
                replayed: false,
                current_segment: null,
            },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const state = await transport.fetchSession();

    assert.deepEqual(state, {
        sessionId: 'ses_1',
        testType: 'papi',
        status: 'in_progress',
        attemptNo: 1,
        startedAt: '2026-09-21T00:00:00Z',
        endsAt: '2026-09-21T01:00:00Z',
        writeDeadline: '2026-09-21T01:00:00Z',
        submittedAt: null,
        serverTime: '2026-09-21T00:30:00Z',
        remainingSeconds: 1800,
        answersRevision: 5,
        config: { total_duration_seconds: 3600 },
        seed: 'seed-1',
        currentSegment: null,
    });
});

// F2 timed-segments stage 5 (PR #109) added `current_segment` to
// GET /sessions/:id — verified against
// `AssessmentSessionResource::currentSegment()`, not guessed. A response
// from before that stage (or a session that never started) omits/nulls the
// field; the test above already covers that. This covers the populated
// shape IST's real orchestration (F2 IST real test page) actually reads.
test('fetchSession maps a populated current_segment', async () => {
    const { fetchImpl } = fakeFetch([
        {
            status: 200,
            body: {
                session_id: 'ses_1',
                test_type: 'ist',
                status: 'in_progress',
                attempt_no: 1,
                started_at: '2026-09-22T00:00:00Z',
                ends_at: '2026-09-22T02:00:00Z',
                write_deadline: '2026-09-22T02:00:00Z',
                submitted_at: null,
                server_time: '2026-09-22T00:05:00Z',
                remaining_seconds: 7200,
                answers_revision: 0,
                config: { total_duration_seconds: 7200 },
                seed: null,
                replayed: false,
                current_segment: {
                    code: 'SE',
                    index: 0,
                    started_at: null,
                    ends_at: null,
                    remaining_seconds: null,
                },
            },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const state = await transport.fetchSession();

    assert.deepEqual(state.currentSegment, {
        code: 'SE',
        index: 0,
        startedAt: null,
        endsAt: null,
        remainingSeconds: null,
    });
});

test('fetchSession throws on a non-2xx response (its contract has no outcome union)', async () => {
    const { fetchImpl } = fakeFetch([
        {
            status: 404,
            body: { error: { code: 'SESSION_NOT_FOUND', message: 'x' } },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    await assert.rejects(() => transport.fetchSession());
});

// --- fetchItems ---

test('fetchItems returns the raw generic envelope on success, without per-instrument typing', async () => {
    const { fetchImpl } = fakeFetch([
        {
            status: 200,
            body: {
                session_id: 'ses_1',
                instrument: 'rmib',
                version: 'v1',
                subtests: [{ code: 'POSITIONS', items: [{ group: 1 }] }],
                instructions: { text: 'hello' },
            },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const outcome = await transport.fetchItems();

    assert.deepEqual(outcome, {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'rmib',
            version: 'v1',
            subtests: [{ code: 'POSITIONS', items: [{ group: 1 }] }],
            instructions: { text: 'hello' },
        },
    });
});

const ITEMS_ERROR_CASES: [string, string][] = [
    ['SESSION_NOT_FOUND', 'not_found'],
    ['SESSION_NOT_STARTED', 'not_started'],
    ['SESSION_CLOSED', 'closed'],
    ['DEADLINE_EXCEEDED', 'deadline_exceeded'],
    ['ASSESSMENT_ITEM_CONTENT_UNAVAILABLE', 'content_unavailable'],
    ['SOME_UNKNOWN_FUTURE_CODE', 'network_error'],
];

for (const [code, expectedType] of ITEMS_ERROR_CASES) {
    test(`fetchItems maps server code ${code} to outcome type ${expectedType}`, async () => {
        const { fetchImpl } = fakeFetch([
            { status: 409, body: { error: { code, message: 'x' } } },
        ]);
        const transport = createHttpTransport({
            sessionId: 'ses_1',
            getToken: () => 'tok',
            onUnauthorized: noop,
            fetchImpl,
        });

        const outcome = await transport.fetchItems();

        assert.equal(outcome.type, expectedType);
    });
}

// --- fetchResumeAnswers ---

test('fetchResumeAnswers maps item_no to itemNo for every answer', async () => {
    const { fetchImpl } = fakeFetch([
        {
            status: 200,
            body: {
                session_id: 'ses_1',
                answers_revision: 3,
                answers: [
                    { item_no: 1, value: 'a' },
                    { item_no: 2, value: 'b' },
                ],
            },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const outcome = await transport.fetchResumeAnswers();

    assert.deepEqual(outcome, {
        type: 'available',
        sessionId: 'ses_1',
        answersRevision: 3,
        answers: [
            { itemNo: 1, value: 'a' },
            { itemNo: 2, value: 'b' },
        ],
    });
});

const RESUME_ERROR_CASES: [string, string][] = [
    ['SESSION_NOT_FOUND', 'not_found'],
    ['SESSION_NOT_STARTED', 'not_started'],
    ['SESSION_CLOSED', 'closed'],
    ['DEADLINE_EXCEEDED', 'deadline_exceeded'],
    ['SOME_UNKNOWN_FUTURE_CODE', 'network_error'],
];

for (const [code, expectedType] of RESUME_ERROR_CASES) {
    test(`fetchResumeAnswers maps server code ${code} to outcome type ${expectedType}`, async () => {
        const { fetchImpl } = fakeFetch([
            { status: 409, body: { error: { code, message: 'x' } } },
        ]);
        const transport = createHttpTransport({
            sessionId: 'ses_1',
            getToken: () => 'tok',
            onUnauthorized: noop,
            fetchImpl,
        });

        const outcome = await transport.fetchResumeAnswers();

        assert.equal(outcome.type, expectedType);
    });
}

// --- autosaveSend ---

test('autosaveSend maps itemNo to item_no in the request body and parses an accepted response', async () => {
    const { fetchImpl, calls } = fakeFetch([
        {
            status: 200,
            body: {
                session_id: 'ses_1',
                status: 'in_progress',
                replayed: false,
                answers_revision: 8,
                accepted_item_numbers: [1, 2],
            },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const outcome = await transport.autosaveSend({
        mutationId: 'mut-1',
        revision: 8,
        items: [
            { itemNo: 1, value: 'a' },
            { itemNo: 2, value: 'b' },
        ],
    });

    assert.equal(outcome.type, 'accepted');
    assert.equal(outcome.type === 'accepted' && outcome.revision, 8);
    assert.deepEqual(
        outcome.type === 'accepted' && outcome.acceptedItemNos,
        [1, 2],
    );

    const sentBody = JSON.parse(String(calls[0]!.init.body));
    assert.deepEqual(sentBody, {
        mutation_id: 'mut-1',
        revision: 8,
        items: [
            { item_no: 1, value: 'a' },
            { item_no: 2, value: 'b' },
        ],
    });
});

const AUTOSAVE_ERROR_CASES: [string, string][] = [
    ['SESSION_NOT_FOUND', 'not_found'],
    ['SESSION_NOT_STARTED', 'not_started'],
    ['SESSION_CLOSED', 'session_closed'],
    ['DEADLINE_EXCEEDED', 'deadline_exceeded'],
    ['AUTOSAVE_STALE_REVISION', 'stale_revision'],
    ['AUTOSAVE_REVISION_GAP', 'revision_gap'],
    ['MUTATION_PAYLOAD_MISMATCH', 'payload_mismatch'],
    ['INVALID_ANSWER_BATCH', 'invalid_batch'],
    ['SOME_UNKNOWN_FUTURE_CODE', 'network_error'],
];

for (const [code, expectedType] of AUTOSAVE_ERROR_CASES) {
    test(`autosaveSend maps server code ${code} to outcome type ${expectedType}`, async () => {
        const { fetchImpl } = fakeFetch([
            { status: 409, body: { error: { code, message: 'x' } } },
        ]);
        const transport = createHttpTransport({
            sessionId: 'ses_1',
            getToken: () => 'tok',
            onUnauthorized: noop,
            fetchImpl,
        });

        const outcome = await transport.autosaveSend({
            mutationId: 'mut-1',
            revision: 1,
            items: [{ itemNo: 1, value: 'a' }],
        });

        assert.equal(outcome.type, expectedType);
    });
}

// --- submitSend ---

test('submitSend parses an accepted response, POSTs with no body', async () => {
    const { fetchImpl, calls } = fakeFetch([
        {
            status: 200,
            body: {
                session_id: 'ses_1',
                status: 'submitted',
                submitted_at: '2026-09-21T00:31:00Z',
                answers_revision: 9,
            },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const outcome = await transport.submitSend();

    assert.deepEqual(outcome, {
        type: 'accepted',
        sessionId: 'ses_1',
        status: 'submitted',
        submittedAt: '2026-09-21T00:31:00Z',
        answersRevision: 9,
    });
    assert.equal(calls[0]!.init.method, 'POST');
    assert.equal(
        calls[0]!.init.body,
        undefined,
        'submit has no request body per SubmitAssessmentSessionController',
    );
});

const SUBMIT_ERROR_CASES: [string, string][] = [
    ['SESSION_NOT_FOUND', 'not_found'],
    ['SESSION_NOT_STARTED', 'not_started'],
    ['SESSION_CLOSED', 'closed'],
    ['DEADLINE_EXCEEDED', 'deadline_exceeded'],
    ['SOME_UNKNOWN_FUTURE_CODE', 'network_error'],
];

for (const [code, expectedType] of SUBMIT_ERROR_CASES) {
    test(`submitSend maps server code ${code} to outcome type ${expectedType}`, async () => {
        const { fetchImpl } = fakeFetch([
            { status: 409, body: { error: { code, message: 'x' } } },
        ]);
        const transport = createHttpTransport({
            sessionId: 'ses_1',
            getToken: () => 'tok',
            onUnauthorized: noop,
            fetchImpl,
        });

        const outcome = await transport.submitSend();

        assert.equal(outcome.type, expectedType);
    });
}

// --- subtestNextSend ---

test('subtestNextSend parses an accepted response, POSTs with no body, to the right URL', async () => {
    const { fetchImpl, calls } = fakeFetch([
        {
            status: 200,
            body: {
                session_id: 'ses_1',
                current_segment: {
                    index: 0,
                    became_current_at: '2026-09-22T00:00:00Z',
                    started_at: '2026-09-22T00:00:00Z',
                },
            },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const outcome = await transport.subtestNextSend();

    assert.deepEqual(outcome, {
        type: 'accepted',
        segmentIndex: 0,
        becameCurrentAt: '2026-09-22T00:00:00Z',
        startedAt: '2026-09-22T00:00:00Z',
    });
    assert.equal(calls[0]!.url, '/api/sessions/ses_1/subtest/next');
    assert.equal(calls[0]!.init.method, 'POST');
    assert.equal(
        calls[0]!.init.body,
        undefined,
        'subtest/next has no request body per SubtestNextController',
    );
});

test('subtestNextSend maps a still-waiting reading gap (started_at null) through as-is', async () => {
    const { fetchImpl } = fakeFetch([
        {
            status: 200,
            body: {
                session_id: 'ses_1',
                current_segment: {
                    index: 1,
                    became_current_at: '2026-09-22T00:30:00Z',
                    started_at: null,
                },
            },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const outcome = await transport.subtestNextSend();

    assert.deepEqual(outcome, {
        type: 'accepted',
        segmentIndex: 1,
        becameCurrentAt: '2026-09-22T00:30:00Z',
        startedAt: null,
    });
});

const SUBTEST_NEXT_ERROR_CASES: [number, string, string][] = [
    [404, 'SESSION_NOT_FOUND', 'not_found'],
    [409, 'SESSION_NOT_STARTED', 'not_started'],
    [409, 'SESSION_CLOSED', 'closed'],
    [409, 'DEADLINE_EXCEEDED', 'deadline_exceeded'],
    [422, 'INVALID_SESSION_TRANSITION', 'invalid_transition'],
    [500, 'SOME_UNKNOWN_FUTURE_CODE', 'network_error'],
];

for (const [status, code, expectedType] of SUBTEST_NEXT_ERROR_CASES) {
    test(`subtestNextSend maps server code ${code} (HTTP ${status}) to outcome type ${expectedType}`, async () => {
        const { fetchImpl } = fakeFetch([
            { status, body: { error: { code, message: 'x' } } },
        ]);
        const transport = createHttpTransport({
            sessionId: 'ses_1',
            getToken: () => 'tok',
            onUnauthorized: noop,
            fetchImpl,
        });

        const outcome = await transport.subtestNextSend();

        assert.equal(outcome.type, expectedType);
    });
}

// --- auth header ---

test('every request carries Authorization: Bearer <token> when a token is available', async () => {
    const { fetchImpl, calls } = fakeFetch([
        { status: 200, body: { answers: [] } },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'secret-token-value',
        onUnauthorized: noop,
        fetchImpl,
    });

    await transport.fetchResumeAnswers();

    const headers = calls[0]!.init.headers as Record<string, string>;
    assert.equal(headers.Authorization, 'Bearer secret-token-value');
});

test('no Authorization header is sent when getToken returns null', async () => {
    const { fetchImpl, calls } = fakeFetch([
        { status: 200, body: { answers: [] } },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => null,
        onUnauthorized: noop,
        fetchImpl,
    });

    await transport.fetchResumeAnswers();

    const headers = calls[0]!.init.headers as Record<string, string>;
    assert.equal('Authorization' in headers, false);
});

// --- network failure propagation ---

test('a real fetch() failure propagates as a rejection, for both a GET and a POST endpoint', async () => {
    const items = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl: fakeFetch([{ networkError: true }]).fetchImpl,
    });

    await assert.rejects(() => items.fetchItems());

    const autosave = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl: fakeFetch([{ networkError: true }]).fetchImpl,
    });

    await assert.rejects(() =>
        autosave.autosaveSend({ mutationId: 'm', revision: 1, items: [] }),
    );
});

test('an unparseable success response resolves as network_error rather than crashing', async () => {
    const calls: CapturedCall[] = [];
    const fetchImpl = (async (input: RequestInfo | URL, init?: RequestInit) => {
        calls.push({ url: String(input), init: init ?? {} });

        return new Response('not json', { status: 200 });
    }) as typeof fetch;
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'tok',
        onUnauthorized: noop,
        fetchImpl,
    });

    const outcome = await transport.fetchItems();

    assert.deepEqual(outcome, { type: 'network_error' });
});

// --- 401 dead-state ---

test('401 calls onUnauthorized exactly once and kills the transport: later calls never touch fetch again', async () => {
    let unauthorizedCalls = 0;
    const { fetchImpl, calls } = fakeFetch([
        {
            status: 401,
            body: { error: { code: 'INVALID_TOKEN', message: 'x' } },
        },
        // If a second real fetch happened, this would be returned instead
        // of the transport short-circuiting — the test below asserts it
        // never gets used.
        { status: 200, body: { answers: [] } },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'expired-token',
        onUnauthorized: () => {
            unauthorizedCalls++;
        },
        fetchImpl,
    });

    await assert.rejects(() => transport.fetchResumeAnswers());
    assert.equal(unauthorizedCalls, 1);
    assert.equal(calls.length, 1);

    // A second call, on a completely different endpoint, must reject
    // immediately without a second network call and without a second
    // onUnauthorized() call.
    await assert.rejects(() => transport.fetchItems());
    assert.equal(unauthorizedCalls, 1, 'onUnauthorized must fire at most once');
    assert.equal(
        calls.length,
        1,
        'no further fetch() call must ever happen once dead',
    );
});

test('the 401 error thrown never includes the token value', async () => {
    const { fetchImpl } = fakeFetch([
        {
            status: 401,
            body: { error: { code: 'INVALID_TOKEN', message: 'x' } },
        },
    ]);
    const transport = createHttpTransport({
        sessionId: 'ses_1',
        getToken: () => 'super-secret-token-xyz',
        onUnauthorized: noop,
        fetchImpl,
    });

    try {
        await transport.fetchResumeAnswers();
        assert.fail('expected fetchResumeAnswers to reject');
    } catch (error) {
        assert.equal(
            String(error).includes('super-secret-token-xyz'),
            false,
            'the token value must never appear in an error message',
        );
    }
});
