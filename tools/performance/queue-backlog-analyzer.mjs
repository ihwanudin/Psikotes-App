/**
 * Pure, report-only analyzer for caller-supplied synthetic queue snapshots.
 *
 * The module has no database, queue, worker, network, or filesystem adapter.
 * Tenant, topic, message identifiers, and error/body data are never returned.
 */

import { Buffer } from 'node:buffer';

const MAX_DOCUMENT_BYTES = 1024 * 1024;
const MAX_ITEMS = 10_000;
const MAX_QUEUES = 32;
const MAX_IDENTIFIER_BYTES = 128;
const MAX_ATTEMPTS = 65_535;
const MAX_THRESHOLD = 31_536_000;
const IDENTIFIER = /^[a-z0-9](?:[a-z0-9._:-]{0,126}[a-z0-9])?$/;
const TIMESTAMP = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const STATUSES = new Set(['pending', 'processing', 'processed', 'failed']);
const SNAPSHOT_KEYS = ['items', 'tenant', 'topic', 'version'];
const ITEM_KEYS = [
    'attempts',
    'availableAt',
    'id',
    'leaseExpiresAt',
    'queue',
    'status',
    'updatedAt',
];
const POLICY_KEYS = ['queues', 'version'];
const QUEUE_POLICY_KEYS = [
    'maxDepth',
    'maxOldestEligibleAgeSeconds',
    'maxRetries',
    'maxStaleLeases',
    'maxTerminal',
    'name',
    'retryAttemptLimit',
    'staleLeaseAgeSeconds',
];
const REQUEST_KEYS = [
    'expectedTenant',
    'expectedTopic',
    'observedAt',
    'policyBytes',
    'snapshotBytes',
];

export class QueueBacklogRefused extends Error {
    constructor() {
        super('queue_backlog');
        this.name = 'QueueBacklogRefused';
    }
}

function refuse() {
    throw new QueueBacklogRefused();
}

function exactKeys(value, keys) {
    if (
        value === null ||
        Array.isArray(value) ||
        Object.getPrototypeOf(value) !== Object.prototype ||
        JSON.stringify(Object.keys(value).sort()) !== JSON.stringify(keys)
    ) {
        refuse();
    }
}

function canonicalValue(value) {
    if (Array.isArray(value)) {
        return value.map(canonicalValue);
    }

    if (value !== null && typeof value === 'object') {
        if (Object.getPrototypeOf(value) !== Object.prototype) {
            refuse();
        }

        return Object.fromEntries(
            Object.keys(value)
                .sort()
                .map((key) => [key, canonicalValue(value[key])]),
        );
    }

    return value;
}

function decode(bytes) {
    if (
        !Buffer.isBuffer(bytes) ||
        bytes.length < 3 ||
        bytes.length > MAX_DOCUMENT_BYTES
    ) {
        refuse();
    }

    for (const byte of bytes) {
        if (byte > 0x7f || byte === 0) {
            refuse();
        }
    }

    let value;

    try {
        const raw = bytes.toString('ascii');

        if (!raw.endsWith('\n') || raw.slice(0, -1).includes('\n')) {
            refuse();
        }

        value = JSON.parse(raw.slice(0, -1));
        const canonical = `${JSON.stringify(canonicalValue(value))}\n`;

        if (canonical !== raw) {
            refuse();
        }
    } catch (error) {
        if (error instanceof QueueBacklogRefused) {
            throw error;
        }

        refuse();
    }

    return value;
}

function exactInteger(value, minimum, maximum) {
    if (!Number.isInteger(value) || value < minimum || value > maximum) {
        refuse();
    }

    return value;
}

function identifier(value) {
    if (
        typeof value !== 'string' ||
        Buffer.byteLength(value, 'ascii') !== value.length ||
        value.length < 1 ||
        value.length > MAX_IDENTIFIER_BYTES ||
        !IDENTIFIER.test(value)
    ) {
        refuse();
    }

    return value;
}

function instant(value) {
    if (typeof value !== 'string' || !TIMESTAMP.test(value)) {
        refuse();
    }

    const milliseconds = Date.parse(value);

    if (
        !Number.isFinite(milliseconds) ||
        new Date(milliseconds).toISOString().replace('.000Z', 'Z') !== value
    ) {
        refuse();
    }

    return milliseconds;
}

function parsePolicy(bytes) {
    const value = decode(bytes);
    exactKeys(value, POLICY_KEYS);

    if (value.version !== 1 || !Array.isArray(value.queues)) {
        refuse();
    }

    if (value.queues.length < 1 || value.queues.length > MAX_QUEUES) {
        refuse();
    }

    const names = new Set();
    let previous = null;
    const queues = value.queues.map((entry) => {
        exactKeys(entry, QUEUE_POLICY_KEYS);
        const name = identifier(entry.name);

        if (names.has(name) || (previous !== null && previous >= name)) {
            refuse();
        }

        names.add(name);
        previous = name;

        return Object.freeze({
            name,
            maxDepth: exactInteger(entry.maxDepth, 0, MAX_ITEMS),
            maxOldestEligibleAgeSeconds: exactInteger(
                entry.maxOldestEligibleAgeSeconds,
                0,
                MAX_THRESHOLD,
            ),
            maxStaleLeases: exactInteger(entry.maxStaleLeases, 0, MAX_ITEMS),
            maxRetries: exactInteger(entry.maxRetries, 0, MAX_ITEMS),
            maxTerminal: exactInteger(entry.maxTerminal, 0, MAX_ITEMS),
            retryAttemptLimit: exactInteger(
                entry.retryAttemptLimit,
                1,
                MAX_ATTEMPTS,
            ),
            staleLeaseAgeSeconds: exactInteger(
                entry.staleLeaseAgeSeconds,
                1,
                MAX_THRESHOLD,
            ),
        });
    });

    return Object.freeze(queues);
}

function parseSnapshot(
    bytes,
    queues,
    expectedTenant,
    expectedTopic,
    observedAt,
) {
    const value = decode(bytes);
    exactKeys(value, SNAPSHOT_KEYS);

    if (
        value.version !== 1 ||
        !Array.isArray(value.items) ||
        value.items.length > MAX_ITEMS
    ) {
        refuse();
    }

    const tenant = identifier(value.tenant);
    const topic = identifier(value.topic);

    if (tenant !== expectedTenant || topic !== expectedTopic) {
        refuse();
    }

    const queueNames = new Set(queues.map(({ name }) => name));
    const ids = new Set();
    const items = value.items.map((entry) => {
        exactKeys(entry, ITEM_KEYS);
        const id = identifier(entry.id);
        const queue = identifier(entry.queue);

        if (
            ids.has(id) ||
            !queueNames.has(queue) ||
            !STATUSES.has(entry.status)
        ) {
            refuse();
        }

        ids.add(id);
        const attempts = exactInteger(entry.attempts, 0, MAX_ATTEMPTS);
        const availableAt = instant(entry.availableAt);
        const updatedAt = instant(entry.updatedAt);
        const leaseExpiresAt =
            entry.leaseExpiresAt === null
                ? null
                : instant(entry.leaseExpiresAt);

        if (updatedAt > observedAt) {
            refuse();
        }

        if (entry.status === 'processing') {
            if (
                leaseExpiresAt === null ||
                attempts < 1 ||
                availableAt > updatedAt ||
                leaseExpiresAt <= updatedAt
            ) {
                refuse();
            }
        } else if (leaseExpiresAt !== null) {
            refuse();
        }

        if (entry.status === 'pending' && attempts !== 0) {
            refuse();
        }

        if (
            (entry.status === 'failed' || entry.status === 'processed') &&
            attempts < 1
        ) {
            refuse();
        }

        return Object.freeze({
            queue,
            status: entry.status,
            attempts,
            availableAt,
            updatedAt,
            leaseExpiresAt,
        });
    });

    return Object.freeze({ tenant, topic, items: Object.freeze(items) });
}

function queueReport(policy, items, observedAt) {
    const selected = items.filter(({ queue }) => queue === policy.name);
    const outstanding = selected.filter(({ status }) => status !== 'processed');
    const eligible = selected.filter(
        ({ status, attempts, availableAt }) =>
            availableAt <= observedAt &&
            (status === 'pending' ||
                (status === 'failed' && attempts < policy.retryAttemptLimit)),
    );
    const leased = selected.filter(({ status }) => status === 'processing');
    const stale = leased.filter(
        ({ leaseExpiresAt, updatedAt }) =>
            leaseExpiresAt <= observedAt &&
            Math.floor((observedAt - updatedAt) / 1000) >=
                policy.staleLeaseAgeSeconds,
    );
    const retries = selected.filter(
        ({ status, attempts }) =>
            status === 'failed' && attempts < policy.retryAttemptLimit,
    );
    const terminal = selected.filter(
        ({ status, attempts }) =>
            status === 'failed' && attempts >= policy.retryAttemptLimit,
    );
    const oldestEligibleAgeSeconds =
        eligible.length === 0
            ? null
            : Math.max(
                  ...eligible.map(({ availableAt }) =>
                      Math.floor((observedAt - availableAt) / 1000),
                  ),
              );
    const breachedMetrics = [];

    if (outstanding.length > policy.maxDepth) {
        breachedMetrics.push('depth');
    }

    if (
        oldestEligibleAgeSeconds !== null &&
        oldestEligibleAgeSeconds > policy.maxOldestEligibleAgeSeconds
    ) {
        breachedMetrics.push('oldestEligibleAgeSeconds');
    }

    if (stale.length > policy.maxStaleLeases) {
        breachedMetrics.push('staleLeaseCount');
    }

    if (retries.length > policy.maxRetries) {
        breachedMetrics.push('retryCount');
    }

    if (terminal.length > policy.maxTerminal) {
        breachedMetrics.push('terminalCount');
    }

    return Object.freeze({
        queue: policy.name,
        depth: outstanding.length,
        eligibleCount: eligible.length,
        leasedCount: leased.length,
        staleLeaseCount: stale.length,
        retryCount: retries.length,
        terminalCount: terminal.length,
        oldestEligibleAgeSeconds,
        sloStatus: breachedMetrics.length === 0 ? 'ok' : 'breach',
        breachedMetrics: Object.freeze(breachedMetrics),
    });
}

export function analyzeQueueBacklog(options) {
    try {
        exactKeys(options, REQUEST_KEYS);
        const {
            snapshotBytes,
            policyBytes,
            expectedTenant,
            expectedTopic,
            observedAt,
        } = options;
        const tenant = identifier(expectedTenant);
        const topic = identifier(expectedTopic);
        const observedAtMilliseconds = instant(observedAt);
        const policies = parsePolicy(policyBytes);
        const snapshot = parseSnapshot(
            snapshotBytes,
            policies,
            tenant,
            topic,
            observedAtMilliseconds,
        );
        const queues = Object.freeze(
            policies.map((policy) =>
                queueReport(policy, snapshot.items, observedAtMilliseconds),
            ),
        );

        return Object.freeze({
            version: 1,
            reportOnly: true,
            identifiersExcluded: true,
            observedAt,
            overallStatus: queues.some(
                ({ sloStatus }) => sloStatus === 'breach',
            )
                ? 'breach'
                : 'ok',
            queues,
        });
    } catch (error) {
        if (error instanceof QueueBacklogRefused) {
            throw error;
        }

        refuse();
    }
}
