<?php

declare(strict_types=1);

use App\Actions\AssessmentSessions\StartAssessmentSession;
use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedIstResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Services\AssessmentResults\LoadPersistedIstResult;
use App\Services\AssessmentResults\PersistSealedIstResult;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\FakePaymentProvider;
use App\Security\RlsContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
const IST_CODES = ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'];
const REPORT_SEMANTICS = 'BASELINE_ONLY_NO_SLO';

/** @param list<float|int> $values */
function percentile(array $values, float $quantile): float
{
    if ($values === [] || ! is_finite($quantile) || $quantile <= 0 || $quantile > 1) {
        throw new RuntimeException('Invalid percentile input.');
    }
    sort($values, SORT_NUMERIC);
    $index = max(0, (int) ceil(count($values) * $quantile) - 1);

    return round((float) $values[$index], 6);
}

/** @param array<string,mixed> $value */
function emit(array $value): never
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (($argv[1] ?? '') === '--self-test') {
    emit([
        'p50_ms' => percentile([1, 2, 3, 4, 5], 0.50),
        'p95_ms' => percentile([1, 2, 3, 4, 5], 0.95),
        'p99_ms' => percentile([1, 2, 3, 4, 5], 0.99),
        'error_rate' => 1 / 5,
        'semantics' => REPORT_SEMANTICS,
    ]);
}

if (! is_file('/.dockerenv') || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('The load harness requires a disposable Linux container with pcntl.');
}
$runId = getenv('F9_LOAD_RUN_ID');
$outputDirectory = getenv('F9_LOAD_OUTPUT');
if (! is_string($runId) || preg_match('/\A[a-f0-9]{32}\z/D', $runId) !== 1
    || ! is_string($outputDirectory) || ! is_dir($outputDirectory)) {
    throw new RuntimeException('Use the bounded F9 load runner; direct execution is refused.');
}

$options = getopt('', ['concurrency:', 'warmup:', 'iterations:', 'start-ops:', 'reader-ops:']);
$profiles = array_map('intval', explode(',', (string) ($options['concurrency'] ?? '')));
$warmupOperations = filter_var($options['warmup'] ?? null, FILTER_VALIDATE_INT);
$iterations = filter_var($options['iterations'] ?? null, FILTER_VALIDATE_INT);
$startOperations = filter_var($options['start-ops'] ?? null, FILTER_VALIDATE_INT);
$readerOperations = filter_var($options['reader-ops'] ?? null, FILTER_VALIDATE_INT);
if ($profiles !== [1, 4, 8] || $warmupOperations !== 32 || $iterations !== 3
    || $startOperations !== 64 || $readerOperations !== 256) {
    throw new RuntimeException('The fixed workload contract does not match the reviewed baseline profile.');
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $directory) {
    $path = $app->storagePath($directory);
    if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
        throw new RuntimeException('Unable to initialize disposable storage.');
    }
}
$app->useEnvironmentPath('/tmp/f9-load-no-env');
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    $config = $app->make('config');
    if ($config->get('app.env') !== 'testing' || $app->configurationIsCached()) {
        throw new RuntimeException('Load runner requires uncached testing configuration.');
    }
    $config->set('database.default', 'pgsql');
    $config->set('database.connections', ['pgsql' => [
        'driver' => 'pgsql', 'host' => 'f9-load-db', 'port' => 5432,
        'database' => 'psikotes_f9_load', 'username' => 'f9_load_owner',
        'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        'sslmode' => 'disable',
    ]]);
    $config->set('database.redis', []);
    $config->set('cache.default', 'array');
    $config->set('queue.default', 'sync');
    $config->set('session.driver', 'array');
    $config->set('logging.default', 'null');
});
$app->booting(function (Application $app): void {
    $app->instance(PaymentProvider::class, new FakePaymentProvider);
    $app->instance(Notifier::class, new FakeNotifier);
    Http::preventStrayRequests();
    Mail::fake();
});
$app->make(Kernel::class)->bootstrap();

$target = DB::selectOne(<<<'SQL'
    SELECT current_database() AS database, current_user AS username,
        shobj_description(oid, 'pg_database') AS marker
    FROM pg_database WHERE datname=current_database()
    SQL);
if ($target?->database !== 'psikotes_f9_load' || $target?->username !== 'f9_load_owner'
    || $target?->marker !== 'ONCAM_F9_LOAD:'.$runId
    || DB::selectOne("SELECT to_regclass('public.migrations') AS name")?->name !== null) {
    throw new RuntimeException('Disposable database identity mismatch; migrations refused.');
}
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('Disposable database migration failed.');
}
DB::statement('ALTER ROLE psikotes_runtime LOGIN');
config()->set('database.connections.pgsql.username', 'psikotes_runtime');
DB::purge('pgsql');
$identity = DB::selectOne('SELECT current_user AS username, rolsuper, rolbypassrls FROM pg_roles WHERE rolname=current_user');
if ($identity?->username !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
    throw new RuntimeException('Measured work requires the non-superuser NOBYPASSRLS runtime role.');
}

/** @return array{start:list<array{participant:int,public_id:string}>,readers:array<string,string>} */
function seedDataset(int $startCount, int $readerCount): array
{
    return app(RlsContextRunner::class)->runAsService(function () use ($startCount, $readerCount): array {
        $stamp = '2026-09-14 00:00:00.000000+00:00';
        $code = 'F9'.strtoupper(substr((string) Str::ulid(), -18));
        $branch = DB::table('branches')->insertGetId([
            'code' => $code, 'ref_code' => $code, 'name' => 'synthetic-load-branch',
            'organization_code' => $code, 'display_name' => 'synthetic-load-branch',
        ]);
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-f9-load-v1',
            'provenance' => 'synthetic-f9-load-only', 'total_duration_seconds' => 540,
            'subtests' => array_map(static fn (string $item): array => [
                'code' => $item, 'duration_seconds' => 60, 'item_count' => 1,
            ], IST_CODES),
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $definitionJson = json_encode($definition->toArray(), JSON_THROW_ON_ERROR);
        $start = [];
        for ($index = 0; $index < $startCount; $index++) {
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'source_system' => 'F9_LOAD_SYNTHETIC',
                'full_name' => 'synthetic-participant', 'phone' => '620000000000',
            ]);
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => strtoupper((string) Str::ulid()), 'participant_id' => $participant,
                'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
                'intended_field_snapshot' => null, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $publicId = strtoupper((string) Str::ulid());
            DB::table('test_sessions')->insert([
                'public_id' => $publicId, 'participant_id' => $participant,
                'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
                'authorization_id' => strtoupper((string) Str::ulid()),
                'allocation_intent_id' => strtoupper((string) Str::ulid()),
                'duration_seconds' => 540, 'status' => 'created', 'answers_revision' => 1,
                'session_definition_version' => $definition->version,
                'session_definition_provenance' => $definition->provenance,
                'session_definition_checksum' => $definition->checksum,
                'session_definition_payload' => $definitionJson,
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $start[] = ['participant' => $participant, 'public_id' => $publicId];
        }

        $sourcePayload = json_encode(['version' => 'synthetic-f9-load-v1'], JSON_THROW_ON_ERROR);
        $sourceChecksum = hash('sha256', $sourcePayload);
        $versionId = DB::table('instrument_versions')->insertGetId([
            'code' => 'ist', 'version' => 'synthetic-f9-load-v1',
            'source_file' => 'synthetic-f9-load.json', 'checksum' => $sourceChecksum,
            'payload' => $sourcePayload, 'is_active' => false,
            'created_at' => $stamp, 'updated_at' => $stamp,
        ]);
        $subtests = [];
        foreach (IST_CODES as $offset => $item) {
            $subtests[] = [
                'code' => $item, 'rawScore' => $offset + 1, 'standardScore' => 90 + $offset,
                'sourceScore' => 100 + $offset, 'level' => 3, 'category' => 'synthetic',
                'band' => ['lo' => 90, 'hi' => 109],
            ];
        }
        $readers = [];
        for ($index = 0; $index < $readerCount; $index++) {
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'source_system' => 'F9_LOAD_SYNTHETIC',
                'full_name' => 'synthetic-reader', 'phone' => '620000000000',
            ]);
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => strtoupper((string) Str::ulid()), 'participant_id' => $participant,
                'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
                'intended_field_snapshot' => null, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $sessionPublicId = strtoupper((string) Str::ulid());
            $submittedAt = sprintf('2026-09-14 00:10:%02d.123456+00:00', $index % 60);
            $session = DB::table('test_sessions')->insertGetId([
                'public_id' => $sessionPublicId, 'participant_id' => $participant,
                'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
                'authorization_id' => strtoupper((string) Str::ulid()),
                'allocation_intent_id' => strtoupper((string) Str::ulid()),
                'duration_seconds' => 540, 'status' => 'submitted', 'answers_revision' => 1,
                'started_at' => '2026-09-14 00:00:00.000000+00:00',
                'ends_at' => '2026-09-14 00:09:00.000000+00:00', 'submitted_at' => $submittedAt,
                'session_definition_version' => $definition->version,
                'session_definition_provenance' => $definition->provenance,
                'session_definition_checksum' => $definition->checksum,
                'session_definition_payload' => $definitionJson,
                'created_at' => $stamp, 'updated_at' => $submittedAt,
            ]);
            $sealedSource = SealedGenericAnswerSet::seal(
                $case, $session, $participant, $sessionPublicId, GenericAssessmentInstrument::Ist,
                1, $submittedAt, 1, $definition,
                [['item_no' => 1, 'value' => 'A', 'revision' => 1, 'answered_at' => '2026-09-14T00:05:00.123456Z']],
            );
            $result = SealedIstResult::seal($sealedSource, [
                'id' => $versionId, 'code' => 'ist', 'version' => 'synthetic-f9-load-v1',
                'sourceFile' => 'synthetic-f9-load.json', 'checksum' => $sourceChecksum,
            ], $subtests, [
                'rawTotal' => 45, 'iq' => 100, 'level' => 3, 'sourceScores' => [100],
                'category' => 'synthetic', 'band' => ['lo' => 90, 'hi' => 109],
            ]);
            $publicId = app(PersistSealedIstResult::class)->execute($result);
            $readers[$publicId] = $result->resultChecksum;
        }

        return ['start' => $start, 'readers' => $readers];
    });
}

/** @return array<string,int> */
function databaseStats(): array
{
    $row = (array) DB::selectOne(<<<'SQL'
        SELECT xact_commit,xact_rollback,tup_returned,tup_fetched,tup_inserted,tup_updated,
               tup_deleted,blks_read,blks_hit,temp_files,temp_bytes,deadlocks,conflicts
        FROM pg_stat_database WHERE datname=current_database()
        SQL);

    return array_map(static fn (mixed $value): int => (int) $value, $row);
}

/** @param array<string,int> $before @param array<string,int> $after @return array<string,int> */
function delta(array $before, array $after): array
{
    $result = [];
    foreach ($before as $key => $value) {
        $result[$key] = max(0, $after[$key] - $value);
    }

    return $result;
}

/** @param list<mixed> $work @param array<string,string> $readerChecksums @return array<string,mixed> */
function executeConcurrent(string $boundary, array $work, array $readerChecksums, int $concurrency, string $outputDirectory): array
{
    DB::disconnect();
    $chunks = array_fill(0, $concurrency, []);
    foreach ($work as $offset => $item) {
        $chunks[$offset % $concurrency][] = $item;
    }
    $prefix = $outputDirectory.'/'.bin2hex(random_bytes(8));
    $pids = [];
    $started = hrtime(true);
    foreach ($chunks as $worker => $chunk) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a load worker.');
        }
        if ($pid === 0) {
            $latencies = [];
            $errors = [];
            $queryCount = 0;
            $queryTime = 0.0;
            try {
                DB::purge('pgsql');
                DB::reconnect('pgsql');
                DB::listen(static function ($query) use (&$queryCount, &$queryTime): void {
                    $queryCount++;
                    $queryTime += (float) $query->time;
                });
                foreach ($chunk as $item) {
                    $operationStart = hrtime(true);
                    try {
                        if ($boundary === 'start') {
                            $result = app(StartAssessmentSession::class)->execute($item['participant'], $item['public_id']);
                            if (! $result->accepted || $result->replayed || $result->status !== 'in_progress'
                                || $result->sessionId !== $item['public_id'] || $result->startedAt === null
                                || $result->endsAt === null) {
                                throw new RuntimeException('START_RESULT_INVALID');
                            }
                        } elseif ($boundary === 'reader') {
                            $loaded = app(RlsContextRunner::class)->runAsService(
                                fn () => DB::transaction(fn () => app(LoadPersistedIstResult::class)->execute($item)),
                            );
                            if ($loaded->publicId !== $item
                                || $loaded->resultChecksum !== ($readerChecksums[$item] ?? null)
                                || array_column($loaded->subtests, 'code') !== IST_CODES) {
                                throw new RuntimeException('READER_RESULT_INVALID');
                            }
                        } else {
                            throw new RuntimeException('UNKNOWN_BOUNDARY');
                        }
                    } catch (Throwable $exception) {
                        $errors[] = $exception::class.':'.$exception->getMessage();
                    } finally {
                        $latencies[] = (hrtime(true) - $operationStart) / 1_000_000;
                    }
                }
                file_put_contents($prefix.'-'.$worker.'.json', json_encode([
                    'latencies' => $latencies, 'errors' => $errors,
                    'query_count' => $queryCount, 'query_time_ms' => round($queryTime, 6),
                ], JSON_THROW_ON_ERROR), LOCK_EX);
                exit($errors === [] ? 0 : 2);
            } catch (Throwable $exception) {
                file_put_contents($prefix.'-'.$worker.'.json', json_encode([
                    'latencies' => $latencies, 'errors' => [$exception::class.':'.$exception->getMessage()],
                    'query_count' => $queryCount, 'query_time_ms' => round($queryTime, 6),
                ], JSON_THROW_ON_ERROR), LOCK_EX);
                exit(3);
            }
        }
        $pids[] = $pid;
    }

    DB::reconnect('pgsql');
    $maximumConnections = 0;
    $lockWaitSamples = 0;
    $remaining = array_fill_keys($pids, true);
    while ($remaining !== []) {
        $maximumConnections = max($maximumConnections, (int) DB::scalar(
            'SELECT count(*) FROM pg_stat_activity WHERE datname=current_database()',
        ));
        $lockWaitSamples += (int) DB::scalar(<<<'SQL'
            SELECT count(*) FROM pg_locks
            WHERE NOT granted AND database=(SELECT oid FROM pg_database WHERE datname=current_database())
            SQL);
        foreach (array_keys($remaining) as $pid) {
            $status = 0;
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                unset($remaining[$pid]);
            }
        }
        if ($remaining !== []) {
            usleep(5_000);
        }
    }
    $wallMs = (hrtime(true) - $started) / 1_000_000;
    $latencies = [];
    $errors = [];
    $queryCount = 0;
    $queryTime = 0.0;
    foreach (array_keys($chunks) as $worker) {
        $path = $prefix.'-'.$worker.'.json';
        if (! is_file($path)) {
            throw new RuntimeException('A load worker did not produce metrics.');
        }
        $result = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unlink($path);
        array_push($latencies, ...$result['latencies']);
        array_push($errors, ...$result['errors']);
        $queryCount += (int) $result['query_count'];
        $queryTime += (float) $result['query_time_ms'];
    }
    $requested = count($work);
    $completed = $requested - count($errors);
    $metrics = [
        'requested' => $requested, 'completed' => $completed, 'errors' => count($errors),
        'error_rate' => $requested === 0 ? 0.0 : round(count($errors) / $requested, 8),
        'wall_ms' => round($wallMs, 6),
        'throughput_ops_s' => round($completed / ($wallMs / 1000), 6),
        'p50_ms' => percentile($latencies, 0.50), 'p95_ms' => percentile($latencies, 0.95),
        'p99_ms' => percentile($latencies, 0.99), 'max_ms' => round(max($latencies), 6),
        'query_count' => $queryCount, 'query_time_ms' => round($queryTime, 6),
        'max_connections' => $maximumConnections, 'lock_wait_samples' => $lockWaitSamples,
    ];
    foreach ($metrics as $value) {
        if ((is_float($value) && ! is_finite($value)) || (is_numeric($value) && $value < 0)) {
            throw new RuntimeException('A metric is not finite and nonnegative.');
        }
    }
    if ($metrics['requested'] !== $requested || $metrics['completed'] !== $requested
        || $metrics['errors'] !== 0 || $metrics['error_rate'] !== 0.0
        || ! ($metrics['p50_ms'] <= $metrics['p95_ms'] && $metrics['p95_ms'] <= $metrics['p99_ms']
            && $metrics['p99_ms'] <= $metrics['max_ms'])) {
        throw new RuntimeException('FIRST_ROOT_CAUSE: load operation correctness or metric invariant failed.');
    }

    return $metrics;
}

$readerDatasetSize = 64;
$startDatasetSize = count($profiles) * ($warmupOperations + ($iterations * $startOperations));
$dataset = seedDataset($startDatasetSize, $readerDatasetSize);
$startOffset = 0;
$report = [
    'semantics' => REPORT_SEMANTICS,
    'workload' => [
        'concurrency' => $profiles, 'warmup_operations_per_boundary_profile' => $warmupOperations,
        'measured_iterations' => $iterations, 'start_operations_per_iteration_profile' => $startOperations,
        'reader_operations_per_iteration_profile' => $readerOperations,
        'start_dataset_sessions' => $startDatasetSize, 'reader_dataset_ledgers' => $readerDatasetSize,
        'reader_sources_per_ledger' => 9,
    ],
    'measurements' => [],
];

foreach ($profiles as $concurrency) {
    $warmStart = array_slice($dataset['start'], $startOffset, $warmupOperations);
    $startOffset += $warmupOperations;
    executeConcurrent('start', $warmStart, $dataset['readers'], $concurrency, $outputDirectory);
    $readerIds = array_keys($dataset['readers']);
    $warmReaders = array_map(static fn (int $i): string => $readerIds[$i % count($readerIds)], range(0, $warmupOperations - 1));
    executeConcurrent('reader', $warmReaders, $dataset['readers'], $concurrency, $outputDirectory);

    foreach (range(1, $iterations) as $iteration) {
        foreach ([['start', $startOperations], ['reader', $readerOperations]] as [$boundary, $operations]) {
            $work = $boundary === 'start'
                ? array_slice($dataset['start'], $startOffset, $operations)
                : array_map(static fn (int $i): string => $readerIds[$i % count($readerIds)], range(0, $operations - 1));
            if ($boundary === 'start') {
                $startOffset += $operations;
            }
            $before = databaseStats();
            $metrics = executeConcurrent($boundary, $work, $dataset['readers'], $concurrency, $outputDirectory);
            $after = databaseStats();
            $report['measurements'][] = [
                'boundary' => $boundary, 'concurrency' => $concurrency, 'iteration' => $iteration,
                ...$metrics, 'pg_stat_database_delta' => delta($before, $after),
            ];
        }
    }
}

if ($startOffset !== $startDatasetSize) {
    throw new RuntimeException('Start workload did not consume its exact fixed dataset.');
}
$sessionCounts = (array) DB::selectOne(<<<'SQL'
    SELECT count(*) FILTER (WHERE source_system='F9_LOAD_SYNTHETIC') AS participants,
      (SELECT count(*) FROM test_sessions s JOIN participants p ON p.id=s.participant_id
       WHERE p.source_system='F9_LOAD_SYNTHETIC') AS sessions,
      (SELECT count(*) FROM test_sessions s JOIN participants p ON p.id=s.participant_id
       WHERE p.source_system='F9_LOAD_SYNTHETIC' AND s.status='in_progress'
         AND s.started_at IS NOT NULL AND s.ends_at>s.started_at) AS started,
      (SELECT count(*) FROM generic_instrument_results) AS results,
      (SELECT count(*) FROM generic_instrument_result_sources) AS sources
    FROM participants
    SQL);
$expectedParticipants = $startDatasetSize + $readerDatasetSize;
if ((int) $sessionCounts['participants'] !== $expectedParticipants
    || (int) $sessionCounts['sessions'] !== $expectedParticipants
    || (int) $sessionCounts['started'] !== $startDatasetSize
    || (int) $sessionCounts['results'] !== $readerDatasetSize
    || (int) $sessionCounts['sources'] !== $readerDatasetSize * 9) {
    throw new RuntimeException('FIRST_ROOT_CAUSE: unexpected rows or partial writes detected.');
}
foreach ([0, $warmupOperations, $startDatasetSize - 1] as $index) {
    $item = $dataset['start'][$index];
    $before = DB::table('test_sessions')->where('public_id', $item['public_id'])->sole();
    $replay = app(StartAssessmentSession::class)->execute($item['participant'], $item['public_id']);
    $after = DB::table('test_sessions')->where('public_id', $item['public_id'])->sole();
    if (! $replay->accepted || ! $replay->replayed || $replay->status !== 'in_progress'
        || (string) $before->started_at !== (string) $after->started_at
        || (string) $before->ends_at !== (string) $after->ends_at) {
        throw new RuntimeException('FIRST_ROOT_CAUSE: session replay invariant failed.');
    }
}

$report['correctness'] = [
    'participants' => (int) $sessionCounts['participants'], 'sessions' => (int) $sessionCounts['sessions'],
    'started_once' => (int) $sessionCounts['started'], 'results' => (int) $sessionCounts['results'],
    'sources' => (int) $sessionCounts['sources'], 'replay_samples' => 3,
];
emit($report);
