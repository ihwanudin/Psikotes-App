param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9a-f]{40}$')]
    [string] $ExpectedCommit
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$runId = [guid]::NewGuid().ToString('N')
$network = "oncam-f9-o1-$runId"
$databaseContainer = "$network-db"
$redisContainer = "$network-redis"
$appContainer = "$network-app"
$label = "oncam.f9-o1-observability=$runId"
$databaseName = 'f9_o1'
$observedAt = '2026-09-18T12:00:00Z'
$tempDirectory = Join-Path ([System.IO.Path]::GetTempPath()) "psikotes-f9-o1-$runId"
$snapshotPath = Join-Path $tempDirectory 'queue-outbox.json'
$networkCreated = $false
$failure = $null

function Assert-NativeSuccess([string] $operation) {
    if ($LASTEXITCODE -ne 0) {
        throw "$operation failed; no production or live resource was targeted."
    }
}

function Wait-PostgresReady {
    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        docker exec $databaseContainer pg_isready --host 127.0.0.1 `
            --username f9_o1_owner --dbname $databaseName | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Disposable PostgreSQL did not become ready.' }
}

function Wait-RedisReady {
    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        docker exec $redisContainer redis-cli ping | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Disposable Redis did not become ready.' }
}

function Invoke-Psql([string] $sql) {
    $output = $sql | docker exec --interactive $databaseContainer psql `
        --host 127.0.0.1 `
        --username f9_o1_owner `
        --dbname $databaseName `
        --no-psqlrc `
        --tuples-only `
        --no-align `
        --set ON_ERROR_STOP=1
    Assert-NativeSuccess 'Disposable PostgreSQL query'

    return (($output | ForEach-Object { [string] $_ }) -join "`n").Trim()
}

function Wait-AppHealthEndpoint {
    $lastStatus = ''
    $lastBody = ''
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        $probe = Invoke-Health 2
        $lastStatus = $probe.Status
        $lastBody = $probe.Body
        if ($probe.Status -cne '200' -or $probe.Body -cne '{"status":"ok"}') {
            Start-Sleep -Milliseconds 500
            continue
        }

        return
    }
    $preview = if ($lastBody.Length -gt 160) { $lastBody.Substring(0, 160) } else { $lastBody }
    throw "Application health endpoint did not become reachable. last_status=$lastStatus last_body_preview=$preview"
}

function Invoke-Health([int] $MaxTimeSeconds = 12) {
    $status = (docker exec $appContainer sh -c "curl --silent --show-error --max-time $MaxTimeSeconds --output /tmp/f9-o1-health-body --write-out '%{http_code}' http://127.0.0.1:8080/health 2>/tmp/f9-o1-health-error || true" | Select-Object -Last 1)
    Assert-NativeSuccess 'Application health probe'
    $body = (docker exec $appContainer sh -c 'if [ -f /tmp/f9-o1-health-body ]; then cat /tmp/f9-o1-health-body; fi') -join ''
    Assert-NativeSuccess 'Application health body read'

    return [pscustomobject]@{ Status = [string] $status; Body = [string] $body }
}

function Assert-Health([pscustomobject] $response, [string] $status, [string] $body, [string] $message) {
    if ($response.Status -cne $status -or $response.Body -cne $body) {
        $preview = if ($response.Body.Length -gt 160) { $response.Body.Substring(0, 160) } else { $response.Body }
        throw "$message status=$($response.Status) body_preview=$preview"
    }
}

try {
    $actualCommit = (git -C $workspace rev-parse HEAD).Trim()
    Assert-NativeSuccess 'Git revision resolution'
    if ($actualCommit -cne $ExpectedCommit) {
        throw "Expected commit $ExpectedCommit does not match current snapshot $actualCommit."
    }
    $dirty = git -C $workspace status --porcelain=v1 --untracked-files=all
    Assert-NativeSuccess 'Git worktree inspection'
    if ($dirty) { throw 'Observability rehearsal requires a clean tracked and untracked worktree.' }

    docker image inspect postgres:17.6-alpine --format '{{.Id}}' | Out-Null
    Assert-NativeSuccess 'PostgreSQL image check'
    docker image inspect redis:8.2-alpine --format '{{.Id}}' | Out-Null
    Assert-NativeSuccess 'Redis image check'
    docker image inspect psikotes-app:dev --format '{{.Id}}' | Out-Null
    Assert-NativeSuccess 'Application image check'

    [void] (New-Item -ItemType Directory -Path $tempDirectory)
    $resolvedTemp = (Resolve-Path -LiteralPath $tempDirectory).Path
    $expectedTempParent = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\')
    $actualTempParent = [System.IO.Directory]::GetParent($resolvedTemp).FullName.TrimEnd('\')
    if ($actualTempParent -cne $expectedTempParent -or $resolvedTemp.StartsWith($workspace, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Task temp directory must remain outside the repository.'
    }

    docker network create --internal --label $label $network | Out-Null
    Assert-NativeSuccess 'Internal rehearsal network creation'
    $networkCreated = $true

    docker run --detach --pull=never --name $databaseContainer --label $label `
        --network $network --network-alias f9-o1-db --tmpfs /var/lib/postgresql/data:rw `
        --env POSTGRES_USER=f9_o1_owner --env POSTGRES_DB=$databaseName `
        --env POSTGRES_HOST_AUTH_METHOD=trust postgres:17.6-alpine | Out-Null
    Assert-NativeSuccess 'Disposable PostgreSQL creation'
    Wait-PostgresReady

    docker run --detach --pull=never --name $redisContainer --label $label `
        --network $network --network-alias f9-o1-redis `
        redis:8.2-alpine | Out-Null
    Assert-NativeSuccess 'Disposable Redis creation'
    Wait-RedisReady

    docker run --detach --pull=never --name $appContainer --label $label `
        --network $network `
        --env APP_ENV=testing `
        --env APP_DEBUG=false `
        --env APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= `
        --env APP_URL=http://127.0.0.1:8080 `
        --env LOG_CHANNEL=stderr `
        --env CACHE_STORE=redis `
        --env SESSION_DRIVER=redis `
        --env QUEUE_CONNECTION=redis `
        --env DB_CONNECTION=pgsql `
        --env DB_HOST=f9-o1-db `
        --env DB_PORT=5432 `
        --env DB_DATABASE=$databaseName `
        --env DB_USERNAME=f9_o1_owner `
        --env DB_PASSWORD= `
        --env REDIS_CLIENT=phpredis `
        --env REDIS_HOST=f9-o1-redis `
        --env REDIS_PORT=6379 `
        --env REDIS_PASSWORD= `
        --env PARTICIPANT_JWT_SECRET=synthetic-f9-o1-participant-secret `
        --env N8N_WEBHOOK_URL= `
        --env N8N_WEBHOOK_TOKEN= `
        --env SELECTION_INTEGRATION_ENABLED=false `
        --env SELECTION_RESULT_CALLBACK_ENABLED=false `
        --env SELECTION_RESULT_CALLBACK_BASE_URL= `
        --env SELECTION_RESULT_CALLBACK_SECRET= `
        --env SELECTION_RESULT_CALLBACK_KEY_ID= `
        --env XENDIT_SECRET_KEY= `
        --env XENDIT_CALLBACK_TOKEN= `
        psikotes-app:dev | Out-Null
    Assert-NativeSuccess 'Disposable application creation'
    Start-Sleep -Seconds 20
    Wait-AppHealthEndpoint

    $healthy = Invoke-Health
    Assert-Health $healthy '200' '{"status":"ok"}' 'Health check returned an unexpected healthy response.'

    docker stop $databaseContainer | Out-Null
    Assert-NativeSuccess 'Disposable PostgreSQL stop'
    $dbDown = Invoke-Health
    Assert-Health $dbDown '503' '{"status":"degraded"}' 'Health dependency failure did not return the exact degraded response.'

    docker start $databaseContainer | Out-Null
    Assert-NativeSuccess 'Disposable PostgreSQL restart'
    Wait-PostgresReady
    docker stop $redisContainer | Out-Null
    Assert-NativeSuccess 'Disposable Redis stop'
    $redisDown = Invoke-Health
    Assert-Health $redisDown '503' '{"status":"degraded"}' 'Health dependency failure did not return the exact degraded response.'

    docker start $redisContainer | Out-Null
    Assert-NativeSuccess 'Disposable Redis restart'
    Start-Sleep -Milliseconds 500

    Invoke-Psql @"
CREATE TABLE f9_o1_queue_items (
    queue_name text NOT NULL,
    state text NOT NULL,
    attempts integer NOT NULL,
    available_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    lease_expires_at timestamptz,
    synthetic_message_id text NOT NULL,
    synthetic_payload text NOT NULL
);
CREATE TABLE f9_o1_outbox_items (
    outbox_name text NOT NULL,
    state text NOT NULL,
    attempts integer NOT NULL,
    available_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    lease_expires_at timestamptz,
    synthetic_message_id text NOT NULL,
    synthetic_payload text NOT NULL
);
INSERT INTO f9_o1_queue_items VALUES
    ('notifications','pending',0,'2026-09-18T11:50:00Z','2026-09-18T11:50:00Z',NULL,'msg-notifications-001','synthetic-secret-participant-id-001'),
    ('notifications','failed',2,'2026-09-18T11:58:00Z','2026-09-18T11:58:00Z',NULL,'msg-notifications-002','synthetic-error-text'),
    ('notifications','processing',1,'2026-09-18T11:40:00Z','2026-09-18T11:40:00Z','2026-09-18T11:55:00Z','msg-notifications-003','synthetic-lease-holder'),
    ('notifications','failed',5,'2026-09-18T11:57:00Z','2026-09-18T11:57:00Z',NULL,'msg-notifications-004','synthetic-terminal'),
    ('default','processed',1,'2026-09-18T11:57:00Z','2026-09-18T11:58:00Z',NULL,'msg-default-001','synthetic-complete'),
    ('integrations','pending',0,'2026-09-18T12:05:00Z','2026-09-18T11:59:00Z',NULL,'msg-integrations-001','synthetic-delayed');
INSERT INTO f9_o1_outbox_items VALUES
    ('participant-notifications','pending',0,'2026-09-18T11:54:00Z','2026-09-18T11:54:00Z',NULL,'outbox-participant-001','synthetic-phone-620000000000'),
    ('participant-notifications','processing',1,'2026-09-18T11:40:00Z','2026-09-18T11:40:00Z','2026-09-18T11:50:00Z','outbox-participant-002','synthetic-provider-body'),
    ('participant-notifications','retryable',3,'2026-09-18T11:52:00Z','2026-09-18T11:52:00Z',NULL,'outbox-participant-003','synthetic-timeout-message'),
    ('participant-notifications','terminal',5,'2026-09-18T11:51:00Z','2026-09-18T11:51:00Z',NULL,'outbox-participant-004','synthetic-last-error'),
    ('generic-result-callbacks','delivered',1,'2026-09-18T11:30:00Z','2026-09-18T11:31:00Z',NULL,'outbox-generic-001','synthetic-callback-ok'),
    ('generic-result-callbacks','retryable',4,'2026-09-18T11:45:00Z','2026-09-18T11:45:00Z',NULL,'outbox-generic-002','synthetic-callback-retry');
"@ | Out-Null

    $collectorPath = Join-Path $workspace 'tools/testing/observability/collect-queue-outbox.ps1'
    $snapshot = & powershell -NoProfile -ExecutionPolicy Bypass -File $collectorPath `
        -DatabaseContainer $databaseContainer `
        -DatabaseName $databaseName `
        -ObservedAt $observedAt
    if ($LASTEXITCODE -ne 0 -or -not $snapshot) {
        throw 'Queue/outbox visibility collection failed before a complete snapshot.'
    }

    Set-Content -LiteralPath $snapshotPath -Value $snapshot -NoNewline -Encoding ascii
    $decoded = Get-Content -LiteralPath $snapshotPath -Raw | ConvertFrom-Json
    if ($decoded.schema -cne 'oncam.f9-o1.queue-outbox-visibility.v1' -or $decoded.identifiersExcluded -ne $true) {
        throw 'Queue/outbox snapshot schema or identifiersExcluded flag is invalid.'
    }
    $serialized = Get-Content -LiteralPath $snapshotPath -Raw
    foreach ($forbidden in @('synthetic-secret', 'msg-', 'outbox-', '620000000000', 'last-error', 'provider-body', 'participant-id')) {
        if ($serialized.Contains($forbidden)) {
            throw "Queue/outbox snapshot leaked forbidden synthetic detail: $forbidden"
        }
    }

    $queueNames = @($decoded.queues | ForEach-Object { $_.name })
    $outboxNames = @($decoded.outboxes | ForEach-Object { $_.name })
    if (($queueNames -join ',') -cne 'default,integrations,notifications') {
        throw "Queue names are not the fixed expected set: $($queueNames -join ',')"
    }
    if (($outboxNames -join ',') -cne 'generic-result-callbacks,participant-notifications') {
        throw "Outbox names are not the fixed expected set: $($outboxNames -join ',')"
    }

    $notifications = @($decoded.queues | Where-Object { $_.name -eq 'notifications' })[0]
    if ($notifications.depth -ne 4 -or $notifications.oldestEligibleAgeSeconds -ne 600 -or $notifications.retryCount -ne 1 -or $notifications.terminalCount -ne 1 -or $notifications.staleLeaseCount -ne 1) {
        throw 'Queue aggregate values did not match the synthetic visibility contract.'
    }
    $participantOutbox = @($decoded.outboxes | Where-Object { $_.name -eq 'participant-notifications' })[0]
    if ($participantOutbox.pendingCount -ne 1 -or $participantOutbox.processingCount -ne 1 -or $participantOutbox.retryCount -ne 1 -or $participantOutbox.terminalCount -ne 1 -or $participantOutbox.staleLeaseCount -ne 1) {
        throw 'Outbox aggregate values did not match the synthetic visibility contract.'
    }

    Write-Output "F9_O1_OBSERVABILITY_REHEARSAL_PASS label=$label commit=$ExpectedCommit"
    Write-Output 'health_healthy=200 health_db_down=503 health_redis_down=503'
    Write-Output "queue_notifications_depth=$($notifications.depth) queue_notifications_oldestEligibleAgeSeconds=$($notifications.oldestEligibleAgeSeconds) queue_notifications_retryCount=$($notifications.retryCount) queue_notifications_terminalCount=$($notifications.terminalCount) queue_notifications_staleLeaseCount=$($notifications.staleLeaseCount)"
    Write-Output "outbox_participant_pendingCount=$($participantOutbox.pendingCount) outbox_participant_retryCount=$($participantOutbox.retryCount) outbox_participant_terminalCount=$($participantOutbox.terminalCount) outbox_participant_staleLeaseCount=$($participantOutbox.staleLeaseCount)"
}
catch {
    $failure = $_
}
finally {
    try {
        $ownedContainers = docker ps --all --quiet --filter "label=$label"
        Assert-NativeSuccess 'Exact-label container lookup'
        foreach ($containerId in $ownedContainers) {
            if ($containerId -and $containerId -match '^[a-f0-9]{12,64}$') {
                docker rm --force $containerId | Out-Null
                Assert-NativeSuccess 'Exact-label container cleanup'
            }
        }
        if ($networkCreated) {
            $networkLabels = docker network inspect --format '{{json .Labels}}' $network | ConvertFrom-Json
            Assert-NativeSuccess 'Rehearsal network inspection'
            if ($networkLabels.'oncam.f9-o1-observability' -cne $runId) {
                throw 'Rehearsal network label mismatch; cleanup refused.'
            }
            docker network rm $network | Out-Null
            Assert-NativeSuccess 'Exact-label network cleanup'
        }
        if (Test-Path -LiteralPath $tempDirectory) {
            Remove-Item -LiteralPath $tempDirectory -Recurse -Force
        }
        $containersAfter = @(docker ps --all --quiet --filter "label=$label")
        Assert-NativeSuccess 'Post-cleanup container attestation'
        $networksAfter = @(docker network ls --quiet --filter "label=$label")
        Assert-NativeSuccess 'Post-cleanup network attestation'
        Write-Output "cleanup label=$label containers=$($containersAfter.Count) networks=$($networksAfter.Count) temp_removed=$(-not (Test-Path -LiteralPath $tempDirectory))"
        if ($containersAfter.Count -ne 0) { throw 'Exact-label cleanup left containers behind; expected containers=0.' }
        if ($networksAfter.Count -ne 0) { throw 'Exact-label cleanup left networks behind; expected networks=0.' }
    }
    catch {
        if ($null -eq $failure) { $failure = $_ }
        else { Write-Error "Cleanup also failed: $($_.Exception.Message)" }
    }
}

if ($null -ne $failure) {
    Write-Error $failure.Exception.Message
    exit 1
}
exit 0
