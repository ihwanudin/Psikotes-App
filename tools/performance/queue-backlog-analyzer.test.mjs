import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { test } from 'node:test';

import {
    QueueBacklogRefused,
    analyzeQueueBacklog,
} from './queue-backlog-analyzer.mjs';

const now = '2026-09-07T12:00:00Z';

function policy(overrides = {}) {
    return {
        version: 1,
        queues: [
            {
                name: 'notifications',
                maxDepth: 4,
                maxOldestEligibleAgeSeconds: 300,
                maxStaleLeases: 0,
                maxRetries: 1,
                maxTerminal: 0,
                retryAttemptLimit: 5,
                staleLeaseAgeSeconds: 600,
                ...overrides,
            },
        ],
    };
}

function item(id, overrides = {}) {
    return {
        id,
        queue: 'notifications',
        status: 'pending',
        attempts: 0,
        availableAt: '2026-09-07T11:59:00Z',
        updatedAt: '2026-09-07T11:59:00Z',
        leaseExpiresAt: null,
        ...overrides,
    };
}

function snapshot(items, overrides = {}) {
    return {
        version: 1,
        tenant: 'tenant-synthetic-01',
        topic: 'participant.activation',
        items,
        ...overrides,
    };
}

function canonical(value) {
    const normalize = (candidate) => {
        if (Array.isArray(candidate)) {
            return candidate.map(normalize);
        }

        if (candidate !== null && typeof candidate === 'object') {
            return Object.fromEntries(
                Object.keys(candidate)
                    .sort()
                    .map((key) => [key, normalize(candidate[key])]),
            );
        }

        return candidate;
    };

    return Buffer.from(`${JSON.stringify(normalize(value))}\n`, 'ascii');
}

function analyze(items, thresholdOverrides = {}, snapshotOverrides = {}) {
    return analyzeQueueBacklog({
        snapshotBytes: canonical(snapshot(items, snapshotOverrides)),
        policyBytes: canonical(policy(thresholdOverrides)),
        expectedTenant: 'tenant-synthetic-01',
        expectedTopic: 'participant.activation',
        observedAt: now,
    });
}

function refused(run) {
    assert.throws(run, (error) => {
        assert.equal(error.constructor, QueueBacklogRefused);
        assert.equal(error.message, 'queue_backlog');

        return true;
    });
}

test('reports deterministic per-queue backlog metrics and SLO breaches', () => {
    const report = analyze([
        item('msg-0001', { availableAt: '2026-09-07T11:50:00Z' }),
        item('msg-0002', {
            status: 'failed',
            attempts: 2,
            availableAt: '2026-09-07T11:58:00Z',
        }),
        item('msg-0003', {
            status: 'processing',
            attempts: 1,
            availableAt: '2026-09-07T11:40:00Z',
            updatedAt: '2026-09-07T11:40:00Z',
            leaseExpiresAt: '2026-09-07T11:55:00Z',
        }),
        item('msg-0004', {
            status: 'failed',
            attempts: 5,
            availableAt: '2026-09-07T11:57:00Z',
            updatedAt: '2026-09-07T11:57:00Z',
        }),
    ]);

    assert.deepEqual(report, {
        version: 1,
        reportOnly: true,
        redacted: true,
        observedAt: now,
        scopeDigest:
            'a40215ee1fcb0961c7b9deb4225e9b41fc5f6da3d6543c96b585c303dd36bea1',
        overallStatus: 'breach',
        queues: [
            {
                queue: 'notifications',
                depth: 4,
                eligibleCount: 2,
                leasedCount: 1,
                staleLeaseCount: 1,
                retryCount: 1,
                terminalCount: 1,
                oldestEligibleAgeSeconds: 600,
                sloStatus: 'breach',
                breachedMetrics: [
                    'oldestEligibleAgeSeconds',
                    'staleLeaseCount',
                    'terminalCount',
                ],
            },
        ],
    });
    assert.equal(Object.isFrozen(report), true);
    assert.equal(Object.isFrozen(report.queues), true);
    assert.equal(Object.isFrozen(report.queues[0]), true);
    assert.equal(Object.isFrozen(report.queues[0].breachedMetrics), true);
    assert.equal(JSON.stringify(report).includes('tenant-synthetic-01'), false);
    assert.equal(
        JSON.stringify(report).includes('participant.activation'),
        false,
    );
    assert.equal(JSON.stringify(report).includes('msg-0001'), false);
});

test('uses deterministic clock and exact inclusive SLO boundaries', () => {
    const report = analyze(
        [
            item('msg-0001', { availableAt: '2026-09-07T11:55:00Z' }),
            item('msg-0002', {
                status: 'processing',
                attempts: 1,
                updatedAt: '2026-09-07T11:50:00Z',
                leaseExpiresAt: '2026-09-07T12:00:00Z',
            }),
        ],
        { maxOldestEligibleAgeSeconds: 300, maxStaleLeases: 1 },
    );

    assert.equal(report.overallStatus, 'ok');
    assert.equal(report.queues[0].oldestEligibleAgeSeconds, 300);
    assert.equal(report.queues[0].staleLeaseCount, 1);
    assert.deepEqual(report.queues[0].breachedMetrics, []);
});

test('orders multiple allowlisted queues and emits zero rows deterministically', () => {
    const report = analyzeQueueBacklog({
        snapshotBytes: canonical(snapshot([item('msg-0001')], {})),
        policyBytes: canonical({
            version: 1,
            queues: [
                { ...policy().queues[0], name: 'billing' },
                policy().queues[0],
            ],
        }),
        expectedTenant: 'tenant-synthetic-01',
        expectedTopic: 'participant.activation',
        observedAt: now,
    });

    assert.deepEqual(
        report.queues.map(({ queue }) => queue),
        ['billing', 'notifications'],
    );
    assert.equal(report.queues[0].depth, 0);
    assert.equal(report.queues[0].oldestEligibleAgeSeconds, null);
});

test('rejects duplicate ids and duplicate JSON keys', () => {
    refused(() => analyze([item('msg-0001'), item('msg-0001')]));
    const duplicateKey = Buffer.from(
        '{"version":1,"tenant":"tenant-synthetic-01","topic":"participant.activation","items":[],"items":[]}\n',
        'ascii',
    );
    refused(() =>
        analyzeQueueBacklog({
            snapshotBytes: duplicateKey,
            policyBytes: canonical(policy()),
            expectedTenant: 'tenant-synthetic-01',
            expectedTopic: 'participant.activation',
            observedAt: now,
        }),
    );
});

test('rejects malformed and noncanonical timestamps', () => {
    for (const availableAt of [
        '2026-09-07 11:59:00Z',
        '2026-09-07T11:59:00+00:00',
        '2026-09-07T11:59:00.000Z',
        '2026-02-30T11:59:00Z',
    ]) {
        refused(() => analyze([item('msg-0001', { availableAt })]));
    }
});

test('rejects negative eligible age and inconsistent state timestamps', () => {
    refused(() =>
        analyze([item('msg-0001', { availableAt: '2026-09-07T12:00:01Z' })]),
    );
    refused(() =>
        analyze([
            item('msg-0001', {
                status: 'processing',
                leaseExpiresAt: null,
            }),
        ]),
    );
    refused(() =>
        analyze([
            item('msg-0001', {
                status: 'pending',
                leaseExpiresAt: '2026-09-07T12:05:00Z',
            }),
        ]),
    );
    refused(() => analyze([item('msg-0001', { attempts: 1 })]));
});

test('rejects unknown queue, status, tenant, and topic', () => {
    refused(() => analyze([item('msg-0001', { queue: 'other' })]));
    refused(() => analyze([item('msg-0001', { status: 'retrying' })]));
    refused(() => analyze([], {}, { tenant: 'other-tenant' }));
    refused(() => analyze([], {}, { topic: 'other.topic' }));
});

test('rejects unbounded inputs and excessive collections', () => {
    const oversized = Buffer.alloc(1024 * 1024 + 1, 0x20);
    refused(() =>
        analyzeQueueBacklog({
            snapshotBytes: oversized,
            policyBytes: canonical(policy()),
            expectedTenant: 'tenant-synthetic-01',
            expectedTopic: 'participant.activation',
            observedAt: now,
        }),
    );

    const queues = Array.from({ length: 33 }, (_, index) => ({
        ...policy().queues[0],
        name: `queue-${String(index).padStart(2, '0')}`,
    }));
    refused(() =>
        analyzeQueueBacklog({
            snapshotBytes: canonical(snapshot([])),
            policyBytes: canonical({ version: 1, queues }),
            expectedTenant: 'tenant-synthetic-01',
            expectedTopic: 'participant.activation',
            observedAt: now,
        }),
    );
});

test('rejects payload fields, loose numbers, noncanonical bytes, and extra keys', () => {
    refused(() =>
        analyze([item('msg-0001', { payload: { email: 'x@y.test' } })]),
    );
    refused(() => analyze([item('msg-0001', { attempts: true })]));
    refused(() =>
        analyzeQueueBacklog({
            snapshotBytes: Buffer.from(JSON.stringify(snapshot([])), 'ascii'),
            policyBytes: canonical(policy()),
            expectedTenant: 'tenant-synthetic-01',
            expectedTopic: 'participant.activation',
            observedAt: now,
        }),
    );
    refused(() =>
        analyzeQueueBacklog({
            snapshotBytes: canonical(snapshot([])),
            policyBytes: canonical({ ...policy(), maxDepth: 10 }),
            expectedTenant: 'tenant-synthetic-01',
            expectedTopic: 'participant.activation',
            observedAt: now,
        }),
    );
    refused(() =>
        analyzeQueueBacklog({
            snapshotBytes: canonical(snapshot([])),
            policyBytes: canonical(policy()),
            expectedTenant: 'tenant-synthetic-01',
            expectedTopic: 'participant.activation',
            observedAt: now,
            extra: true,
        }),
    );
});

test('does not mutate caller bytes and exposes no runtime or outbound surface', async () => {
    const snapshotBytes = canonical(snapshot([item('msg-0001')]));
    const policyBytes = canonical(policy());
    const beforeSnapshot = Buffer.from(snapshotBytes);
    const beforePolicy = Buffer.from(policyBytes);

    analyzeQueueBacklog({
        snapshotBytes,
        policyBytes,
        expectedTenant: 'tenant-synthetic-01',
        expectedTopic: 'participant.activation',
        observedAt: now,
    });

    assert.deepEqual(snapshotBytes, beforeSnapshot);
    assert.deepEqual(policyBytes, beforePolicy);
    const source = await import('node:fs/promises').then(({ readFile }) =>
        readFile(
            new URL('./queue-backlog-analyzer.mjs', import.meta.url),
            'utf8',
        ),
    );

    for (const forbidden of [
        'child_process',
        'node:net',
        'node:http',
        'node:https',
        'redis',
        'payload',
        'last_error',
    ]) {
        assert.equal(source.includes(forbidden), false, forbidden);
    }
});
