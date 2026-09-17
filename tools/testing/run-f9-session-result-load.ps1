param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-f0-9]{40}$')]
    [string] $ExpectedCommit,
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $VendorDirectory,
    [int[]] $ConcurrencyProfiles = @(1, 4, 8),
    [int] $WarmupOperations = 32,
    [int] $MeasuredIterations = 3,
    [int] $StartOperations = 64,
    [int] $ReaderOperations = 256
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
if (($ConcurrencyProfiles -join ',') -ne '1,4,8' -or $WarmupOperations -ne 32 `
    -or $MeasuredIterations -ne 3 -or $StartOperations -ne 64 -or $ReaderOperations -ne 256) {
    throw 'Invalid workload: the reviewed fixed profile is concurrency 1,4,8; warmup 32; iterations 3; start 64; reader 256.'
}
$head = (git -C $workspace rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $head -cne $ExpectedCommit) {
    throw "ExpectedCommit mismatch; exact clean snapshot required."
}
$dirty = @(git -C $workspace status --porcelain=v1 --untracked-files=all)
if ($LASTEXITCODE -ne 0 -or $dirty.Count -ne 0) {
    throw 'Dirty tracked or untracked worktree refused before provisioning.'
}
$vendor = (Resolve-Path -LiteralPath $VendorDirectory).Path
if (-not (Test-Path -LiteralPath (Join-Path $vendor 'autoload.php') -PathType Leaf)) {
    throw 'A real Composer vendor directory is required.'
}

$runId = [guid]::NewGuid().ToString('N')
$labelKey = 'oncam.f9-session-result-load'
$label = "$labelKey=$runId"
$network = "oncam-f9-load-$runId"
$databaseContainer = "$network-db"
$runnerContainer = "$network-runner"
$tempRoot = Join-Path ([IO.Path]::GetTempPath()) "oncam-f9-load-$runId"
$archive = Join-Path $tempRoot 'snapshot.tar'
$networkCreated = $false
$exitCode = 1
$primaryFailure = $null
$cleanupFailures = @()

function Assert-DockerSuccess([string] $operation) {
    if ($LASTEXITCODE -ne 0) { throw "$operation failed; no live application resource was targeted." }
}

try {
    New-Item -ItemType Directory -Path $tempRoot | Out-Null
    git -C $workspace archive --format=tar --output=$archive $ExpectedCommit
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $archive -PathType Leaf)) {
        throw 'Immutable Git archive creation failed.'
    }
    docker image inspect postgres:17.6-alpine --format '{{.Id}}' | Out-Null
    Assert-DockerSuccess 'PostgreSQL image check'
    docker image inspect psikotes-app:dev --format '{{.Id}}' | Out-Null
    Assert-DockerSuccess 'Application image check'

    docker network create --internal --label $label $network | Out-Null
    Assert-DockerSuccess 'Internal network creation'
    $networkCreated = $true
    docker run --detach --pull=never --name $databaseContainer --label $label `
        --network $network --network-alias f9-load-db `
        --tmpfs /var/lib/postgresql/data:rw `
        --env POSTGRES_USER=f9_load_owner --env POSTGRES_DB=psikotes_f9_load `
        --env POSTGRES_HOST_AUTH_METHOD=trust postgres:17.6-alpine | Out-Null
    Assert-DockerSuccess 'Disposable PostgreSQL creation'

    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        docker exec $databaseContainer pg_isready -h 127.0.0.1 -U f9_load_owner -d psikotes_f9_load | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Disposable PostgreSQL did not become ready.' }
    docker exec $databaseContainer psql -h 127.0.0.1 -U f9_load_owner -d psikotes_f9_load `
        -v ON_ERROR_STOP=1 -c "COMMENT ON DATABASE psikotes_f9_load IS 'ONCAM_F9_LOAD:$runId'" | Out-Null
    Assert-DockerSuccess 'Disposable database marker'

    Write-Output "semantics=BASELINE_ONLY_NO_SLO label=$label"
    Write-Output "workload concurrency=$($ConcurrencyProfiles -join ',') warmup=$WarmupOperations iterations=$MeasuredIterations start_ops=$StartOperations reader_ops=$ReaderOperations"
    $shell = @'
mkdir -p /tmp/load-app/vendor /tmp/load-app/storage/framework/cache/data /tmp/load-app/storage/framework/sessions /tmp/load-app/storage/framework/views /tmp/load-app/storage/logs /tmp/load-app/bootstrap/cache
tar -xf /snapshot/source.tar -C /tmp/load-app
cp /runtime-vendor/autoload.php /tmp/load-app/vendor/
cp -a /runtime-vendor/composer /tmp/load-app/vendor/
for dependency in /runtime-vendor/*; do name=$(basename "$dependency"); if [ "$name" != autoload.php ] && [ "$name" != composer ]; then ln -s "$dependency" "/tmp/load-app/vendor/$name"; fi; done
cd /tmp/load-app
exec php tools/testing/f9-session-result-load.php --concurrency=1,4,8 --warmup=32 --iterations=3 --start-ops=64 --reader-ops=256
'@
    $dockerArgs = @(
        'run', '--rm', '--pull=never', '--name', $runnerContainer, '--label', $label,
        '--network', $network,
        '--mount', "type=bind,source=$archive,target=/snapshot/source.tar,readonly",
        '--mount', "type=bind,source=$vendor,target=/runtime-vendor,readonly",
        '--mount', "type=bind,source=$tempRoot,target=/run-output",
        '--tmpfs', '/tmp/load-app/storage:rw', '--tmpfs', '/tmp/load-app/bootstrap/cache:rw',
        '--env', "F9_LOAD_RUN_ID=$runId", '--env', 'F9_LOAD_OUTPUT=/run-output',
        '--env', 'APP_ENV=testing',
        '--env', 'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        '--env', 'APP_DEBUG=false', '--env', 'APP_URL=http://localhost',
        '--entrypoint', 'sh', 'psikotes-app:dev', '-euc', $shell
    )
    & docker @dockerArgs
    $exitCode = $LASTEXITCODE
    $reportPath = Join-Path $tempRoot 'report.json'
    if ($exitCode -ne 0 -or -not (Test-Path -LiteralPath $reportPath -PathType Leaf)) {
        $exitCode = 1
        throw "FIRST_ROOT_CAUSE: disposable load harness exited $exitCode."
    }
}
catch {
    $primaryFailure = $_.Exception.Message
    $exitCode = 1
}
finally {
    foreach ($container in @($runnerContainer, $databaseContainer)) {
        $containerIds = @(docker ps --all --quiet --filter "name=^/$container$" --filter "label=$label" 2>$null)
        $containerLookupExit = $LASTEXITCODE
        if ($containerLookupExit -ne 0) {
            $cleanupFailures += "CONTAINER_LOOKUP_FAILED:$container"
        }
        elseif ($containerIds.Count -gt 1 -or ($containerIds.Count -eq 1 -and $containerIds[0] -notmatch '^[a-f0-9]{12,64}$')) {
            $cleanupFailures += "CONTAINER_LOOKUP_INVALID:$container"
        }
        elseif ($containerIds.Count -eq 1) {
            docker rm --force $containerIds[0] | Out-Null
            $containerRemoveExit = $LASTEXITCODE
            if ($containerRemoveExit -ne 0) {
                $cleanupFailures += "CONTAINER_REMOVE_FAILED:$container"
            }
        }
    }
    if ($networkCreated) {
        $parsedNetworkLabels = $null
        $networkLabels = docker network inspect --format '{{json .Labels}}' $network 2>$null
        $networkInspectExit = $LASTEXITCODE
        if ($networkInspectExit -ne 0 -or [string]::IsNullOrWhiteSpace($networkLabels)) {
            $cleanupFailures += 'NETWORK_INSPECT_FAILED'
        }
        else {
            try {
                $parsedNetworkLabels = $networkLabels | ConvertFrom-Json -ErrorAction Stop
            }
            catch {
                $parsedNetworkLabels = $null
                $cleanupFailures += 'NETWORK_INSPECT_INVALID'
            }
            if ($null -ne $parsedNetworkLabels -and $parsedNetworkLabels.$labelKey -ne $runId) {
                $cleanupFailures += 'NETWORK_LABEL_MISMATCH'
            }
        }
        if ($networkInspectExit -eq 0 -and $null -ne $parsedNetworkLabels -and $parsedNetworkLabels.$labelKey -eq $runId) {
            docker network rm $network | Out-Null
            $networkRemoveExit = $LASTEXITCODE
            if ($networkRemoveExit -ne 0) {
                $cleanupFailures += 'NETWORK_REMOVE_FAILED'
            }
        }
    }

    $remainingContainerIds = @(docker ps --all --quiet --filter "label=$label" 2>$null)
    $containerInventoryExit = $LASTEXITCODE
    if ($containerInventoryExit -ne 0) {
        $cleanupFailures += 'CONTAINER_INVENTORY_FAILED'
        $remainingContainers = 'UNKNOWN'
    }
    else {
        $remainingContainers = $remainingContainerIds.Count
    }
    $remainingNetworkIds = @(docker network ls --quiet --filter "label=$label" 2>$null)
    $networkInventoryExit = $LASTEXITCODE
    if ($networkInventoryExit -ne 0) {
        $cleanupFailures += 'NETWORK_INVENTORY_FAILED'
        $remainingNetworks = 'UNKNOWN'
    }
    else {
        $remainingNetworks = $remainingNetworkIds.Count
    }
    if (Test-Path -LiteralPath $tempRoot) {
        try {
            Remove-Item -LiteralPath $tempRoot -Recurse -Force -ErrorAction Stop
        }
        catch {
            $cleanupFailures += 'TEMP_REMOVE_FAILED'
        }
    }
    $tempRemoved = -not (Test-Path -LiteralPath $tempRoot)
    if (-not $tempRemoved) {
        $cleanupFailures += 'TEMP_ATTESTATION_FAILED'
    }
    if ($containerInventoryExit -eq 0 -and $remainingContainers -ne 0) {
        $cleanupFailures += 'CONTAINER_RESIDUE'
    }
    if ($networkInventoryExit -eq 0 -and $remainingNetworks -ne 0) {
        $cleanupFailures += 'NETWORK_RESIDUE'
    }
    Write-Output "cleanup containers=$remainingContainers networks=$remainingNetworks temp_removed=$tempRemoved"
}

if ($null -ne $primaryFailure) {
    [Console]::Error.WriteLine("PRIMARY_FAILURE: $primaryFailure")
}
if ($cleanupFailures.Count -ne 0) {
    [Console]::Error.WriteLine("CLEANUP_FAILURE: $($cleanupFailures -join ',')")
    exit 1
}
if ($null -ne $primaryFailure) {
    exit 1
}
exit $exitCode
