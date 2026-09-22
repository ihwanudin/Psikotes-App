import assert from 'node:assert/strict';
import { test } from 'node:test';

import { submitWithVerification } from './submit-session.ts';
import type { SubmitOutcome } from './submit-session.ts';
import type { AssessmentSessionState } from './use-assessment-session.ts';

function fakeSession(
    overrides: Partial<AssessmentSessionState> = {},
): AssessmentSessionState {
    return {
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
        config: null,
        seed: null,
        ...overrides,
    };
}

function neverCalledVerify(): Promise<AssessmentSessionState> {
    throw new Error('verify() must not be called for a non-ambiguous outcome');
}

test('accepted submit is returned as-is, without ever calling verify', async () => {
    const outcome: SubmitOutcome = {
        type: 'accepted',
        sessionId: 'ses_1',
        status: 'submitted',
        submittedAt: '2026-09-21T00:31:00Z',
        answersRevision: 5,
    };
    const result = await submitWithVerification(
        async () => outcome,
        neverCalledVerify,
    );

    assert.deepEqual(result, {
        status: 'accepted',
        sessionId: 'ses_1',
        sessionStatus: 'submitted',
        submittedAt: '2026-09-21T00:31:00Z',
        answersRevision: 5,
        verifiedViaSession: false,
    });
});

for (const type of [
    'not_found',
    'not_started',
    'closed',
    'deadline_exceeded',
] as const) {
    test(`a definitive ${type} outcome is returned as-is, without calling verify`, async () => {
        const result = await submitWithVerification(
            async () => ({ type }),
            neverCalledVerify,
        );

        assert.deepEqual(result, { status: type });
    });
}

test('network_error from submit, verified as submitted, is treated as success', async () => {
    const result = await submitWithVerification(
        async () => ({ type: 'network_error' }),
        async () =>
            fakeSession({
                status: 'submitted',
                submittedAt: '2026-09-21T00:31:00Z',
                answersRevision: 6,
            }),
    );

    assert.deepEqual(result, {
        status: 'accepted',
        sessionId: 'ses_1',
        sessionStatus: 'submitted',
        submittedAt: '2026-09-21T00:31:00Z',
        answersRevision: 6,
        verifiedViaSession: true,
    });
});

test('network_error from submit, verified as scored, is also treated as success', async () => {
    const result = await submitWithVerification(
        async () => ({ type: 'network_error' }),
        async () =>
            fakeSession({
                status: 'scored',
                submittedAt: '2026-09-21T00:31:00Z',
            }),
    );

    assert.equal(result.status, 'accepted');
    assert.equal(
        result.status === 'accepted' && result.sessionStatus,
        'scored',
    );
});

test('a rejected/thrown send() is treated identically to an explicit network_error outcome', async () => {
    const result = await submitWithVerification(
        async () => {
            throw new Error('fetch failed');
        },
        async () =>
            fakeSession({
                status: 'submitted',
                submittedAt: '2026-09-21T00:31:00Z',
            }),
    );

    assert.equal(result.status, 'accepted');
});

test('network_error from submit, verified as genuinely still in_progress, is a real failure (safe to retry)', async () => {
    const result = await submitWithVerification(
        async () => ({ type: 'network_error' }),
        async () => fakeSession({ status: 'in_progress' }),
    );

    assert.deepEqual(result, { status: 'network_error' });
});

test('network_error from submit, verified as expired, is reported as deadline_exceeded (server status as-is, no client guess)', async () => {
    const result = await submitWithVerification(
        async () => ({ type: 'network_error' }),
        async () => fakeSession({ status: 'expired' }),
    );

    assert.deepEqual(result, { status: 'deadline_exceeded' });
});

test('network_error from submit, verify() itself also fails, stays an unresolved network_error', async () => {
    const result = await submitWithVerification(
        async () => ({ type: 'network_error' }),
        async () => {
            throw new Error('verify also failed');
        },
    );

    assert.deepEqual(result, { status: 'network_error' });
});
