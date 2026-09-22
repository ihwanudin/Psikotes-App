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
Assert-Contains $source "GetEnvironmentVariable('F9_BACKUP_RETENTION_DAYS')" 'Retention duration must come from the environment boundary.'
Assert-Contains $source '$retentionDays = 30' 'Retention must default to 30 days in one assignment.'
Assert-Contains $source '[int]::TryParse($retentionDaysValue' 'Retention input must be validated as an integer.'
Assert-Contains $source "GetEnvironmentVariable('F9_BACKUP_RETENTION_APPLY')" 'Destructive retention apply must have an explicit environment signal.'
Assert-Contains $source '$retentionApply = $false' 'Retention apply must default to dry-run.'
Assert-Contains $source '$retentionApplyValue -ieq ''true''' 'Only an explicit true value may enable retention apply.'
Assert-Contains $source '$backupKeyTemplate = ''backups/{date}/{name}.dump.age''' 'Backup prefix and dated key format must have one template.'
Assert-Contains $source '$backupPrefix = $backupKeyTemplate.Substring' 'Owned prefix must be derived from the shared key template.'
Assert-Contains $source '[DateTime]::UtcNow.Date' 'Retention reference date must come from the system clock.'
Assert-Contains $source 'function New-BackupObjectKey' 'Synthetic and current backup keys must use the shared writer.'
Assert-Contains $source 'function Get-BackupObjectDate' 'Retention must parse keys through one parser.'
Assert-Contains $source 'function Invoke-RetentionPrune' 'Runner must define a reusable retention operation.'
Assert-Contains $source '$objectKey = $backupPrefix + $listedKey' 'Relative mc listing keys must be scoped back under the owned prefix.'
Assert-Contains $source '$cutoffDate = $referenceDate.Date.AddDays(-$days)' 'Retention cutoff must derive from the single duration value.'
Assert-Contains $source '$backupDate -gt $referenceDate.Date' 'Future-dated keys must be treated separately.'
Assert-Contains $source '$null -eq $backupDate' 'Unparseable keys must be treated separately.'
Assert-Contains $source 'Send-EncryptedOffsiteCopy $encryptedArchive $currentBackupObject' 'The current encrypted backup must use the retained backup prefix.'
Assert-Contains $source 'New-BackupObjectKey ($retentionReferenceDate.AddDays(-$retentionDays - 1))' 'Harness must create an old in-prefix object.'
Assert-Contains $source '$youngRetentionObject = $currentBackupObject' 'Harness must retain the current backup as its young object.'
Assert-Contains $source 'outside-backups/2000-01-01/' 'Harness must create an old-looking object outside the owned prefix.'
Assert-Contains $source '${backupPrefix}not-a-date/' 'Harness must create an unparseable in-prefix object.'
Assert-Contains $source 'New-BackupObjectKey ($retentionReferenceDate.AddDays(1))' 'Harness must create a future-dated in-prefix object.'
Assert-Contains $source 'Invoke-RetentionPrune $retentionReferenceDate $retentionDays $false' 'Synthetic rehearsal must run dry-run explicitly.'
Assert-Contains $source 'Invoke-RetentionPrune $retentionReferenceDate $retentionDays $true' 'Synthetic rehearsal must run apply explicitly.'
Assert-Contains $source 'Assert-RetentionObjectState ''dry-run'' ''old''' 'Dry-run must inspect the old object individually.'
Assert-Contains $source 'Assert-RetentionObjectState ''dry-run'' ''young''' 'Dry-run must inspect the young object individually.'
Assert-Contains $source 'Assert-RetentionObjectState ''dry-run'' ''outside-prefix''' 'Dry-run must inspect the outside-prefix object individually.'
Assert-Contains $source 'Assert-RetentionObjectState ''dry-run'' ''unparseable''' 'Dry-run must inspect the unparseable object individually.'
Assert-Contains $source 'Assert-RetentionObjectState ''dry-run'' ''future''' 'Dry-run must inspect the future object individually.'
Assert-Contains $source 'Assert-RetentionObjectState ''apply'' ''old'' $oldRetentionObject $false' 'Apply must prove the old object was deleted.'
Assert-Contains $source 'Assert-RetentionObjectState ''apply'' ''young'' $youngRetentionObject $true' 'Apply must prove the young object remains.'
Assert-Contains $source 'Assert-RetentionObjectState ''apply'' ''outside-prefix'' $outsideRetentionObject $true' 'Apply must prove the outside-prefix object remains.'
Assert-Contains $source 'Assert-RetentionObjectState ''apply'' ''unparseable'' $invalidRetentionObject $true' 'Apply must prove the unparseable object remains.'
Assert-Contains $source 'Assert-RetentionObjectState ''apply'' ''future'' $futureRetentionObject $true' 'Apply must prove the future object remains.'
Assert-Contains $source 'retention_dry_run=PASS' 'Successful dry-run must print its retention attestation.'
Assert-Contains $source 'retention_prune=PASS' 'Successful apply must print its retention attestation.'
Assert-Contains $source 'retention_secret_output_check=PASS' 'Successful retention must attest credential-output checks.'
Assert-Contains $source 'Invoke-S3Step ''Retention object deletion''' 'Each destructive object operation must use the credential guard.'
Assert-Contains $source 'Invoke-RetentionPrune $retentionReferenceDate $retentionDays $retentionApply' 'Operator mode must honor the safe explicit apply signal.'
Assert-Contains $source '$disasterRecoveryDatabase = ''psikotes_backup_disaster_recovery''' 'Disaster recovery must use a dedicated fresh database.'
Assert-Contains $source "'source.disaster-recovery.download.age'" 'Disaster recovery must use a distinct downloaded ciphertext path.'
Assert-Contains $source "'source.disaster-recovery.dump'" 'Disaster recovery must use a distinct decrypted archive path.'
Assert-Contains $source '$rtoTargetSeconds = 8 * 3600' 'The disaster recovery RTO target must be eight hours.'
Assert-Contains $source 'function Invoke-DisasterRecovery' 'Synthetic and operator targets must share one disaster recovery function.'
Assert-Contains $source 'offsite/$s3Bucket/$currentBackupObject' 'Disaster recovery must download the retained current backup object.'
Assert-Contains $source 'Invoke-S3Step ''Disaster recovery download stage''' 'The recovery download must use the S3 credential guard.'
Assert-Contains $source 'Assert-AgeCiphertext $disasterRecoveryEncryptedArchive' 'The recovered ciphertext must pass the age structural guard.'
Assert-Contains $source 'Test-Path -LiteralPath $disasterRecoveryEncryptedArchive -PathType Leaf' 'The recovery download must be verified on disk.'
Assert-Contains $source '(Get-Item -LiteralPath $disasterRecoveryEncryptedArchive).Length -ne $encryptedBytes' 'The recovery download size must match the uploaded ciphertext.'
Assert-Contains $source 'Invoke-KeyStep ''Disaster recovery decrypt stage''' 'The recovery decryption must use the key output guard.'
Assert-Contains $source '/rehearsal/age -d -i /rehearsal/identity.txt' 'Disaster recovery must reuse the run identity.'
Assert-Contains $source 'Test-Path -LiteralPath $disasterRecoveryDumpArchive -PathType Leaf' 'The decrypted recovery archive must be verified on disk.'
Assert-Contains $source '(Get-Item -LiteralPath $disasterRecoveryDumpArchive).Length -ne $archiveBytes' 'The decrypted recovery archive size must match the original dump.'
Assert-Contains $source 'pg_restore --list /rehearsal/source.disaster-recovery.dump' 'The decrypted recovery archive must be inspected before restore.'
Assert-Contains $source 'Invoke-Psql ''postgres'' "CREATE DATABASE $disasterRecoveryDatabase"' 'Recovery database creation must not depend on the lost source database.'
Assert-Contains $source '--dbname $disasterRecoveryDatabase --no-owner --single-transaction --exit-on-error' 'Disaster recovery restore must be atomic and fail once.'
Assert-Contains $source 'if ($disasterRecoveryCounts -cne ''1|1|1|1|1|9'')' 'Recovery readiness must prove the real integrity graph.'
Assert-Contains $source '$disasterRecoveryTimer = [System.Diagnostics.Stopwatch]::StartNew()' 'RTO timing must start immediately before recovery work.'
Assert-Contains $source '$disasterRecoveryTimer.Stop()' 'RTO timing must stop after readiness succeeds.'
Assert-Contains $source '[Math]::Ceiling($disasterRecoveryTimer.Elapsed.TotalSeconds)' 'RTO evidence must report measured whole seconds.'
Assert-Contains $source 'Assert-RtoWithinTarget $disasterRecoveryActualSeconds $rtoTargetSeconds' 'Measured recovery time must enforce the target.'
Assert-Contains $source 'disaster_recovery=PASS rto_target_seconds=$rtoTargetSeconds rto_actual_seconds=$disasterRecoveryActualSeconds within_target=$disasterRecoveryWithinTarget' 'Successful recovery must print target, actual, and comparison.'
Assert-Contains $source 'Get-SchemaManifest $disasterRecoveryDatabase' 'Recovery must compute a schema manifest.'
Assert-Contains $source 'Get-DataFingerprint $disasterRecoveryDatabase' 'Recovery must compute a data fingerprint.'
Assert-Contains $source 'Get-SequenceFingerprint $disasterRecoveryDatabase' 'Recovery must compute a sequence fingerprint.'
Assert-Contains $source '$disasterRecoverySchemaFingerprint -cne $sourceSchemaFingerprint' 'Recovery schema must match the source exactly.'
Assert-Contains $source '$disasterRecoveryDataFingerprint -cne $sourceDataFingerprint' 'Recovery data must match the source exactly.'
Assert-Contains $source '$disasterRecoverySequenceFingerprint -cne $sourceSequenceFingerprint' 'Recovery sequence state must match the source exactly.'
Assert-Contains $source 'disaster_recovery_fingerprints=PASS' 'Successful recovery must print explicit fingerprint evidence.'
Assert-Contains $source 'disaster_recovery_secret_output_check=PASS' 'Successful recovery must attest credential and key output checks.'
Assert-Contains $source 'Disaster recovery download stage failed:' 'Download failures must identify their stage.'
Assert-Contains $source 'Disaster recovery decrypt stage failed:' 'Decryption failures must identify their stage.'
Assert-Contains $source 'Disaster recovery restore stage failed:' 'Restore failures must identify their stage.'
Assert-Contains $source 'Disaster recovery readiness stage failed:' 'Readiness and fingerprint failures must identify their stage.'
Assert-Contains $source 'function Assert-RtoWithinTarget' 'RTO enforcement must be independently testable.'

$recoveryAst = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -ceq 'Invoke-DisasterRecovery'
}, $true)
Assert-True ($null -ne $recoveryAst) 'Runner must define one shared disaster recovery path.'
$recoverySource = $recoveryAst.Extent.Text
Assert-True (-not $recoverySource.Contains('$offsiteObject')) 'Disaster recovery must not use the connection-rehearsal object.'
Assert-True (-not $recoverySource.Contains('$encryptedArchive')) 'Disaster recovery must not restore from the local encrypted archive.'
$retentionEvidenceOffset = $source.IndexOf('retention_secret_output_check=PASS', [StringComparison]::Ordinal)
$recoveryInvocationOffset = $source.LastIndexOf('Invoke-DisasterRecovery', [StringComparison]::Ordinal)
Assert-True ($recoveryInvocationOffset -gt $retentionEvidenceOffset) 'Disaster recovery must run after retention evidence succeeds.'

$rtoGuardAst = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -ceq 'Assert-RtoWithinTarget'
}, $true)
Assert-True ($null -ne $rtoGuardAst) 'Runner must define the RTO enforcement helper.'
. ([scriptblock]::Create($rtoGuardAst.Extent.Text))
Assert-RtoWithinTarget 28800 28800
$rtoExceededRejected = $false
try { Assert-RtoWithinTarget 28801 28800 }
catch { $rtoExceededRejected = $_.Exception.Message.Contains('exceeded RTO target') }
Assert-True $rtoExceededRejected 'RTO enforcement must fail when measured recovery exceeds the target.'

$writerAst = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -ceq 'New-BackupObjectKey'
}, $true)
Assert-True ($null -ne $writerAst) 'Runner must define the shared backup-key writer.'
$parserAst = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -ceq 'Get-BackupObjectDate'
}, $true)
Assert-True ($null -ne $parserAst) 'Runner must define the shared backup-key parser.'
$backupKeyTemplate = 'backups/{date}/{name}.dump.age'
. ([scriptblock]::Create($writerAst.Extent.Text))
. ([scriptblock]::Create($parserAst.Extent.Text))
$contractDate = [DateTime]::SpecifyKind([DateTime] '2026-09-21', [DateTimeKind]::Utc)
$contractKey = New-BackupObjectKey $contractDate 'contract-check'
Assert-True ($contractKey -ceq 'backups/2026-09-21/contract-check.dump.age') 'Backup-key writer must use the one declared format.'
Assert-True ((Get-BackupObjectDate $contractKey) -eq $contractDate) 'Backup-key parser must round-trip the writer output.'
Assert-True ($null -eq (Get-BackupObjectDate 'backups/not-a-date/contract-check.dump.age')) 'Parser must reject non-date keys.'
Assert-True ($null -eq (Get-BackupObjectDate 'backups/2026-02-30/contract-check.dump.age')) 'Parser must reject impossible calendar dates.'

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

Write-Output 'postgres-backup-restore-contract: PASS (170 assertions)'
