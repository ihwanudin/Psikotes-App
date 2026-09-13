<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedIstResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\PersistSealedIstResult;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\TestCase;
use Throwable;
use UnexpectedValueException;

final class PersistSealedIstResultTest extends TestCase
{
    public function createApplication(): Application
    {
        if (! $this->isPostgresRun()) {
            return parent::createApplication();
        }

        $app = Application::getInstance();
        /** @phpstan-ignore-next-line Laravel consumes the native trait-name values despite its key-only PHPDoc. */
        $this->traitsUsedByTest = class_uses_recursive(self::class);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isPostgresRun()) {
            $runId = getenv('ORG_TEST_RUN_ID');
            $target = DB::selectOne(<<<'SQL'
                SELECT current_database() database,current_user username,
                    shobj_description(oid,'pg_database') marker
                FROM pg_database WHERE datname=current_database()
                SQL);
            $this->assertSame('psikotes_organization_test', $target->database);
            $this->assertSame('psikotes_runtime', $target->username);
            $this->assertSame('ONCAM_ORG_TEST:'.$runId, $target->marker);

            return;
        }

        OrganizationPaymentTestCase::assertSafeDatabase(
            config('app.env'), config('database.default'),
            config('database.connections.sqlite.database'), config('database.connections.sqlite.url'),
        );
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->isPostgresRun()) {
            // The disposable bootstrap owns this shared app and its handler stack;
            // the one-test container performs process-level teardown immediately afterward.
            return;
        }

        parent::tearDown();
    }

    public function test_it_appends_one_result_and_all_ordered_sources_without_owning_the_transaction(): void
    {
        $fixture = $this->fixture();
        $beforeStatus = DB::table('test_sessions')->where('id', $fixture['session'])->value('status');

        $identity = app(RlsContextRunner::class)->runAsService(function () use ($fixture): string {
            $level = DB::transactionLevel();
            $identity = app(PersistSealedIstResult::class)->execute($fixture['result']);
            $this->assertSame($level, DB::transactionLevel());

            return $identity;
        });

        $this->assertTrue(Str::isUlid($identity));
        $this->assertSame(strtoupper($identity), $identity);
        $this->assertSame($beforeStatus, DB::table('test_sessions')->where('id', $fixture['session'])->value('status'));
        $parent = DB::table('generic_instrument_results')->sole();
        $this->assertSame($identity, $parent->public_id);
        $this->assertSame($fixture['result']->resultChecksum, $parent->result_checksum);
        $this->assertSame(
            json_decode($fixture['result']->canonicalJson(), true, flags: JSON_THROW_ON_ERROR),
            json_decode($parent->result_payload, true, flags: JSON_THROW_ON_ERROR),
        );
        $sources = DB::table('generic_instrument_result_sources')->orderBy('ordinal')->get();
        $this->assertCount(9, $sources);
        foreach ($sources as $offset => $source) {
            $expected = $fixture['result']->subtests[$offset];
            $this->assertSame($offset + 1, $source->ordinal);
            $this->assertSame($expected['code'], $source->source_code);
            $this->assertSame($expected['rawScore'], $source->raw_score);
            $this->assertSame($expected['standardScore'], $source->standard_score);
            $this->assertSame($expected['sourceScore'], $source->source_score);
            $this->assertSame($expected['level'], $source->level);
            $this->assertSame($parent->created_at, $source->created_at);
        }
    }

    public function test_exact_replay_returns_original_identity_and_divergent_replay_fails_closed(): void
    {
        $fixture = $this->fixture();
        $identity = app(RlsContextRunner::class)->runAsService(
            fn (): string => app(PersistSealedIstResult::class)->execute($fixture['result']),
        );
        $replay = app(RlsContextRunner::class)->runAsService(
            fn (): string => app(PersistSealedIstResult::class)->execute($fixture['result']),
        );
        $this->assertSame($identity, $replay);
        $this->assertSame(1, DB::table('generic_instrument_results')->count());
        $this->assertSame(9, DB::table('generic_instrument_result_sources')->count());

        try {
            app(RlsContextRunner::class)->runAsService(
                fn (): string => app(PersistSealedIstResult::class)->execute(
                    $this->sealedResult($fixture['source'], $fixture['scoringSource'], 1),
                ),
            );
            $this->fail('A divergent result for the same session must fail closed.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('IST_RESULT_PERSISTENCE_CONFLICT', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('generic_instrument_results')->count());
        $this->assertSame(9, DB::table('generic_instrument_result_sources')->count());
    }

    public function test_context_and_caller_owned_transaction_are_mandatory(): void
    {
        $fixture = $this->fixture();
        foreach ([
            fn () => app(PersistSealedIstResult::class)->execute($fixture['result']),
            fn () => app(RlsContextRunner::class)->run(
                new RlsContext('participant', $fixture['branch'], $fixture['participant']),
                fn () => app(PersistSealedIstResult::class)->execute($fixture['result']),
            ),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Persistence must require its trusted service transaction.');
            } catch (LogicException $exception) {
                $this->assertSame('IST_RESULT_PERSISTENCE_CONTEXT_REQUIRED', $exception->getMessage());
            }
        }

        $contexts = app(RlsContextRunner::class);
        $current = new ReflectionProperty($contexts, 'current');
        $current->setValue($contexts, new RlsContext('service'));
        try {
            $this->assertSame(0, DB::transactionLevel());
            app(PersistSealedIstResult::class)->execute($fixture['result']);
            $this->fail('A service context without an outer transaction must be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('IST_RESULT_PERSISTENCE_CONTEXT_REQUIRED', $exception->getMessage());
        } finally {
            $current->setValue($contexts, null);
        }
        $this->assertSame(0, DB::table('generic_instrument_results')->count());
    }

    public function test_outer_rollback_and_stale_authority_leave_no_partial_rows(): void
    {
        $fixture = $this->fixture();
        try {
            app(RlsContextRunner::class)->runAsService(function () use ($fixture): never {
                app(PersistSealedIstResult::class)->execute($fixture['result']);
                throw new RuntimeException('ROLLBACK_SENTINEL');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('ROLLBACK_SENTINEL', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('generic_instrument_results')->count());
        $this->assertSame(0, DB::table('generic_instrument_result_sources')->count());

        $counterfeit = SealedGenericAnswerSet::seal(
            assessmentCaseId: $fixture['case'] + 999,
            sessionId: $fixture['session'],
            participantId: $fixture['participant'],
            sessionPublicId: $fixture['source']->sessionPublicId,
            instrument: GenericAssessmentInstrument::Ist,
            attemptNo: 1,
            submittedAt: $fixture['source']->submittedAt,
            answersRevision: 1,
            definition: $fixture['source']->definition,
            answers: $fixture['source']->answers,
        );
        try {
            app(RlsContextRunner::class)->runAsService(fn () => app(PersistSealedIstResult::class)
                ->execute($this->sealedResult($counterfeit, $fixture['scoringSource'])));
            $this->fail('Stale or cross-scope authority must fail closed.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('IST_RESULT_PERSISTENCE_INVALID', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('generic_instrument_results')->count());
        $this->assertSame(0, DB::table('generic_instrument_result_sources')->count());
    }

    /** @return array{branch:int,participant:int,case:int,session:int,source:SealedGenericAnswerSet,scoringSource:array{id:int,code:string,version:string,sourceFile:string,checksum:string},result:SealedIstResult} */
    private function fixture(): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'source_system' => 'R2_WRITER_TEST',
            'full_name' => $key, 'phone' => '620000000000',
        ]);
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-test-only', 'total_duration_seconds' => 540,
            'subtests' => array_map(static fn (string $code): array => [
                'code' => $code, 'duration_seconds' => 60, 'item_count' => 1,
            ], ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME']),
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $submittedAt = '2026-09-13 03:20:00.654321+00:00';
        $sessionPublicId = (string) Str::ulid();
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 540, 'status' => 'submitted', 'answers_revision' => 1,
            'started_at' => '2026-09-13 03:00:00.000000+00:00',
            'ends_at' => '2026-09-13 03:30:00.000000+00:00',
            'submitted_at' => $submittedAt,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-13 03:00:00.000000+00:00',
            'updated_at' => '2026-09-13 03:20:00.654321+00:00',
        ]);
        $payload = json_encode(['version' => 'synthetic-v1'], JSON_THROW_ON_ERROR);
        $instrumentVersion = DB::table('instrument_versions')->insertGetId([
            'code' => 'ist', 'version' => 'synthetic-v1', 'source_file' => 'synthetic-ist.json',
            'checksum' => hash('sha256', $payload), 'payload' => $payload,
            'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $answers = [];
        foreach (range(1, 9) as $item) {
            $answers[] = [
                'item_no' => $item, 'value' => 'A', 'revision' => 1,
                'answered_at' => '2026-09-13T03:10:00.123456Z',
            ];
        }
        $source = SealedGenericAnswerSet::seal(
            assessmentCaseId: $case, sessionId: $session, participantId: $participant,
            sessionPublicId: $sessionPublicId, instrument: GenericAssessmentInstrument::Ist,
            attemptNo: 1, submittedAt: $submittedAt, answersRevision: 1,
            definition: $definition, answers: $answers,
        );
        $scoringSource = [
            'id' => $instrumentVersion, 'code' => 'ist', 'version' => 'synthetic-v1',
            'sourceFile' => 'synthetic-ist.json', 'checksum' => hash('sha256', $payload),
        ];

        return compact('branch', 'participant', 'case', 'session', 'source', 'scoringSource')
            + ['result' => $this->sealedResult($source, $scoringSource)];
    }

    /** @param array{id:int,code:string,version:string,sourceFile:string,checksum:string} $scoringSource */
    private function sealedResult(
        SealedGenericAnswerSet $source,
        array $scoringSource,
        int $rawOffset = 0,
    ): SealedIstResult {
        $subtests = [];
        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'] as $offset => $code) {
            $raw = $offset + 1 + $rawOffset;
            $subtests[] = [
                'code' => $code, 'rawScore' => $raw, 'standardScore' => 90 + $offset,
                'sourceScore' => 100 + $offset, 'level' => 3, 'category' => 'synthetic',
                'band' => ['lo' => 90, 'hi' => 109],
            ];
        }

        return SealedIstResult::seal($source, $scoringSource, $subtests, [
            'rawTotal' => array_sum(array_column($subtests, 'rawScore')),
            'iq' => 100 + $rawOffset, 'level' => 3, 'sourceScores' => [100 + $rawOffset],
            'category' => 'synthetic', 'band' => ['lo' => 90, 'hi' => 109],
        ]);
    }

    /** PostgreSQL-authoritative writer serialization; excluded from the normal SQLite suite. */
    #[Group('sandbox')]
    public function test_two_runtime_processes_persist_one_result_and_exactly_replay_its_identity(): void
    {
        $runId = getenv('ORG_TEST_RUN_ID');
        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $runId);
        $identity = DB::selectOne(
            'SELECT current_user name,rolsuper,rolbypassrls FROM pg_roles WHERE rolname=current_user',
        );
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);

        $fixture = app(RlsContextRunner::class)->runAsService(fn (): array => $this->postgresFixture());
        DB::purge('pgsql');
        $workers = [];
        try {
            $workers[] = $this->startWorker($fixture['result']);
            $workers[] = $this->startWorker($fixture['result']);

            app(RlsContextRunner::class)->runAsService(function () use ($workers, $fixture): void {
                DB::table('test_sessions')->where('id', $fixture['session'])->lockForUpdate()->sole();
                foreach ($workers as $worker) {
                    fwrite($worker['socket'], "go\n");
                }
                foreach ($workers as $worker) {
                    $this->assertBackendWaitsOnLock($worker['backend']);
                }
            });

            $first = $this->readEvent($workers[0]['socket']);
            $second = $this->readEvent($workers[1]['socket']);
            $this->assertSame('result', $first['event'], json_encode($first, JSON_THROW_ON_ERROR));
            $this->assertSame('result', $second['event'], json_encode($second, JSON_THROW_ON_ERROR));
            $this->assertSame($first['public_id'], $second['public_id']);
            $this->assertSame(strtoupper($first['public_id']), $first['public_id']);
            $this->assertTrue(Str::isUlid($first['public_id']));

            app(RlsContextRunner::class)->runAsService(function () use ($fixture, $first): void {
                $parent = DB::table('generic_instrument_results')
                    ->where('session_id', $fixture['session'])->sole();
                $this->assertSame($first['public_id'], $parent->public_id);
                $this->assertSame(1, DB::table('generic_instrument_results')
                    ->where('session_id', $fixture['session'])->count());
                $this->assertSame(9, DB::table('generic_instrument_result_sources')
                    ->where('result_id', $parent->id)->count());
            });
        } finally {
            $this->stopWorkers($workers);
        }
    }

    /** @return array{session:int,result:SealedIstResult} */
    private function postgresFixture(): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'source_system' => 'R2_WRITER_PG_TEST',
            'full_name' => $key, 'phone' => '620000000000',
        ]);
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-pg-test-only', 'total_duration_seconds' => 540,
            'subtests' => array_map(static fn (string $code): array => [
                'code' => $code, 'duration_seconds' => 60, 'item_count' => 1,
            ], ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME']),
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $submittedAt = '2026-09-13 04:20:00.654321+00:00';
        $sessionPublicId = (string) Str::ulid();
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 540, 'status' => 'submitted', 'answers_revision' => 1,
            'started_at' => '2026-09-13 04:00:00.000000+00:00',
            'ends_at' => '2026-09-13 04:30:00.000000+00:00', 'submitted_at' => $submittedAt,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-13 04:00:00.000000+00:00',
            'updated_at' => $submittedAt,
        ]);
        $payload = json_encode(['version' => 'synthetic-pg-v1'], JSON_THROW_ON_ERROR);
        $instrumentVersion = DB::table('instrument_versions')->insertGetId([
            'code' => 'ist', 'version' => 'synthetic-pg-v1', 'source_file' => 'synthetic-pg-ist.json',
            'checksum' => hash('sha256', $payload), 'payload' => $payload,
            'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $source = SealedGenericAnswerSet::seal(
            assessmentCaseId: $case, sessionId: $session, participantId: $participant,
            sessionPublicId: $sessionPublicId, instrument: GenericAssessmentInstrument::Ist,
            attemptNo: 1, submittedAt: $submittedAt, answersRevision: 1,
            definition: $definition, answers: [[
                'item_no' => 1, 'value' => 'A', 'revision' => 1,
                'answered_at' => '2026-09-13T04:10:00.123456Z',
            ]],
        );
        $scoringSource = [
            'id' => $instrumentVersion, 'code' => 'ist', 'version' => 'synthetic-pg-v1',
            'sourceFile' => 'synthetic-pg-ist.json', 'checksum' => hash('sha256', $payload),
        ];

        return ['session' => $session, 'result' => $this->postgresSealedResult($source, $scoringSource)];
    }

    /** @param array{id:int,code:string,version:string,sourceFile:string,checksum:string} $scoringSource */
    private function postgresSealedResult(SealedGenericAnswerSet $source, array $scoringSource): SealedIstResult
    {
        $subtests = [];
        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'] as $offset => $code) {
            $subtests[] = [
                'code' => $code, 'rawScore' => $offset + 1, 'standardScore' => 90 + $offset,
                'sourceScore' => 100 + $offset, 'level' => 3, 'category' => 'synthetic',
                'band' => ['lo' => 90, 'hi' => 109],
            ];
        }

        return SealedIstResult::seal($source, $scoringSource, $subtests, [
            'rawTotal' => 45, 'iq' => 100, 'level' => 3, 'sourceScores' => [100],
            'category' => 'synthetic', 'band' => ['lo' => 90, 'hi' => 109],
        ]);
    }

    /** @return array{pid:int,backend:int,socket:resource} */
    private function startWorker(SealedIstResult $result): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Writer concurrency requires pcntl; never skip.');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create result writer worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 25);
            try {
                DB::purge('pgsql');
                $identity = DB::selectOne(
                    'SELECT pg_backend_pid() pid,current_user name,rolsuper,rolbypassrls FROM pg_roles WHERE rolname=current_user',
                );
                if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                    throw new RuntimeException('Writer worker must be runtime NOBYPASSRLS.');
                }
                DB::statement("SET lock_timeout = '15s'");
                DB::statement("SET statement_timeout = '20s'");
                $this->writeEvent($pair[1], ['event' => 'ready', 'backend' => (int) $identity->pid]);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Writer barrier timed out.');
                }
                $publicId = app(RlsContextRunner::class)->runAsService(
                    fn (): string => app(PersistSealedIstResult::class)->execute($result),
                );
                $this->writeEvent($pair[1], ['event' => 'result', 'public_id' => $publicId]);
            } catch (Throwable $exception) {
                $this->writeEvent($pair[1], [
                    'event' => 'unexpected', 'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 25);
        $ready = $this->readEvent($pair[0]);
        $this->assertSame('ready', $ready['event'], json_encode($ready, JSON_THROW_ON_ERROR));

        return ['pid' => $pid, 'backend' => $ready['backend'], 'socket' => $pair[0]];
    }

    private function assertBackendWaitsOnLock(int $backend): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->assertSame('Lock', $waiting?->wait_event_type);
    }

    /** @param resource $socket
     * @return array<string,mixed>
     */
    private function readEvent($socket): array
    {
        $line = fgets($socket);
        if (! is_string($line)) {
            throw new RuntimeException('Writer worker did not report an event.');
        }
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || ! is_string($event['event'] ?? null)) {
            throw new RuntimeException('Writer worker event is invalid.');
        }

        return $event;
    }

    /** @param resource $socket
     * @param  array<string,mixed>  $event
     */
    private function writeEvent($socket, array $event): void
    {
        fwrite($socket, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /** @param list<array{pid:int,backend:int,socket:resource}> $workers */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    private function isPostgresRun(): bool
    {
        $runId = getenv('ORG_TEST_RUN_ID');

        return is_string($runId) && preg_match('/\A[a-f0-9]{32}\z/', $runId) === 1;
    }
}
