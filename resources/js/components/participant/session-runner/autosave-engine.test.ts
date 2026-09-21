import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createAutosaveEngine } from './autosave-engine.ts';
import type { AutosaveBatch, AutosaveSendOutcome } from './autosave-engine.ts';

function idGenerator(prefix = 'id'): () => string {
    let n = 0;

    return () => `${prefix}-${++n}`;
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (error: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

test('flush with nothing pending is a no-op and never calls send', async () => {
    const calls: AutosaveBatch[] = [];
    const engine = createAutosaveEngine({
        initialRevision: 0,
        createMutationId: idGenerator(),
        send: async (batch) => {
            calls.push(batch);

            return {
                type: 'accepted',
                revision: 1,
                acceptedItemNos: [],
            };
        },
    });

    const result = await engine.flush();

    assert.deepEqual(result, { status: 'skipped' });
    assert.equal(calls.length, 0);
});

test('accepted flush advances revision and clears pending changes', async () => {
    const calls: AutosaveBatch[] = [];
    const engine = createAutosaveEngine({
        initialRevision: 6,
        createMutationId: idGenerator(),
        send: async (batch) => {
            calls.push(batch);

            return {
                type: 'accepted',
                revision: batch.revision,
                acceptedItemNos: batch.items.map((item) => item.itemNo),
            };
        },
    });

    engine.queueChange(12, 'A');
    engine.queueChange(13, { rank: 3 });
    const result = await engine.flush();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].revision, 7);
    assert.equal(calls[0].mutationId, 'id-1');
    assert.deepEqual(
        calls[0].items.sort((a, b) => a.itemNo - b.itemNo),
        [
            { itemNo: 12, value: 'A' },
            { itemNo: 13, value: { rank: 3 } },
        ],
    );
    assert.equal(result.status, 'accepted');
    assert.equal(engine.getRevision(), 7);
    assert.equal(engine.hasPendingChanges(), false);
});

test('a second flush while one is in flight does not send twice', async () => {
    const calls: AutosaveBatch[] = [];
    const gate = deferred<AutosaveSendOutcome>();
    const engine = createAutosaveEngine({
        initialRevision: 0,
        createMutationId: idGenerator(),
        send: async (batch) => {
            calls.push(batch);

            return gate.promise;
        },
    });

    engine.queueChange(1, 'x');
    const firstFlush = engine.flush();

    assert.equal(engine.isFlushing(), true);
    const secondResult = await engine.flush();
    assert.deepEqual(secondResult, { status: 'in_flight' });
    assert.equal(calls.length, 1, 'send must be called exactly once');

    gate.resolve({
        type: 'accepted',
        revision: 1,
        acceptedItemNos: [1],
    });
    await firstFlush;
});

test('changes queued while a batch is in flight are not merged into that batch', async () => {
    const calls: AutosaveBatch[] = [];
    const gate = deferred<AutosaveSendOutcome>();
    const engine = createAutosaveEngine({
        initialRevision: 0,
        createMutationId: idGenerator(),
        send: async (batch) => {
            calls.push(batch);

            return gate.promise;
        },
    });

    engine.queueChange(1, 'first');
    const firstFlush = engine.flush();
    engine.queueChange(2, 'second'); // arrives while the first is in flight

    gate.resolve({
        type: 'accepted',
        revision: 1,
        acceptedItemNos: [1],
    });
    await firstFlush;

    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0].items, [{ itemNo: 1, value: 'first' }]);
    assert.equal(
        engine.hasPendingChanges(),
        true,
        'item 2 must still be pending for the next flush',
    );

    const secondResult = await engine.flush();
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[1].items, [{ itemNo: 2, value: 'second' }]);
    assert.equal(
        calls[1].mutationId,
        'id-2',
        'a genuinely new batch gets a new mutation id',
    );
    assert.equal(secondResult.status, 'accepted');
});

test('a network error retries the identical mutation_id, revision, and items', async () => {
    const calls: AutosaveBatch[] = [];
    let attempt = 0;
    const engine = createAutosaveEngine({
        initialRevision: 4,
        createMutationId: idGenerator(),
        send: async (batch) => {
            calls.push(batch);
            attempt++;

            if (attempt === 1) {
                throw new Error('network down');
            }

            return {
                type: 'accepted',
                revision: batch.revision,
                acceptedItemNos: batch.items.map((i) => i.itemNo),
            };
        },
    });

    engine.queueChange(9, 'v1');
    const first = await engine.flush();
    assert.deepEqual(first, { status: 'network_error' });

    // No new local edits between the failure and the retry.
    const second = await engine.flush();
    assert.equal(second.status, 'accepted');

    assert.equal(calls.length, 2);
    assert.equal(calls[0].mutationId, calls[1].mutationId);
    assert.equal(calls[0].revision, calls[1].revision);
    assert.deepEqual(calls[0].items, calls[1].items);
});

test('send() rejecting is treated the same as an explicit network_error outcome', async () => {
    const engine = createAutosaveEngine({
        initialRevision: 0,
        createMutationId: idGenerator(),
        send: async () => {
            throw new Error('fetch failed');
        },
    });

    engine.queueChange(1, 'x');
    const result = await engine.flush();

    assert.deepEqual(result, { status: 'network_error' });
});

test('stale_revision sets needsReload, requeues the batch, and blocks further local edits', async () => {
    const engine = createAutosaveEngine({
        initialRevision: 2,
        createMutationId: idGenerator(),
        send: async () => ({ type: 'stale_revision' }),
    });

    engine.queueChange(5, 'v');
    const result = await engine.flush();

    assert.deepEqual(result, { status: 'stale_revision' });
    assert.equal(engine.needsReload(), true);
    assert.equal(
        engine.hasPendingChanges(),
        true,
        'the rejected batch must not be silently dropped',
    );
    assert.throws(() => engine.queueChange(6, 'other'), /needs reload/);
});

test('revision_gap has the same requeue-and-block behavior as stale_revision', async () => {
    const engine = createAutosaveEngine({
        initialRevision: 2,
        createMutationId: idGenerator(),
        send: async () => ({ type: 'revision_gap' }),
    });

    engine.queueChange(5, 'v');
    const result = await engine.flush();

    assert.deepEqual(result, { status: 'revision_gap' });
    assert.equal(engine.needsReload(), true);
    assert.equal(engine.hasPendingChanges(), true);
});

test('payload_mismatch sets needsReload without retrying the same identity', async () => {
    const calls: AutosaveBatch[] = [];
    const engine = createAutosaveEngine({
        initialRevision: 0,
        createMutationId: idGenerator(),
        send: async (batch) => {
            calls.push(batch);

            return { type: 'payload_mismatch' };
        },
    });

    engine.queueChange(1, 'v');
    await engine.flush();

    assert.equal(engine.needsReload(), true);
    // A caller that (incorrectly) called flush() again before reload()
    // would get needs_reload, not a second send with the same identity.
    const again = await engine.flush();
    assert.deepEqual(again, { status: 'needs_reload' });
    assert.equal(calls.length, 1);
});

test('session_closed and deadline_exceeded surface without setting needsReload', async () => {
    for (const type of ['session_closed', 'deadline_exceeded'] as const) {
        const engine = createAutosaveEngine({
            initialRevision: 0,
            createMutationId: idGenerator(),
            send: async () => ({ type }),
        });

        engine.queueChange(1, 'v');
        const result = await engine.flush();

        assert.equal(result.status, type);
        assert.equal(
            engine.needsReload(),
            false,
            `${type} is a terminal outcome, not a desync — reload() would be the wrong recovery`,
        );
    }
});

test('not_found and not_started are terminal: no requeue, no retry, and further edits are blocked', async () => {
    for (const type of ['not_found', 'not_started'] as const) {
        const calls: AutosaveBatch[] = [];
        const engine = createAutosaveEngine({
            initialRevision: 0,
            createMutationId: idGenerator(),
            send: async (batch) => {
                calls.push(batch);

                return { type };
            },
        });

        engine.queueChange(1, 'v');
        const result = await engine.flush();

        assert.equal(result.status, type);
        assert.equal(
            engine.isTerminal(),
            true,
            `${type} must mark the engine terminal`,
        );
        assert.equal(
            engine.hasPendingChanges(),
            false,
            `${type} must not requeue the rejected batch (there is nothing to resend it to)`,
        );
        assert.throws(
            () => engine.queueChange(2, 'other'),
            /terminal/,
            `${type} must block further local edits`,
        );

        // A caller that calls flush() again anyway (e.g. a stray timer)
        // must get the same terminal status without a second network call.
        const again = await engine.flush();
        assert.deepEqual(again, { status: type });
        assert.equal(
            calls.length,
            1,
            `${type} must never be retried automatically`,
        );
    }
});

test('invalid_batch holds the rejected batch without retrying it, but the engine stays usable for new edits', async () => {
    const calls: AutosaveBatch[] = [];
    const engine = createAutosaveEngine({
        initialRevision: 0,
        createMutationId: idGenerator(),
        send: async (batch) => {
            calls.push(batch);

            return { type: 'invalid_batch' };
        },
    });

    engine.queueChange(1, 'bad-value');
    const result = await engine.flush();

    assert.deepEqual(result, { status: 'invalid_batch' });
    assert.equal(
        engine.isTerminal(),
        false,
        'invalid_batch is per-batch, not terminal for the engine',
    );
    assert.equal(engine.needsReload(), false);
    assert.equal(
        engine.hasPendingChanges(),
        false,
        'the rejected batch must not be requeued — resending the identical payload would retry-loop forever',
    );

    // Nothing pending: a stray extra flush() must be a no-op, proving no
    // automatic resend of the same rejected payload.
    const again = await engine.flush();
    assert.deepEqual(again, { status: 'skipped' });
    assert.equal(
        calls.length,
        1,
        'the rejected batch must be sent exactly once, never retried',
    );

    // A genuinely new edit (not a retry of the old one) is still accepted.
    assert.doesNotThrow(() => engine.queueChange(2, 'good-value'));
});

test('reload() restores a fresh revision and clears needsReload without discarding unsent edits', async () => {
    const engine = createAutosaveEngine({
        initialRevision: 2,
        createMutationId: idGenerator(),
        send: async () => ({ type: 'stale_revision' }),
    });

    engine.queueChange(5, 'v');
    await engine.flush();
    assert.equal(engine.needsReload(), true);

    engine.reload(9);

    assert.equal(engine.needsReload(), false);
    assert.equal(engine.getRevision(), 9);
    assert.equal(
        engine.hasPendingChanges(),
        true,
        'item 5 was never confirmed saved and must still be queued after reload',
    );
    // Editing is allowed again post-reload.
    assert.doesNotThrow(() => engine.queueChange(6, 'w'));
});

test('each real (non-retry) batch gets its own mutation_id from the injected generator', async () => {
    const mutationIds: string[] = [];
    const engine = createAutosaveEngine({
        initialRevision: 0,
        createMutationId: idGenerator('mut'),
        send: async (batch) => {
            mutationIds.push(batch.mutationId);

            return {
                type: 'accepted',
                revision: batch.revision,
                acceptedItemNos: batch.items.map((i) => i.itemNo),
            };
        },
    });

    engine.queueChange(1, 'a');
    await engine.flush();
    engine.queueChange(2, 'b');
    await engine.flush();

    assert.deepEqual(mutationIds, ['mut-1', 'mut-2']);
});
