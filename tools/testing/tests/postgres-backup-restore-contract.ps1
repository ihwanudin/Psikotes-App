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
$ast = [System.Management.Automation.Language.Parser]::ParseFile($runner, [ref] $tokens, [ref] $parseErrors)
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
Assert-Contains $source 'data_type' 'Sequence fingerprint must include the sequence data type.'
Assert-Contains $source 'start_value' 'Sequence fingerprint must include the configured start value.'
Assert-Contains $source 'min_value' 'Sequence fingerprint must include the configured minimum value.'
Assert-Contains $source 'max_value' 'Sequence fingerprint must include the configured maximum value.'
Assert-Contains $source 'increment_by' 'Sequence fingerprint must include the configured increment.'
Assert-Contains $source 'cache_size' 'Sequence fingerprint must include the configured cache size.'
Assert-Contains $source 'cycle' 'Sequence fingerprint must include the configured cycle behavior.'
Assert-Contains $source 'SELECT last_value, is_called FROM %I.%I' 'Sequence fingerprint must read direct last_value/is_called state.'
Assert-Contains $source 'corrupt' 'Runner must exercise a damaged archive.'
Assert-Contains $source "containers=0" 'Runner must attest exact-label container cleanup.'
Assert-Contains $source "networks=0" 'Runner must attest exact-label network cleanup.'
Assert-Contains $source 'Remove-Item -LiteralPath $tempDirectory -Recurse -Force' 'Runner must remove its task temp directory.'
Assert-Contains $source "GetEnvironmentVariable('F9_BACKUP_AGE_IDENTITY')" 'Operator identity must come from the environment.'
Assert-Contains $source '/rehearsal/age-keygen -o /rehearsal/identity.txt' 'Default rehearsal must generate a fresh identity inside the disposable container.'
Assert-Contains $source 'Join-Path $tempDirectory ''identity.txt''' 'Identity must live in the GUID task directory.'
Assert-Contains $source 'apk add --no-cache age' 'Established age tooling must be installed ephemerally.'
Assert-Contains $source 'test -x /usr/bin/age ||' 'Fixed age path must be checked before copying.'
Assert-Contains $source 'test -x /usr/bin/age-keygen ||' 'Fixed age-keygen path must be checked before copying.'
Assert-True (-not $source.Contains('command -v age')) 'Windows PowerShell 5.1-unsafe command substitution must not be used.'
Assert-Contains $source '/rehearsal/age -r $recipient -o /rehearsal/source.dump.age /rehearsal/source.dump' 'Archive encryption must be mandatory.'
Assert-Contains $source '/rehearsal/age -d -i /rehearsal/identity.txt' 'Restore must decrypt with the identity.'
Assert-Contains $source 'encryptedBytes -le $archiveBytes' 'Encrypted archive size must be checked.'
Assert-Contains $source 'Length -ne $archiveBytes' 'Decrypted archive size must match the original.'
Assert-Contains $source 'Remove-Item -LiteralPath $dumpArchive -Force' 'Plaintext export must be removed after encryption.'
Assert-Contains $source 'ReadAllBytes($encryptedArchive)' 'Corruption probe must damage ciphertext.'
Assert-Contains $source 'if ($corruptExitCode -eq 0)' 'Corrupted ciphertext must fail authentication.'
Assert-Contains $source 'Assert-OutputExcludesKey $output $identitySecret' 'Each key-touching native step must scan its captured output.'
Assert-Contains $source 'Assert-OutputExcludesKey (($keygenOutput' 'Key generation output must also be scanned.'
Assert-Contains $source 'key_output_check=PASS steps=$keyOutputChecks' 'Successful runs must attest the key output checks.'
Assert-Contains $source '[023456789ACDEFGHJKLMNPQRSTUVWXYZ]+$' 'Native age identities containing L must pass validation.'
Assert-Contains $source "GetEnvironmentVariable('F9_BACKUP_S3_ENDPOINT')" 'S3 endpoint must come from the environment boundary.'
Assert-Contains $source "GetEnvironmentVariable('F9_BACKUP_S3_BUCKET')" 'S3 bucket must come from the environment boundary.'
Assert-Contains $source "GetEnvironmentVariable('F9_BACKUP_S3_ACCESS_KEY_ID')" 'S3 access key must come from the environment boundary.'
Assert-Contains $source "GetEnvironmentVariable('F9_BACKUP_S3_SECRET_ACCESS_KEY')" 'S3 secret key must come from the environment boundary.'
Assert-Contains $source "GetEnvironmentVariable('F9_BACKUP_S3_REGION')" 'S3 region must come from the environment boundary.'
Assert-Contains $source 'All five F9_BACKUP_S3_* environment variables are required together.' 'Partial operator configuration must fail closed.'
Assert-Contains $source 'quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z' 'Synthetic S3-compatible target image must be pinned.'
Assert-Contains $source 'quay.io/minio/mc:RELEASE.2025-04-16T18-13-26Z' 'S3-compatible client image must be pinned.'
Assert-Contains $source 'docker network create --internal --label $label $offsiteNetwork' 'Synthetic object storage must use its own internal network.'
Assert-Contains $source '--network $network --network-alias f9-backup-db' 'Database must remain on the isolated database network.'
Assert-Contains $source '[System.Text.Encoding]::ASCII.GetBytes("age-encryption.org/v1`n")' 'Upload guard must require the complete age header.'
Assert-Contains $source 'Assert-AgeCiphertext $archivePath' 'Every offsite upload must pass the structural ciphertext guard.'
Assert-Contains $source 'Send-EncryptedOffsiteCopy $encryptedArchive $offsiteObject' 'The encrypted archive must be the production upload input.'
Assert-Contains $source 'Send-EncryptedOffsiteCopy $nonAgeProbe' 'Harness must attempt the negative non-age upload.'
Assert-Contains $source 'Offsite upload rejected: file is not an age ciphertext.' 'Non-age input must fail with a clear message.'
Assert-Contains $source 'Get-FileSha256 $encryptedArchive' 'Local ciphertext checksum must be computed.'
Assert-Contains $source 'source.offsite.download.age' 'Uploaded ciphertext must be downloaded again.'
Assert-Contains $source '$downloadedEncryptedSha256 -cne $localEncryptedSha256' 'Downloaded and local checksums must be compared explicitly.'
Assert-Contains $source 'Wrong S3 credential rejection probe' 'Harness must execute the wrong-credential negative probe.'
Assert-Contains $source 'offsite_copy=PASS' 'Successful runs must attest the offsite copy.'
Assert-Contains $source 's3_output_check=PASS' 'Successful runs must attest S3 credential output checks.'
Assert-Contains $source 'Exact-label offsite network cleanup' 'Dedicated offsite network must be cleaned by exact label.'

$s3GuardAst = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -ceq 'Assert-OutputExcludesSecrets'
}, $true)
Assert-True ($null -ne $s3GuardAst) 'Runner must define the S3 credential output guard.'
. ([scriptblock]::Create($s3GuardAst.Extent.Text))
$syntheticS3Secrets = @('F9_SYNTHETIC_ACCESS', 'F9_SYNTHETIC_SECRET')
Assert-OutputExcludesSecrets 'mc: transfer complete' $syntheticS3Secrets
$rejectedLeakedS3Secret = $false
try { Assert-OutputExcludesSecrets "stderr: $($syntheticS3Secrets[1])" $syntheticS3Secrets }
catch { $rejectedLeakedS3Secret = $true }
Assert-True $rejectedLeakedS3Secret 'S3 output guard must reject a credential appearing in stderr.'

$keyGuardAst = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -ceq 'Assert-OutputExcludesKey'
}, $true)
Assert-True ($null -ne $keyGuardAst) 'Runner must define the key output guard.'
. ([scriptblock]::Create($keyGuardAst.Extent.Text))
$syntheticIdentity = 'AGE-SECRET-KEY-1TESTSYNTHETICIDENTITY'
Assert-OutputExcludesKey 'age: public recipient only' $syntheticIdentity
$rejectedLeakedIdentity = $false
try { Assert-OutputExcludesKey "stderr: $syntheticIdentity" $syntheticIdentity }
catch { $rejectedLeakedIdentity = $true }
Assert-True $rejectedLeakedIdentity 'Key output guard must reject an identity appearing in stderr.'

$normalizerAst = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -ceq 'ConvertTo-StableSchemaExpression'
}, $true)
Assert-True ($null -ne $normalizerAst) 'Runner must define the narrow schema-expression normalizer.'
. ([scriptblock]::Create($normalizerAst.Extent.Text))

$sourceArray = "status = ANY ((ARRAY['Created'::character varying, 'in_progress'::character varying])::text[])"
$restoredArray = "status = ANY (ARRAY[('Created'::character varying)::text, ('in_progress'::character varying)::text])"
Assert-True ((ConvertTo-StableSchemaExpression $sourceArray) -ceq (ConvertTo-StableSchemaExpression $restoredArray)) `
    'Observed redundant varchar/text array casts must canonicalize identically.'
Assert-True ((ConvertTo-StableSchemaExpression $sourceArray) -cne `
    (ConvertTo-StableSchemaExpression $sourceArray.Replace("'Created'", "'created'"))) `
    'Canonicalization must preserve case-sensitive string literal content.'
Assert-True ((ConvertTo-StableSchemaExpression '(a AND b) OR c') -cne `
    (ConvertTo-StableSchemaExpression 'a AND (b OR c)')) `
    'Canonicalization must preserve Boolean grouping.'
Assert-True ((ConvertTo-StableSchemaExpression '"CaseSensitive" = 1') -cne `
    (ConvertTo-StableSchemaExpression '"casesensitive" = 1')) `
    'Canonicalization must preserve quoted-identifier case.'

Write-Output 'postgres-backup-restore-contract: PASS (82 assertions)'
