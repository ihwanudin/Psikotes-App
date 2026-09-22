import assert from 'node:assert/strict';
import { test } from 'node:test';

import type { GenericItemsOutcome } from '../session-runner/http-transport.ts';
import { createIstItemsLoader } from './ist-items-loader.ts';
import type { IstItemsLoaderState } from './ist-items-loader.ts';

function collect(loader: {
    subscribe: (listener: (state: IstItemsLoaderState) => void) => () => void;
}): IstItemsLoaderState[] {
    const states: IstItemsLoaderState[] = [];
    loader.subscribe((state) => states.push(state));

    return states;
}

test('createIstItemsLoader maps an available outcome to IST subtest content via the shared retry core', async () => {
    // Cast rationale: see ist-items.test.ts's identical cast — the
    // generic transport type doesn't model per-instrument wire fields.
    const rawSubtests = [
        {
            code: 'SE',
            answer_type: 'multiple_choice',
            instructions: 'x',
            items: [
                {
                    item: 1,
                    text: 'x',
                    options: { a: '1', b: '2', c: '3', d: '4', e: '5' },
                },
            ],
        },
    ];
    const outcome: GenericItemsOutcome = {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'ist',
            version: 'v1',
            instructions: null,
            subtests: rawSubtests as unknown as {
                code: string;
                items: unknown[];
            }[],
        },
    };
    const loader = createIstItemsLoader({
        fetchItems: async () => outcome,
        queueRetry: () => () => {},
    });
    const states = collect(loader);

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    const last = states.at(-1);
    assert.equal(last?.status, 'ready');

    if (last?.status !== 'ready') {
        return;
    }

    assert.equal(last.outcome.type, 'available');

    if (last.outcome.type !== 'available') {
        return;
    }

    assert.equal(last.outcome.subtests[0]!.code, 'SE');
});

test('createIstItemsLoader treats a network_error outcome as retryable (reconnecting), not final', async () => {
    const captured: { queued: (() => void) | null } = { queued: null };
    let attempts = 0;
    const loader = createIstItemsLoader({
        fetchItems: async () => {
            attempts++;

            return attempts === 1
                ? { type: 'network_error' }
                : {
                      type: 'available',
                      content: {
                          sessionId: 'ses_1',
                          instrument: 'ist',
                          version: 'v1',
                          instructions: null,
                          subtests: [],
                      },
                  };
        },
        queueRetry: (retry) => {
            captured.queued = retry;

            return () => {};
        },
    });
    const states = collect(loader);

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(states.at(-1)?.status, 'reconnecting');

    captured.queued?.();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(states.at(-1)?.status, 'ready');
});

test('createIstItemsLoader treats content_unavailable as final, never auto-retried', async () => {
    let attempts = 0;
    const loader = createIstItemsLoader({
        fetchItems: async () => {
            attempts++;

            return { type: 'content_unavailable' };
        },
        queueRetry: () => () => {},
    });
    const states = collect(loader);

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(states.at(-1)?.status, 'ready');
    assert.deepEqual(states.at(-1), {
        status: 'ready',
        outcome: { type: 'content_unavailable' },
    });
    assert.equal(attempts, 1);
});
