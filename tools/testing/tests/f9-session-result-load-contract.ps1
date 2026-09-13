param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
$runnerPath = Join-Path $root 'tools/testing/run-f9-session-result-load.ps1'
$harnessPath = Join-Path $root 'tools/testing/f9-session-result-load.php'

function Assert-True([bool] $condition, [string] $message) {
    if (-not $condition) { throw $message }
    $script:assertions++
}

function Assert-Contains([string] $source, [string] $needle, [string] $message) {
    Assert-True ($source.Contains($needle)) $message
}

$script:assertions = 0
Assert-True (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'Load runner is missing.'
Assert-True (Test-Path -LiteralPath $harnessPath -PathType Leaf) 'PHP load harness is missing.'

$runner = Get-Content -Raw -LiteralPath $runnerPath
$harness = Get-Content -Raw -LiteralPath $harnessPath

Assert-Contains $runner "ValidatePattern('^[a-f0-9]{40}$')" 'ExpectedCommit must be a full lowercase SHA.'
Assert-Contains $runner 'status --porcelain=v1 --untracked-files=all' 'Dirty and untracked files must be rejected.'
Assert-Contains $runner 'archive --format=tar' 'Runner must execute an immutable Git snapshot.'
Assert-Contains $runner 'postgres:17.6-alpine' 'PostgreSQL image must be pinned.'
Assert-Contains $runner 'psikotes-app:dev' 'Application image must be pinned.'
Assert-Contains $runner 'docker network create --internal --label' 'Network must be internal and exactly labeled.'
Assert-True (-not $runner.Contains('--publish')) 'Runner must not publish ports.'
Assert-Contains $runner '--tmpfs /var/lib/postgresql/data:rw' 'Database must use tmpfs.'
Assert-Contains $runner 'target=/runtime-vendor,readonly' 'Vendor bind must be read-only.'
Assert-Contains $runner 'GetTempPath' 'Task temp must live outside the repository.'
Assert-Contains $runner 'oncam.f9-session-result-load' 'A task-specific exact label is required.'
Assert-Contains $runner "@(1, 4, 8)" 'Fixed concurrency profiles must be 1, 4, and 8.'
Assert-Contains $runner 'WarmupOperations = 32' 'Warmup must be exactly 32 operations per boundary/profile.'
Assert-Contains $runner 'MeasuredIterations = 3' 'There must be three measured iterations.'
Assert-Contains $runner 'StartOperations = 64' 'Start workload must be 64 operations per iteration/profile.'
Assert-Contains $runner 'ReaderOperations = 256' 'Reader workload must be 256 operations per iteration/profile.'
Assert-Contains $runner 'BASELINE_ONLY_NO_SLO' 'Results must be explicitly report-only.'
Assert-True (-not ($runner -match '(?i)latency.{0,30}(budget|threshold|target)|throughput.{0,30}(budget|threshold|target)')) 'Runner must not invent latency or throughput thresholds.'
Assert-Contains $runner 'cleanup containers=' 'Cleanup inventory must be emitted.'
Assert-Contains $runner 'temp_removed=' 'Temporary-directory cleanup must be emitted.'

Assert-Contains $harness "'start'" 'Harness must exercise StartAssessmentSession.'
Assert-Contains $harness "'reader'" 'Harness must exercise LoadPersistedIstResult.'
Assert-Contains $harness 'StartAssessmentSession::class' 'Start boundary must be invoked directly.'
Assert-Contains $harness 'LoadPersistedIstResult::class' 'Reader boundary must be invoked directly.'
Assert-Contains $harness 'runAsService' 'Runtime work must use service context.'
Assert-Contains $harness 'DB::transaction' 'Reader calls must retain a caller-owned transaction.'
Assert-Contains $harness 'psikotes_runtime' 'Runtime role identity must be checked.'
Assert-Contains $harness 'rolsuper' 'Superuser status must be rejected.'
Assert-Contains $harness 'rolbypassrls' 'BYPASSRLS must be rejected.'
Assert-Contains $harness "['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME']" 'Nine-source order must be fixed.'
Assert-Contains $harness 'pg_stat_database' 'Database counters must be observed.'
Assert-Contains $harness 'pg_stat_activity' 'Connections must be observed.'
Assert-Contains $harness 'pg_locks' 'Lock waits must be observed.'
Assert-Contains $harness 'p50_ms' 'p50 must be reported.'
Assert-Contains $harness 'p95_ms' 'p95 must be reported.'
Assert-Contains $harness 'p99_ms' 'p99 must be reported.'
Assert-Contains $harness 'error_rate' 'Error rate must be reported.'
Assert-Contains $harness 'query_count' 'Query count must be reported.'
Assert-Contains $harness 'query_time_ms' 'Cumulative query time must be reported.'

$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $php) { throw 'PHP CLI is required for the executable metric contract.' }
$selfTest = & $php.Source $harnessPath --self-test
if ($LASTEXITCODE -ne 0) { throw 'Metric self-test failed.' }
$metrics = $selfTest | ConvertFrom-Json
Assert-True ($metrics.p50_ms -eq 3.0) 'Known-vector p50 must use nearest-rank math.'
Assert-True ($metrics.p95_ms -eq 5.0) 'Known-vector p95 must use nearest-rank math.'
Assert-True ($metrics.p99_ms -eq 5.0) 'Known-vector p99 must use nearest-rank math.'
Assert-True ($metrics.error_rate -eq 0.2) 'Error-rate math must be errors divided by requested operations.'
Assert-True ($metrics.semantics -eq 'BASELINE_ONLY_NO_SLO') 'Self-test must preserve report-only semantics.'

Write-Output "f9-session-result-load-contract: PASS ($script:assertions assertions)"
