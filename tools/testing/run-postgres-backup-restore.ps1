param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9a-f]{40}$')]
    [string] $ExpectedCommit,

    [Parameter(Mandatory = $true)]
    [string] $VendorDirectory
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$runId = [guid]::NewGuid().ToString('N')
$network = "oncam-f9-backup-$runId"
$databaseContainer = "$network-db"
$migrationContainer = "$network-migrate"
$label = "oncam.f9-backup-restore=$runId"
$sourceDatabase = 'psikotes_backup_source'
$destinationDatabase = 'psikotes_backup_destination'
$corruptDatabase = 'psikotes_backup_corrupt'
$tempDirectory = Join-Path ([System.IO.Path]::GetTempPath()) "psikotes-f9-backup-$runId"
$snapshotArchive = Join-Path $tempDirectory 'snapshot.tar'
$dumpArchive = Join-Path $tempDirectory 'source.dump'
$identityFile = Join-Path $tempDirectory 'identity.txt'
$encryptedArchive = Join-Path $tempDirectory 'source.dump.age'
$restoredArchive = Join-Path $tempDirectory 'source.restored.dump'
$corruptArchive = Join-Path $tempDirectory 'source.corrupt.dump.age'
$networkCreated = $false
$failure = $null
$identitySecret = $null
$keyOutputChecks = 0

function Assert-NativeSuccess([string] $operation) {
    if ($LASTEXITCODE -ne 0) {
        throw "$operation failed; no live or application resource was targeted."
    }
}

function Assert-OutputExcludesKey([string] $output, [string] $secret) {
    if ($output.Contains($secret)) {
        throw 'A key-touching step exposed the identity in its output.'
    }
}

function Invoke-KeyStep([string] $operation, [scriptblock] $command, [bool] $requireSuccess = $true) {
    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $captured = & $command 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    $output = ($captured | ForEach-Object { [string] $_ }) -join "`n"
    Assert-OutputExcludesKey $output $identitySecret
    $script:keyOutputChecks++
    if ($requireSuccess -and $exitCode -ne 0) {
        throw "$operation failed with exit code $exitCode."
    }
    return @{ Output = $output; ExitCode = $exitCode }
}

function Invoke-Psql([string] $database, [string] $sql) {
    $output = docker exec $databaseContainer psql --host 127.0.0.1 --username f9_backup_owner `
        --dbname $database --no-psqlrc --tuples-only --no-align --set ON_ERROR_STOP=1 `
        --command $sql
    Assert-NativeSuccess "PostgreSQL query on $database"

    return (($output | ForEach-Object { [string] $_ }) -join "`n").Trim()
}

function Get-TextSha256([string] $text) {
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($text)
    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        return (($sha256.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join '')
    }
    finally {
        $sha256.Dispose()
    }
}

function ConvertTo-StableSchemaExpression([string] $expression) {
    $literalPattern = "'(?:''|[^'])*'"
    $sourceItemPattern = "$literalPattern::character varying"
    $restoredItemPattern = "\($literalPattern::character varying\)::text"
    $sourceArrayPattern = "\(ARRAY\[(?<items>\s*$sourceItemPattern(?:\s*,\s*$sourceItemPattern)*)\]\)::text\[\]"
    $restoredArrayPattern = "ARRAY\[(?<items>\s*$restoredItemPattern(?:\s*,\s*$restoredItemPattern)*)\]"
    $canonicalizeArray = {
        param([System.Text.RegularExpressions.Match] $match)

        $literals = [regex]::Matches($match.Groups['items'].Value, $literalPattern) |
            ForEach-Object { $_.Value }
        return 'ARRAY[' + ($literals -join ', ') + ']::text[]'
    }.GetNewClosure()

    $stable = [regex]::Replace($expression, $sourceArrayPattern, $canonicalizeArray)
    return [regex]::Replace($stable, $restoredArrayPattern, $canonicalizeArray)
}

function ConvertTo-StableSchemaManifest([string] $manifest) {
    $canonicalizeExpression = {
        param([System.Text.RegularExpressions.Match] $match)

        $expressionBytes = [Convert]::FromBase64String($match.Groups['payload'].Value)
        $expression = [System.Text.Encoding]::UTF8.GetString($expressionBytes)
        $stableExpression = ConvertTo-StableSchemaExpression $expression
        $stableBytes = [System.Text.Encoding]::UTF8.GetBytes($stableExpression)
        return 'F9EXPR{' + [Convert]::ToBase64String($stableBytes) + '}'
    }

    return [regex]::Replace($manifest, 'F9EXPR\{(?<payload>[A-Za-z0-9+/=]*)\}', $canonicalizeExpression)
}

function Get-SchemaManifest([string] $database) {
    $manifest = Invoke-Psql $database @'
WITH objects(kind, identity, definition) AS (
    SELECT 'relation', format('%I.%I', n.nspname, c.relname),
        concat_ws('|', n.nspname, c.relname, c.relkind, c.relpersistence,
            c.relrowsecurity, c.relforcerowsecurity, pg_get_userbyid(c.relowner))
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname IN ('public', 'dass') AND c.relkind IN ('r', 'p', 'S')
    UNION ALL
    SELECT 'column', format('%I.%I.%s', n.nspname, c.relname, a.attnum),
        format('%I|%I|%I|%s|%s|%s', n.nspname, c.relname, a.attname,
            format_type(a.atttypid, a.atttypmod), a.attnotnull,
            COALESCE(pg_get_expr(d.adbin, d.adrelid), ''))
    FROM pg_attribute a
    JOIN pg_class c ON c.oid = a.attrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
    WHERE n.nspname IN ('public', 'dass') AND c.relkind IN ('r', 'p')
      AND a.attnum > 0 AND NOT a.attisdropped
    UNION ALL
    SELECT 'constraint', format('%I.%I', n.nspname, x.conname),
        concat_ws('|', n.nspname, c.relname, x.contype, x.conkey::text,
            COALESCE(x.confrelid::regclass::text, ''), COALESCE(x.confkey::text, ''),
            x.confupdtype, x.confdeltype, x.confmatchtype, x.condeferrable,
            x.condeferred, x.convalidated,
            CASE WHEN x.contype='c' THEN 'F9EXPR{' || replace(encode(convert_to(
                COALESCE(pg_get_expr(x.conbin, x.conrelid), ''), 'UTF8'), 'base64'), E'\n', '') || '}'
                ELSE '' END)
    FROM pg_constraint x
    JOIN pg_class c ON c.oid = x.conrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname IN ('public', 'dass')
    UNION ALL
    SELECT 'index', format('%I.%I', n.nspname, index_relation.relname),
        concat_ws('|', n.nspname, table_relation.relname, access_method.amname,
            index_row.indkey::text, index_row.indclass::text, index_row.indcollation::text,
            index_row.indisunique, index_row.indisprimary, index_row.indisexclusion,
            index_row.indisvalid, index_row.indisready, index_row.indislive,
            index_row.indisclustered, index_row.indisreplident,
            'F9EXPR{' || replace(encode(convert_to(
                COALESCE(pg_get_expr(index_row.indexprs, index_row.indrelid), ''),
                'UTF8'), 'base64'), E'\n', '') || '}',
            'F9EXPR{' || replace(encode(convert_to(
                COALESCE(pg_get_expr(index_row.indpred, index_row.indrelid), ''),
                'UTF8'), 'base64'), E'\n', '') || '}')
    FROM pg_index index_row
    JOIN pg_class index_relation ON index_relation.oid = index_row.indexrelid
    JOIN pg_class table_relation ON table_relation.oid = index_row.indrelid
    JOIN pg_namespace n ON n.oid = table_relation.relnamespace
    JOIN pg_am access_method ON access_method.oid = index_relation.relam
    WHERE n.nspname IN ('public', 'dass')
    UNION ALL
    SELECT 'trigger', format('%I.%I', n.nspname, t.tgname), pg_get_triggerdef(t.oid, false)
    FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname IN ('public', 'dass') AND NOT t.tgisinternal
    UNION ALL
    SELECT 'policy', format('%I.%I', schemaname, policyname),
        concat_ws('|', schemaname, tablename, policyname, permissive, cmd, roles::text,
            'F9EXPR{' || replace(encode(convert_to(COALESCE(qual, ''), 'UTF8'),
                'base64'), E'\n', '') || '}',
            'F9EXPR{' || replace(encode(convert_to(COALESCE(with_check, ''), 'UTF8'),
                'base64'), E'\n', '') || '}')
    FROM pg_policies WHERE schemaname IN ('public', 'dass')
    UNION ALL
    SELECT 'function', format('%I.%I(%s)', n.nspname, p.proname,
            pg_get_function_identity_arguments(p.oid)), pg_get_functiondef(p.oid)
    FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname IN ('public', 'dass', 'app_private')
    UNION ALL
    SELECT 'acl', format('%I.%I.%s.%s', n.nspname, c.relname,
            CASE WHEN a.grantee=0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END,
            a.privilege_type),
        concat_ws('|', pg_get_userbyid(a.grantor),
            CASE WHEN a.grantee=0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END,
            a.privilege_type, a.is_grantable)
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    CROSS JOIN LATERAL aclexplode(c.relacl) a
    WHERE n.nspname IN ('public', 'dass') AND c.relkind IN ('r', 'p', 'S')
      AND c.relacl IS NOT NULL
)
SELECT string_agg(kind || '|' || identity || '|' || definition, E'\n'
    ORDER BY kind, identity, definition) FROM objects;
'@
    return ConvertTo-StableSchemaManifest $manifest
}

function Get-SchemaFingerprint([string] $database) {
    return Get-TextSha256 (Get-SchemaManifest $database)
}

function Get-DataFingerprint([string] $database) {
    $dataManifest = Invoke-Psql $database @'
SELECT jsonb_build_object(
    'branches', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM branches t),
    'participants', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM participants t),
    'assessment_cases', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM assessment_cases t),
    'test_sessions', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM test_sessions t),
    'instrument_versions', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM instrument_versions t),
    'results', (SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM generic_instrument_results t),
    'sources', (SELECT jsonb_agg(to_jsonb(t) ORDER BY result_id, ordinal) FROM generic_instrument_result_sources t)
)::text;
'@
    return Get-TextSha256 $dataManifest
}

function Get-SequenceFingerprint([string] $database) {
    $sequenceManifest = Invoke-Psql $database @'
SELECT string_agg(format('%I.%I|%s|%s|%s|%s|%s|%s|%s|%s',
        schemaname, sequencename, data_type, start_value, min_value, max_value,
        increment_by, cycle, cache_size,
        query_to_xml(format('SELECT last_value, is_called FROM %I.%I', schemaname, sequencename),
            false, true, '')::text), E'\n'
    ORDER BY schemaname, sequencename)
FROM pg_sequences WHERE schemaname IN ('public', 'dass');
'@
    return Get-TextSha256 $sequenceManifest
}

try {
    $actualCommit = (git -C $workspace rev-parse HEAD).Trim()
    Assert-NativeSuccess 'Git revision resolution'
    if ($actualCommit -cne $ExpectedCommit) {
        throw "Expected commit $ExpectedCommit does not match clean snapshot $actualCommit."
    }
    $dirty = git -C $workspace status --porcelain=v1 --untracked-files=all
    Assert-NativeSuccess 'Git worktree inspection'
    if ($dirty) { throw 'Backup/restore rehearsal requires a clean tracked and untracked worktree.' }

    $resolvedVendor = (Resolve-Path -LiteralPath $VendorDirectory).Path
    if (-not (Test-Path -LiteralPath (Join-Path $resolvedVendor 'autoload.php') -PathType Leaf) -or -not (Test-Path -LiteralPath (Join-Path $resolvedVendor 'composer/installed.json') -PathType Leaf)) {
        throw 'VendorDirectory must be a real Composer vendor installation.'
    }

    docker image inspect postgres:17.6-alpine --format '{{.Id}}' | Out-Null
    Assert-NativeSuccess 'PostgreSQL image check'
    docker image inspect psikotes-app:dev --format '{{.Id}}' | Out-Null
    Assert-NativeSuccess 'Application image check'

    [void] (New-Item -ItemType Directory -Path $tempDirectory)
    $resolvedTemp = (Resolve-Path -LiteralPath $tempDirectory).Path
    $expectedTempParent = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\')
    $actualTempParent = [System.IO.Directory]::GetParent($resolvedTemp).FullName.TrimEnd('\')
    if ($actualTempParent -cne $expectedTempParent -or [System.IO.Path]::GetFileName($resolvedTemp) -cne "psikotes-f9-backup-$runId" -or $resolvedTemp.StartsWith($workspace, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Task temp directory must remain outside the repository.'
    }
    git -C $workspace archive --format=tar --output=$snapshotArchive $ExpectedCommit
    Assert-NativeSuccess 'Immutable Git snapshot creation'

    docker run --rm --pull=never --label $label --network bridge `
        --mount "type=bind,source=$tempDirectory,target=/rehearsal" `
        --entrypoint sh postgres:17.6-alpine -euc `
        'apk add --no-cache age >/dev/null; cp "$(command -v age)" /rehearsal/age; cp "$(command -v age-keygen)" /rehearsal/age-keygen'
    Assert-NativeSuccess 'Ephemeral age tooling installation'
    if (-not (Test-Path -LiteralPath (Join-Path $tempDirectory 'age') -PathType Leaf) -or
        -not (Test-Path -LiteralPath (Join-Path $tempDirectory 'age-keygen') -PathType Leaf)) {
        throw 'Ephemeral age tooling is missing.'
    }

    docker network create --internal --label $label $network | Out-Null
    Assert-NativeSuccess 'Internal rehearsal network creation'
    $networkCreated = $true

    docker run --detach --pull=never --name $databaseContainer --label $label `
        --network $network --network-alias f9-backup-db --tmpfs /var/lib/postgresql/data:rw `
        --mount "type=bind,source=$tempDirectory,target=/rehearsal" `
        --env POSTGRES_USER=f9_backup_owner --env POSTGRES_DB=$sourceDatabase `
        --env POSTGRES_HOST_AUTH_METHOD=trust postgres:17.6-alpine | Out-Null
    Assert-NativeSuccess 'Disposable PostgreSQL creation'

    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        docker exec $databaseContainer pg_isready --host 127.0.0.1 `
            --username f9_backup_owner --dbname $sourceDatabase | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Disposable PostgreSQL did not become ready.' }

    $providedIdentity = [Environment]::GetEnvironmentVariable('F9_BACKUP_AGE_IDENTITY')
    if ([string]::IsNullOrEmpty($providedIdentity)) {
        $previousPreference = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $keygenOutput = docker exec $databaseContainer /rehearsal/age-keygen -o /rehearsal/identity.txt 2>&1
            $keygenExit = $LASTEXITCODE
        }
        finally {
            $ErrorActionPreference = $previousPreference
        }
        if ($keygenExit -ne 0) { throw "Synthetic age identity generation failed with exit code $keygenExit." }
        $keyMode = 'synthetic'
    }
    else {
        if ($providedIdentity -cnotmatch '^AGE-SECRET-KEY-1[023456789ACDEFGHJKMNPQRSTUVWXYZ]+$') {
            throw 'F9_BACKUP_AGE_IDENTITY must contain one native age identity.'
        }
        [System.IO.File]::WriteAllText($identityFile, "$providedIdentity`n", [System.Text.UTF8Encoding]::new($false))
        $keygenOutput = @()
        $keyMode = 'operator'
    }
    if (-not (Test-Path -LiteralPath $identityFile -PathType Leaf)) { throw 'Age identity file is missing.' }
    $identitySecret = ((Get-Content -LiteralPath $identityFile) | Where-Object { $_ -match '^AGE-SECRET-KEY-' } | Select-Object -First 1)
    if ([string]::IsNullOrEmpty($identitySecret) -or $identitySecret -cnotmatch '^AGE-SECRET-KEY-1[023456789ACDEFGHJKMNPQRSTUVWXYZ]+$') {
        throw 'Age identity file does not contain one valid native identity.'
    }
    Assert-OutputExcludesKey (($keygenOutput | ForEach-Object { [string] $_ }) -join "`n") $identitySecret
    $keyOutputChecks++
    $recipientStep = Invoke-KeyStep 'Age recipient derivation' {
        docker exec $databaseContainer /rehearsal/age-keygen -y /rehearsal/identity.txt
    }
    $recipient = $recipientStep.Output.Trim()
    if ($recipient -cnotmatch '^age1[023456789acdefghjklmnpqrstuvwxyz]+$') {
        throw 'Age recipient derivation returned an invalid public key.'
    }

    Get-Content -LiteralPath (Join-Path $workspace 'database/schema/postgres_roles.sql') -Raw |
        docker exec --interactive $databaseContainer psql --host 127.0.0.1 `
            --username f9_backup_owner --dbname $sourceDatabase --no-psqlrc --set ON_ERROR_STOP=1
    Assert-NativeSuccess 'Runtime-role initialization'
    [void] (Invoke-Psql $sourceDatabase "COMMENT ON DATABASE $sourceDatabase IS 'ONCAM_F9_BACKUP_SOURCE:$runId'")

    $migrationCommand = @(
        'run', '--rm', '--pull=never', '--name', $migrationContainer, '--label', $label,
        '--network', $network,
        '--mount', "type=bind,source=$tempDirectory,target=/rehearsal",
        '--mount', "type=bind,source=$resolvedVendor,target=/runtime-vendor,readonly",
        '--workdir', '/rehearsal',
        '--env', 'APP_ENV=testing',
        '--env', 'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        '--env', 'DB_HOST=f9-backup-db',
        '--env', "DB_DATABASE=$sourceDatabase",
        '--env', 'DB_MIGRATION_USERNAME=f9_backup_owner',
        '--env', 'DB_MIGRATION_PASSWORD=',
        '--env', 'DB_RUNTIME_USERNAME=psikotes_runtime',
        '--env', 'DB_RUNTIME_PASSWORD=',
        '--entrypoint', 'sh', 'psikotes-app:dev', '-euc',
        'mkdir -p /tmp/rehearsal-app/vendor; tar -xf /rehearsal/snapshot.tar -C /tmp/rehearsal-app; cp /runtime-vendor/autoload.php /tmp/rehearsal-app/vendor/; cp -a /runtime-vendor/composer /tmp/rehearsal-app/vendor/; for dependency in /runtime-vendor/*; do name=$(basename "$dependency"); if [ "$name" != autoload.php ] && [ "$name" != composer ]; then ln -s "$dependency" "/tmp/rehearsal-app/vendor/$name"; fi; done; mkdir -p /tmp/rehearsal-app/storage/framework/cache/data /tmp/rehearsal-app/storage/framework/sessions /tmp/rehearsal-app/storage/framework/views /tmp/rehearsal-app/storage/logs /tmp/rehearsal-app/bootstrap/cache; cd /tmp/rehearsal-app; php artisan migrate --database=pgsql_migration --force --no-interaction'
    )
    docker @migrationCommand
    Assert-NativeSuccess 'Exact-baseline source migration'

    $syntheticSql = @'
BEGIN;
SELECT set_config('app.role', 'service', true);
WITH branch_row AS (
    INSERT INTO branches (code, name, ref_code, organization_code, display_name)
    VALUES ('F9-SYNTHETIC', 'F9 Synthetic Branch', 'F9-SYNTHETIC', 'F9-SYNTHETIC', 'F9 Synthetic Branch')
    RETURNING id
), participant_row AS (
    INSERT INTO participants (branch_id, referral_branch_id, referral_source, source_system, full_name, phone)
    SELECT id, id, 'default', 'F9_BACKUP_REHEARSAL', 'Synthetic Recovery Participant', '620000000000'
    FROM branch_row RETURNING id, branch_id
), case_row AS (
    INSERT INTO assessment_cases
        (public_id, participant_id, organization_id, package_id, origin, intended_field_snapshot, created_at, updated_at)
    SELECT '01J00000000000000000000001', id, branch_id, NULL, 'DIRECT_PUBLIC', 'UMUM',
        '2026-09-14 00:00:00+00', '2026-09-14 00:00:00+00'
    FROM participant_row RETURNING id, participant_id
), version_row AS (
    INSERT INTO instrument_versions
        (code, version, source_file, checksum, payload, is_active, created_at, updated_at)
    VALUES ('ist', 'f9-synthetic-v1', 'f9-synthetic-ist.json', repeat('1', 64),
        '{"version":"f9-synthetic-v1"}'::jsonb, false,
        '2026-09-14 00:00:00+00', '2026-09-14 00:00:00+00') RETURNING id
), session_row AS (
    INSERT INTO test_sessions
        (public_id, participant_id, assessment_case_id, test_type, attempt_no, authorization_id,
         allocation_intent_id, duration_seconds, status, answers_revision, started_at, ends_at,
         submitted_at, session_definition_version, session_definition_provenance,
         session_definition_checksum, session_definition_payload, created_at, updated_at)
    SELECT '01J00000000000000000000002', c.participant_id, c.id, 'ist', 1,
        '01J00000000000000000000003', '01J00000000000000000000004', 1800, 'submitted', 1,
        '2026-09-14 00:00:00+00', '2026-09-14 00:30:00+00', '2026-09-14 00:20:00+00',
        'f9-definition-v1', 'f9-local-synthetic', repeat('2', 64),
        '{"instrument":"ist","version":"f9-definition-v1","provenance":"f9-local-synthetic","checksum":"2222222222222222222222222222222222222222222222222222222222222222","total_duration_seconds":1800,"subtests":[{"code":"SE","duration_seconds":1800,"item_count":1}],"randomization":"fixed","seed":null,"generator":null}'::jsonb,
        '2026-09-14 00:00:00+00', '2026-09-14 00:20:00+00'
    FROM case_row c RETURNING id, public_id, participant_id, assessment_case_id, submitted_at
), result_row AS (
    INSERT INTO generic_instrument_results
        (public_id, assessment_case_id, session_id, participant_id, session_public_id,
         instrument_code, attempt_no, submitted_at, answers_revision, sealed_source_checksum,
         session_definition_version, session_definition_provenance, session_definition_checksum,
         session_definition_payload, instrument_version_id, instrument_version,
         instrument_source_file, instrument_checksum, result_contract_version, result_payload,
         result_checksum, created_at)
    SELECT '01J00000000000000000000005', s.assessment_case_id, s.id, s.participant_id, s.public_id,
        'ist', 1, s.submitted_at, 1, repeat('3', 64), 'f9-definition-v1',
        'f9-local-synthetic', repeat('2', 64),
        '{"instrument":"ist","version":"f9-definition-v1","provenance":"f9-local-synthetic","checksum":"2222222222222222222222222222222222222222222222222222222222222222","total_duration_seconds":1800,"subtests":[{"code":"SE","duration_seconds":1800,"item_count":1}],"randomization":"fixed","seed":null,"generator":null}'::jsonb,
        v.id, 'f9-synthetic-v1', 'f9-synthetic-ist.json', repeat('1', 64),
        'ist-result:v1', '{"resultContractVersion":"ist-result:v1","iq":100}'::jsonb,
        repeat('4', 64), '2026-09-14 00:20:01+00'
    FROM session_row s CROSS JOIN version_row v RETURNING id
)
INSERT INTO generic_instrument_result_sources
    (result_id, ordinal, source_code, raw_score, standard_score, source_score,
     level, category, band_low, band_high, created_at)
SELECT r.id, s.ordinal, s.source_code, s.ordinal, 90 + s.ordinal, 100 + s.ordinal,
    3, 'synthetic', 90, 109, '2026-09-14 00:20:01+00'
FROM result_row r CROSS JOIN (VALUES
    (1, 'SE'), (2, 'WA'), (3, 'AN'), (4, 'GE'), (5, 'RA'),
    (6, 'ZR'), (7, 'FA'), (8, 'WU'), (9, 'ME')
) AS s(ordinal, source_code);
COMMIT;
'@
    $syntheticSql | docker exec --interactive $databaseContainer psql --host 127.0.0.1 `
        --username f9_backup_owner --dbname $sourceDatabase --no-psqlrc --set ON_ERROR_STOP=1
    Assert-NativeSuccess 'Bounded synthetic integrity graph creation'

    $graphCounts = Invoke-Psql $sourceDatabase @'
SELECT concat_ws('|',
    (SELECT count(*) FROM branches WHERE code='F9-SYNTHETIC'),
    (SELECT count(*) FROM participants WHERE source_system='F9_BACKUP_REHEARSAL'),
    (SELECT count(*) FROM assessment_cases WHERE public_id='01J00000000000000000000001'),
    (SELECT count(*) FROM test_sessions WHERE public_id='01J00000000000000000000002'),
    (SELECT count(*) FROM generic_instrument_results WHERE public_id='01J00000000000000000000005'),
    (SELECT count(*) FROM generic_instrument_result_sources s JOIN generic_instrument_results r ON r.id=s.result_id
        WHERE r.public_id='01J00000000000000000000005'));
'@
    if ($graphCounts -cne '1|1|1|1|1|9') { throw "Synthetic graph is incomplete: $graphCounts" }

    $sourceSchemaManifest = Get-SchemaManifest $sourceDatabase
    $sourceSchemaFingerprint = Get-TextSha256 $sourceSchemaManifest
    $sourceDataFingerprint = Get-DataFingerprint $sourceDatabase
    $sourceSequenceFingerprint = Get-SequenceFingerprint $sourceDatabase

    $dumpTimer = [System.Diagnostics.Stopwatch]::StartNew()
    docker exec $databaseContainer pg_dump --host 127.0.0.1 --username f9_backup_owner `
        --dbname $sourceDatabase --format=custom --no-owner --file /tmp/source.dump
    Assert-NativeSuccess 'Custom-format logical dump'
    $dumpTimer.Stop()
    docker exec $databaseContainer pg_restore --list /tmp/source.dump | Out-Null
    Assert-NativeSuccess 'pg_restore --list archive inspection'
    docker cp "$databaseContainer`:/tmp/source.dump" $dumpArchive | Out-Null
    Assert-NativeSuccess 'Archive export to task temp directory'
    $archiveBytes = (Get-Item -LiteralPath $dumpArchive).Length
    if ($archiveBytes -le 0) { throw 'Logical dump archive is empty.' }

    [void] (Invoke-KeyStep 'Authenticated archive encryption' {
        docker exec $databaseContainer /rehearsal/age -r $recipient -o /rehearsal/source.dump.age /rehearsal/source.dump
    })
    if (-not (Test-Path -LiteralPath $encryptedArchive -PathType Leaf)) { throw 'Encrypted archive is missing.' }
    $encryptedBytes = (Get-Item -LiteralPath $encryptedArchive).Length
    if ($encryptedBytes -le $archiveBytes) { throw 'Encrypted archive has an implausible size.' }
    Remove-Item -LiteralPath $dumpArchive -Force
    docker exec $databaseContainer rm /tmp/source.dump
    Assert-NativeSuccess 'Container plaintext dump removal'
    if (Test-Path -LiteralPath $dumpArchive) { throw 'Plaintext export remains after encryption.' }

    [void] (Invoke-Psql $sourceDatabase "CREATE DATABASE $destinationDatabase")
    $restoreTimer = [System.Diagnostics.Stopwatch]::StartNew()
    [void] (Invoke-KeyStep 'Authenticated archive decryption' {
        docker exec $databaseContainer /rehearsal/age -d -i /rehearsal/identity.txt `
            -o /rehearsal/source.restored.dump /rehearsal/source.dump.age
    })
    if (-not (Test-Path -LiteralPath $restoredArchive -PathType Leaf) -or
        (Get-Item -LiteralPath $restoredArchive).Length -ne $archiveBytes) {
        throw 'Decrypted archive is missing or differs in size from the original.'
    }
    docker exec $databaseContainer pg_restore --list /rehearsal/source.restored.dump | Out-Null
    Assert-NativeSuccess 'Decrypted archive inspection'
    docker exec $databaseContainer pg_restore --host 127.0.0.1 --username f9_backup_owner `
        --dbname $destinationDatabase --no-owner --single-transaction --exit-on-error /rehearsal/source.restored.dump
    Assert-NativeSuccess 'Atomic destination restore'
    Remove-Item -LiteralPath $restoredArchive -Force
    $restoreTimer.Stop()

    $destinationSchemaManifest = Get-SchemaManifest $destinationDatabase
    $destinationSchemaFingerprint = Get-TextSha256 $destinationSchemaManifest
    $destinationDataFingerprint = Get-DataFingerprint $destinationDatabase
    $destinationSequenceFingerprint = Get-SequenceFingerprint $destinationDatabase
    if ($destinationSchemaFingerprint -cne $sourceSchemaFingerprint) {
        $schemaDifference = Compare-Object ($sourceSchemaManifest -split "`n") ($destinationSchemaManifest -split "`n") | Select-Object -First 12
        Write-Output 'schema_difference_begin'
        $schemaDifference | ForEach-Object { Write-Output "$($_.SideIndicator) $($_.InputObject)" }
        Write-Output 'schema_difference_end'
        throw 'Restored schema fingerprint differs from source.'
    }
    if ($destinationDataFingerprint -cne $sourceDataFingerprint) { throw 'Restored row/value fingerprint differs from source.' }
    if ($destinationSequenceFingerprint -cne $sourceSequenceFingerprint) { throw 'Restored sequence fingerprint differs from source.' }
    $destinationCounts = Invoke-Psql $destinationDatabase @'
SELECT concat_ws('|',
    (SELECT count(*) FROM branches WHERE code='F9-SYNTHETIC'),
    (SELECT count(*) FROM participants WHERE source_system='F9_BACKUP_REHEARSAL'),
    (SELECT count(*) FROM assessment_cases WHERE public_id='01J00000000000000000000001'),
    (SELECT count(*) FROM test_sessions WHERE public_id='01J00000000000000000000002'),
    (SELECT count(*) FROM generic_instrument_results WHERE public_id='01J00000000000000000000005'),
    (SELECT count(*) FROM generic_instrument_result_sources s JOIN generic_instrument_results r ON r.id=s.result_id
        WHERE r.public_id='01J00000000000000000000005'));
'@
    if ($destinationCounts -cne '1|1|1|1|1|9') { throw "Restored graph is incomplete: $destinationCounts" }

    $archive = [System.IO.File]::ReadAllBytes($encryptedArchive)
    if ($archive.Length -lt 128) { throw 'Encrypted archive is too small for a corruption probe.' }
    $truncated = [byte[]]::new([Math]::Floor($archive.Length / 2))
    [Array]::Copy($archive, $truncated, $truncated.Length)
    [System.IO.File]::WriteAllBytes($corruptArchive, $truncated)
    [void] (Invoke-Psql $sourceDatabase "CREATE DATABASE $corruptDatabase")
    $corruptStep = Invoke-KeyStep 'Damaged ciphertext decryption' {
        docker exec $databaseContainer /rehearsal/age -d -i /rehearsal/identity.txt `
            -o /rehearsal/source.corrupt.restored.dump /rehearsal/source.corrupt.dump.age
    } $false
    $corruptExitCode = $corruptStep.ExitCode
    if ($corruptExitCode -eq 0) { throw 'Damaged ciphertext was incorrectly authenticated.' }
    if (Test-Path -LiteralPath (Join-Path $tempDirectory 'source.corrupt.restored.dump')) {
        Remove-Item -LiteralPath (Join-Path $tempDirectory 'source.corrupt.restored.dump') -Force
    }
    $corruptState = Invoke-Psql $corruptDatabase @'
SELECT concat_ws('|',
    to_regclass('public.migrations') IS NULL,
    to_regclass('public.branches') IS NULL,
    to_regclass('public.generic_instrument_results') IS NULL,
    to_regclass('public.generic_instrument_result_sources') IS NULL);
'@
    if ($corruptState -cne 't|t|t|t') {
        throw "Corrupt restore left partial accepted destination state: $corruptState"
    }

    Write-Output "F9_BACKUP_RESTORE_PASS label=$label commit=$ExpectedCommit"
    Write-Output "dump_elapsed_ms=$($dumpTimer.ElapsedMilliseconds) restore_elapsed_ms=$($restoreTimer.ElapsedMilliseconds) archive_bytes=$archiveBytes encrypted_bytes=$encryptedBytes"
    Write-Output "schema_sha256=$sourceSchemaFingerprint data_sha256=$sourceDataFingerprint sequence_sha256=$sourceSequenceFingerprint graph=1|1|1|1|1|9"
    Write-Output "encryption=age key_mode=$keyMode key_output_check=PASS steps=$keyOutputChecks"
    Write-Output "corrupt_ciphertext_decrypt_exit=$corruptExitCode corrupt_partial_state=0"
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
            if ($networkLabels.'oncam.f9-backup-restore' -cne $runId) {
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
