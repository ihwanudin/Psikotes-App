param(
    [string] $Filter = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$runId = [guid]::NewGuid().ToString('N')
$network = "oncam-org-test-$runId"
$databaseContainer = "$network-db"
$runnerContainer = "$network-runner"
$label = "oncam.org-test-run=$runId"
$networkCreated = $false
$testExitCode = 1

function Assert-DockerSuccess([string] $operation) {
    if ($LASTEXITCODE -ne 0) { throw "$operation failed; no active application containers will be modified." }
}

try {
    docker image inspect postgres:17.6-alpine --format '{{.Id}}' | Out-Null
    Assert-DockerSuccess 'PostgreSQL image check'
    docker image inspect psikotes-app:dev --format '{{.Id}}' | Out-Null
    Assert-DockerSuccess 'PHP image check'
    if (-not (Test-Path (Join-Path $workspace 'vendor/phpunit/phpunit/phpunit'))) {
        throw 'Development vendor dependencies are required.'
    }

    docker network create --internal --label $label $network | Out-Null
    Assert-DockerSuccess 'Isolated network creation'
    $networkCreated = $true

    docker run --detach --pull=never --name $databaseContainer --label $label `
        --network $network --network-alias org-test-db `
        --tmpfs /var/lib/postgresql/data:rw `
        --env POSTGRES_USER=org_test_owner --env POSTGRES_DB=psikotes_organization_test `
        --env POSTGRES_HOST_AUTH_METHOD=trust postgres:17.6-alpine | Out-Null
    Assert-DockerSuccess 'Disposable PostgreSQL creation'

    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        # PostgreSQL's init phase briefly exposes a temporary Unix socket before
        # restarting the final server. Probe TCP so that transient socket cannot
        # be mistaken for durable readiness by the following marker command.
        docker exec $databaseContainer pg_isready -h 127.0.0.1 -U org_test_owner -d psikotes_organization_test | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Disposable PostgreSQL did not become ready.' }

    docker exec $databaseContainer psql -h 127.0.0.1 -U org_test_owner -d psikotes_organization_test `
        -v ON_ERROR_STOP=1 -c "COMMENT ON DATABASE psikotes_organization_test IS 'ONCAM_ORG_TEST:$runId'" | Out-Null
    Assert-DockerSuccess 'Disposable database marker'

    Write-Output "Running PostgreSQL tests on disposable network $network (no published ports)."
    $phpunitCommand = @(
        'vendor/bin/phpunit',
        '--configuration',
        'phpunit.organization-postgres.xml'
    )
    if ($Filter -ne '') {
        $phpunitCommand += @('--filter', $Filter)
    }
    docker run --rm --pull=never --name $runnerContainer --label $label --network $network `
        --mount "type=bind,source=$workspace,target=/workspace,readonly" `
        --tmpfs /workspace/storage:rw --tmpfs /workspace/bootstrap/cache:rw `
        --workdir /workspace --env "ORG_TEST_RUN_ID=$runId" `
        --entrypoint php psikotes-app:dev @phpunitCommand
    $testExitCode = $LASTEXITCODE
}
finally {
    # Never select by a broad prefix: require this invocation's exact name AND label.
    foreach ($container in @($runnerContainer, $databaseContainer)) {
        $containerId = docker ps --all --quiet --filter "name=^/$container$" --filter "label=$label"
        Assert-DockerSuccess 'Disposable container lookup'
        if ($containerId -and $containerId -match '^[a-f0-9]{12,64}$') {
            docker rm --force $containerId | Out-Null
            Assert-DockerSuccess 'Disposable container cleanup'
        }
    }
    if ($networkCreated) {
        $networkLabels = docker network inspect --format '{{json .Labels}}' $network | ConvertFrom-Json
        if ($LASTEXITCODE -eq 0 -and $networkLabels.'oncam.org-test-run' -eq $runId) {
            docker network rm $network | Out-Null
            Assert-DockerSuccess 'Disposable network cleanup'
        }
    }
    Write-Output 'Disposable test resources cleaned up; application containers were not targeted.'
}
exit $testExitCode
