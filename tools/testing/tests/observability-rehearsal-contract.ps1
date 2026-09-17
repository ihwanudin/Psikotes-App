$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
$runner = Join-Path $workspace 'tools/testing/run-observability-rehearsal.ps1'
$collector = Join-Path $workspace 'tools/testing/observability/collect-queue-outbox.ps1'

function Assert-True([bool] $condition, [string] $message) {
    if (-not $condition) { throw $message }
}

function Assert-Contains([string] $text, [string] $needle, [string] $message) {
    Assert-True ($text.IndexOf($needle, [StringComparison]::Ordinal) -ge 0) $message
}

function Assert-NotContains([string] $text, [string] $needle, [string] $message) {
    Assert-True ($text.IndexOf($needle, [StringComparison]::Ordinal) -lt 0) $message
}

Assert-True (Test-Path -LiteralPath $runner -PathType Leaf) 'Observability rehearsal runner is missing.'
Assert-True (Test-Path -LiteralPath $collector -PathType Leaf) 'Queue/outbox collector is missing.'

$runnerSource = Get-Content -LiteralPath $runner -Raw
$collectorSource = Get-Content -LiteralPath $collector -Raw

$tokens = $null
$parseErrors = $null
[void] [System.Management.Automation.Language.Parser]::ParseFile($runner, [ref] $tokens, [ref] $parseErrors)
Assert-True ($parseErrors.Count -eq 0) ('Runner has PowerShell parse errors: ' + (($parseErrors | ForEach-Object Message) -join '; '))
[void] [System.Management.Automation.Language.Parser]::ParseFile($collector, [ref] $tokens, [ref] $parseErrors)
Assert-True ($parseErrors.Count -eq 0) ('Collector has PowerShell parse errors: ' + (($parseErrors | ForEach-Object Message) -join '; '))

Assert-Contains $runnerSource '[Parameter(Mandatory = $true)]' 'ExpectedCommit must be mandatory.'
Assert-Contains $runnerSource "[ValidatePattern('^[0-9a-f]{40}$')]" 'ExpectedCommit must be a full lowercase SHA.'
Assert-Contains $runnerSource 'status --porcelain=v1 --untracked-files=all' 'Runner must reject a dirty worktree.'
Assert-Contains $runnerSource 'docker network create --internal --label $label $network' 'Runner must use an internal exact-label network.'
Assert-Contains $runnerSource '--pull=never' 'Runner must not fetch images during rehearsal.'
Assert-Contains $runnerSource 'postgres:17.6-alpine' 'Runner must pin PostgreSQL.'
Assert-Contains $runnerSource 'redis:8.2-alpine' 'Runner must pin Redis.'
Assert-Contains $runnerSource 'psikotes-app:dev' 'Runner must use the local application image.'
Assert-Contains $runnerSource 'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' 'Runner must use a synthetic APP_KEY.'
Assert-Contains $runnerSource 'N8N_WEBHOOK_URL=' 'Runner must disable notification outbound configuration.'
Assert-Contains $runnerSource 'SELECTION_RESULT_CALLBACK_ENABLED=false' 'Runner must disable integration callbacks.'
Assert-Contains $runnerSource 'Health check returned an unexpected healthy response.' 'Runner must assert exact healthy response.'
Assert-Contains $runnerSource 'Health dependency failure did not return the exact degraded response.' 'Runner must assert exact degraded response.'
Assert-Contains $runnerSource 'Queue/outbox visibility collection failed before a complete snapshot.' 'Runner must fail closed before partial snapshots.'
Assert-Contains $runnerSource 'identifiersExcluded' 'Runner must verify identifier exclusion.'
Assert-Contains $runnerSource 'containers=0' 'Runner must attest exact-label container cleanup.'
Assert-Contains $runnerSource 'networks=0' 'Runner must attest exact-label network cleanup.'
Assert-Contains $runnerSource 'Remove-Item -LiteralPath $tempDirectory -Recurse -Force' 'Runner must remove its task temp directory.'
Assert-NotContains $runnerSource '.env' 'Runner must not read environment files.'
Assert-NotContains $runnerSource '--publish' 'Runner must not publish ports.'
Assert-NotContains $runnerSource 'queue:work' 'Runner must not start queue workers.'
Assert-NotContains $runnerSource 'schedule:work' 'Runner must not start the scheduler.'
Assert-NotContains $runnerSource 'SLO' 'Runner must not create SLO language.'
Assert-NotContains $runnerSource 'alert' 'Runner must not create alert language.'

Assert-Contains $collectorSource 'BEGIN READ ONLY' 'Collector must declare read-only query intent.'
Assert-Contains $collectorSource 'f9_o1_queue_items' 'Collector must read the synthetic queue snapshot.'
Assert-Contains $collectorSource 'f9_o1_outbox_items' 'Collector must read the synthetic outbox snapshot.'
Assert-Contains $collectorSource 'oldestEligibleAgeSeconds' 'Collector must report oldest eligible age.'
Assert-Contains $collectorSource 'staleLeaseCount' 'Collector must report stale leases.'
Assert-Contains $collectorSource 'retryCount' 'Collector must report retry counts.'
Assert-Contains $collectorSource 'terminalCount' 'Collector must report terminal counts.'
Assert-Contains $collectorSource 'identifiersExcluded' 'Collector must state identifier exclusion.'
Assert-Contains $collectorSource 'notifications' 'Collector must keep queue names in a fixed allowlist.'
Assert-Contains $collectorSource 'integrations' 'Collector must keep queue names in a fixed allowlist.'
Assert-Contains $collectorSource 'participant-notifications' 'Collector must keep outbox names in a fixed allowlist.'
Assert-Contains $collectorSource 'generic-result-callbacks' 'Collector must keep outbox names in a fixed allowlist.'
Assert-NotContains $collectorSource 'synthetic_message_id' 'Collector must not select message identifiers.'
Assert-NotContains $collectorSource 'payload' 'Collector must not select payloads.'
Assert-NotContains $collectorSource 'last_error' 'Collector must not select error text.'
Assert-NotContains $collectorSource 'participant_id' 'Collector must not select participant identifiers.'
Assert-NotContains $collectorSource 'tenant' 'Collector must not select tenant identifiers.'
Assert-True (-not [regex]::IsMatch($collectorSource, '(?i)\b(insert|update|delete|truncate|drop|alter|create)\b')) `
    'Collector must not contain SQL mutation statements.'

Write-Output 'observability-rehearsal-contract: PASS (54 assertions)'
