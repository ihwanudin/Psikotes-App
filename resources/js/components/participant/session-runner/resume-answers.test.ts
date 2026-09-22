import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createAutosaveEngine } from './autosave-engine.ts';
import type { AutosaveBatch, AutosaveSendOutcome } from './autosave-engine.ts';
import { initialAutosaveRevisionFromResume } from './resume-answers.ts';

test('an available resume outcome yields its own answers_revision as the initial autosave revision', () => {
    const revision = initialAutosaveRevisionFromResume({
        type: 'available',
        sessionId: 'ses_1',
        answersRevision: 7,
        answers: [{ itemNo: 1, value: 'A' }],
    });

    assert.equal(revision, 7);
});

test('a not_started outcome yields revision 0 — nothing autosaved yet, structurally', () => {
    const revision = initialAutosaveRevisionFromResume({ type: 'not_started' });

    assert.equal(revision, 0);
});

for (const type of [
    'closed',
    'deadline_exceeded',
    'not_found',
    'network_error',
] as const) {
    test(`a ${type} outcome yields no valid revision (null) — no editable UI to autosave against`, () => {
        const revision = initialAutosaveRevisionFromResume({ type });

        assert.equal(revision, null);
    });
}

test('resume -> initial revision from readback -> the first real autosave uses revision + 1', async () => {
    const resumeOutcome: Parameters<
        typeof initialAutosaveRevisionFromResume
    >[0] = {
        type: 'available',
        sessionId: 'ses_1',
        answersRevision: 7,
        answers: [{ itemNo: 1, value: 'A' }],
    };
    const initialRevision = initialAutosaveRevisionFromResume(resumeOutcome);
    assert.equal(initialRevision, 7);

    const sentBatches: AutosaveBatch[] = [];
    const engine = createAutosaveEngine({
        initialRevision: initialRevision!,
        send: async (batch): Promise<AutosaveSendOutcome> => {
            sentBatches.push(batch);

            return {
                type: 'accepted',
                revision: batch.revision,
                acceptedItemNos: batch.items.map((item) => item.itemNo),
            };
        },
        createMutationId: () => 'mut_1',
    });

    engine.queueChange(2, 'B');
    const result = await engine.flush();

    assert.equal(sentBatches.length, 1);
    assert.equal(
        sentBatches[0]?.revision,
        8,
        'the first autosave after resuming at revision 7 must propose exactly 7 + 1',
    );
    assert.equal(result.status, 'accepted');
    assert.equal(engine.getRevision(), 8);
});
