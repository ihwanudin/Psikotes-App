param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$')]
    [string] $DatabaseContainer,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-z][a-z0-9_]{0,62}$')]
    [string] $DatabaseName,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$')]
    [string] $ObservedAt
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Assert-NativeSuccess([string] $operation) {
    if ($LASTEXITCODE -ne 0) {
        throw "$operation failed."
    }
}

$sql = @"
BEGIN READ ONLY;
WITH params AS (
    SELECT '$ObservedAt'::timestamptz AS observed_at
), queue_names(name) AS (
    VALUES ('default'), ('integrations'), ('notifications')
), outbox_names(name) AS (
    VALUES ('generic-result-callbacks'), ('participant-notifications')
), queue_source AS (
    SELECT q.name, i.state, i.attempts, i.available_at, i.updated_at, i.lease_expires_at
    FROM queue_names q
    LEFT JOIN f9_o1_queue_items i ON i.queue_name = q.name
), queue_aggregates AS (
    SELECT
        name,
        count(*) FILTER (WHERE state IN ('pending', 'processing', 'failed'))::int AS depth,
        count(*) FILTER (
            WHERE available_at <= (SELECT observed_at FROM params)
              AND (state = 'pending' OR (state = 'failed' AND attempts < 5))
        )::int AS eligible_count,
        count(*) FILTER (WHERE state = 'processing')::int AS processing_count,
        count(*) FILTER (
            WHERE state = 'processing'
              AND lease_expires_at <= (SELECT observed_at FROM params)
              AND extract(epoch from ((SELECT observed_at FROM params) - updated_at)) >= 600
        )::int AS stale_lease_count,
        count(*) FILTER (WHERE state = 'failed' AND attempts < 5)::int AS retry_count,
        count(*) FILTER (WHERE state = 'failed' AND attempts >= 5)::int AS terminal_count,
        max(
            floor(extract(epoch from ((SELECT observed_at FROM params) - available_at)))
        ) FILTER (
            WHERE available_at <= (SELECT observed_at FROM params)
              AND (state = 'pending' OR (state = 'failed' AND attempts < 5))
        )::int AS oldest_eligible_age_seconds
    FROM queue_source
    GROUP BY name
), outbox_source AS (
    SELECT o.name, i.state, i.attempts, i.available_at, i.updated_at, i.lease_expires_at
    FROM outbox_names o
    LEFT JOIN f9_o1_outbox_items i ON i.outbox_name = o.name
), outbox_aggregates AS (
    SELECT
        name,
        count(*) FILTER (WHERE state = 'pending')::int AS pending_count,
        count(*) FILTER (WHERE state = 'processing')::int AS processing_count,
        count(*) FILTER (WHERE state = 'retryable' AND attempts < 5)::int AS retry_count,
        count(*) FILTER (WHERE state = 'terminal' OR (state = 'retryable' AND attempts >= 5))::int AS terminal_count,
        count(*) FILTER (
            WHERE state = 'processing'
              AND lease_expires_at <= (SELECT observed_at FROM params)
              AND extract(epoch from ((SELECT observed_at FROM params) - updated_at)) >= 600
        )::int AS stale_lease_count,
        max(
            floor(extract(epoch from ((SELECT observed_at FROM params) - available_at)))
        ) FILTER (
            WHERE available_at <= (SELECT observed_at FROM params)
              AND state IN ('pending', 'retryable')
        )::int AS oldest_eligible_age_seconds
    FROM outbox_source
    GROUP BY name
)
SELECT jsonb_build_object(
    'schema', 'oncam.f9-o1.queue-outbox-visibility.v1',
    'observedAt', '$ObservedAt',
    'reportOnly', true,
    'identifiersExcluded', true,
    'queueStates', jsonb_build_array('pending', 'processing', 'processed', 'failed'),
    'outboxStates', jsonb_build_array('pending', 'processing', 'delivered', 'retryable', 'terminal'),
    'queues', (
        SELECT jsonb_agg(jsonb_build_object(
            'name', name,
            'depth', depth,
            'eligibleCount', eligible_count,
            'processingCount', processing_count,
            'staleLeaseCount', stale_lease_count,
            'retryCount', retry_count,
            'terminalCount', terminal_count,
            'oldestEligibleAgeSeconds', oldest_eligible_age_seconds
        ) ORDER BY name)
        FROM queue_aggregates
    ),
    'outboxes', (
        SELECT jsonb_agg(jsonb_build_object(
            'name', name,
            'pendingCount', pending_count,
            'processingCount', processing_count,
            'staleLeaseCount', stale_lease_count,
            'retryCount', retry_count,
            'terminalCount', terminal_count,
            'oldestEligibleAgeSeconds', oldest_eligible_age_seconds
        ) ORDER BY name)
        FROM outbox_aggregates
    )
)::text;
COMMIT;
"@

$output = $sql | docker exec --interactive $DatabaseContainer psql `
    --host 127.0.0.1 `
    --username f9_o1_owner `
    --dbname $DatabaseName `
    --no-psqlrc `
    --quiet `
    --tuples-only `
    --no-align `
    --set ON_ERROR_STOP=1
Assert-NativeSuccess 'Read-only queue/outbox visibility query'

$jsonLine = @($output | ForEach-Object { [string] $_ } | Where-Object { $_.Trim().StartsWith('{') })[0]
if (-not $jsonLine) {
    throw 'Queue/outbox visibility query returned no complete JSON snapshot.'
}

$jsonLine
