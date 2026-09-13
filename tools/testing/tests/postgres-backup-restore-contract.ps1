$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
$runner = Join-Path $workspace 'tools/testing/run-postgres-backup-restore.ps1'

function Assert-True([bool] $condition, [string] $message) {
    if (-not $condition) { throw $message }
}

function Assert-Contains([string] $text, [string] $needle, [string] $message) {
    Assert-True ($text.IndexOf($needle, [StringComparison]::Ordinal) -ge 0) $message
}

Assert-True (Test-Path -LiteralPath $runner -PathType Leaf) 'Backup/restore runner is missing.'
$source = Get-Content -LiteralPath $runner -Raw
$tokens = $null
$parseErrors = $null
[void] [System.Management.Automation.Language.Parser]::ParseFile($runner, [ref] $tokens, [ref] $parseErrors)
if ($parseErrors.Count -ne 0) {
    throw ('Runner has PowerShell parse errors: ' + (($parseErrors | ForEach-Object Message) -join '; '))
}

Assert-Contains $source '[Parameter(Mandatory = $true)]' 'ExpectedCommit must be mandatory.'
Assert-Contains $source "[ValidatePattern('^[0-9a-f]{40}$')]" 'ExpectedCommit must be a full lowercase SHA.'
Assert-Contains $source "status --porcelain=v1 --untracked-files=all" 'Runner must reject a dirty snapshot.'
Assert-Contains $source "VendorDirectory must be a real Composer vendor installation." 'Runner must validate its Composer runtime input.'
Assert-Contains $source 'target=/runtime-vendor,readonly' 'Composer runtime must be mounted read-only.'
Assert-Contains $source 'postgres:17.6-alpine' 'PostgreSQL image must be pinned.'
Assert-Contains $source 'psikotes-app:dev' 'Application image must be pinned.'
Assert-Contains $source 'docker network create --internal --label $label $network' 'Network must be internal and exactly labeled.'
Assert-True (-not $source.Contains('--publish')) 'Runner must not publish ports.'
Assert-True (-not [regex]::IsMatch($source, "(?m)^\s*'?-p'?,?\s*$")) 'Runner must not use Docker short-form port publishing.'
Assert-Contains $source '[System.IO.Path]::GetTempPath()' 'Archive must use the task temp root outside the repository.'
Assert-Contains $source '--format=custom' 'Runner must create a custom-format dump.'
Assert-Contains $source 'pg_restore --list' 'Runner must inspect the archive before restore.'
Assert-Contains $source '--single-transaction' 'Restore must be atomic.'
Assert-Contains $source '--exit-on-error' 'Restore must fail on the first error.'
Assert-Contains $source 'SchemaFingerprint' 'Runner must compare authoritative schema fingerprints.'
Assert-Contains $source 'DataFingerprint' 'Runner must compare row/value checksums.'
Assert-Contains $source 'SequenceFingerprint' 'Runner must compare sequence state.'
Assert-Contains $source 'corrupt' 'Runner must exercise a damaged archive.'
Assert-Contains $source "containers=0" 'Runner must attest exact-label container cleanup.'
Assert-Contains $source "networks=0" 'Runner must attest exact-label network cleanup.'
Assert-Contains $source 'Remove-Item -LiteralPath $tempDirectory -Recurse -Force' 'Runner must remove its task temp directory.'

Write-Output 'postgres-backup-restore-contract: PASS (24 assertions)'
