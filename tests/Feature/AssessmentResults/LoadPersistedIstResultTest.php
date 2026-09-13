<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedIstResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\LoadPersistedIstResult;
use App\Services\AssessmentResults\PersistSealedIstResult;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\OrganizationPaymentTestCase;
use Tests\TestCase;
use UnexpectedValueException;

final class LoadPersistedIstResultTest extends TestCase
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
            $target = DB::selectOne('SELECT current_database() database,current_user username,shobj_description(oid,\'pg_database\') marker FROM pg_database WHERE datname=current_database()');
            $this->assertSame('psikotes_organization_test', $target->database);
            $this->assertSame('psikotes_runtime', $target->username);
            $this->assertSame('ONCAM_ORG_TEST:'.$runId, $target->marker);

            return;
        }
        OrganizationPaymentTestCase::assertSafeDatabase(config('app.env'), config('database.default'), config('database.connections.sqlite.database'), config('database.connections.sqlite.url'));
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->isPostgresRun()) {
            return;
        }
        parent::tearDown();
    }

    public function test_service_context_and_caller_transaction_are_required(): void
    {
        $fixture = $this->persistedFixture();
        foreach ([
            fn () => app(LoadPersistedIstResult::class)->execute($fixture['publicId']),
            fn () => app(RlsContextRunner::class)->run(new RlsContext('participant', $fixture['branch'], $fixture['participant']), fn () => app(LoadPersistedIstResult::class)->execute($fixture['publicId'])),
        ] as $operation) {
            try {
                $operation();
                $this->fail('The reader must require a trusted service transaction.');
            } catch (LogicException $exception) {
                $this->assertSame('IST_RESULT_READ_CONTEXT_REQUIRED', $exception->getMessage());
            }
        }
        $contexts = app(RlsContextRunner::class);
        $current = new ReflectionProperty($contexts, 'current');
        $current->setValue($contexts, new RlsContext('service'));
        try {
            app(LoadPersistedIstResult::class)->execute($fixture['publicId']);
            $this->fail('A service marker without an outer transaction must fail.');
        } catch (LogicException $exception) {
            $this->assertSame('IST_RESULT_READ_CONTEXT_REQUIRED', $exception->getMessage());
        } finally {
            $current->setValue($contexts, null);
        }
    }

    public function test_it_loads_exact_state_without_writes_or_session_mutation(): void
    {
        $fixture = $this->persistedFixture();
        $beforeSession = (array) DB::table('test_sessions')->where('id', $fixture['session'])->sole();
        $beforeParent = DB::table('generic_instrument_results')->count();
        $beforeSources = DB::table('generic_instrument_result_sources')->count();
        $queries = [];

        $loaded = app(RlsContextRunner::class)->runAsService(function () use ($fixture, &$queries) {
            DB::listen(static function (QueryExecuted $query) use (&$queries): void {
                $queries[] = strtolower(trim($query->sql));
            });
            $level = DB::transactionLevel();
            $loaded = app(LoadPersistedIstResult::class)->execute($fixture['publicId']);
            $this->assertSame($level, DB::transactionLevel());

            return $loaded;
        });

        $this->assertSame($fixture['publicId'], $loaded->publicId);
        $this->assertEquals($fixture['result']->toArray(), json_decode($loaded->canonicalJson(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', $query);
            $this->assertStringNotContainsString('for update', $query);
        }
        $this->assertSame($beforeParent, DB::table('generic_instrument_results')->count());
        $this->assertSame($beforeSources, DB::table('generic_instrument_result_sources')->count());
        $this->assertSame($beforeSession, (array) DB::table('test_sessions')->where('id', $fixture['session'])->sole());
    }

    public function test_missing_noncanonical_and_wrong_instrument_identities_fail_identically(): void
    {
        $fixture = $this->persistedFixture();
        $missing = (string) Str::ulid();
        foreach ([$missing, strtolower($fixture['publicId'])] as $identity) {
            try {
                app(RlsContextRunner::class)->runAsService(fn () => app(LoadPersistedIstResult::class)->execute($identity));
                $this->fail('Missing or noncanonical identities must fail closed.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('IST_RESULT_READ_INVALID', $exception->getMessage());
            }
        }

        $wrongInstrument = $this->wrongInstrumentResultId($fixture);
        try {
            app(RlsContextRunner::class)->runAsService(fn () => app(LoadPersistedIstResult::class)->execute($wrongInstrument));
            $this->fail('A result for another instrument must be indistinguishable from missing.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('IST_RESULT_READ_INVALID', $exception->getMessage());
        }
    }

    public function test_counterfeit_payload_that_schema_can_store_fails_closed(): void
    {
        $fixture = $this->persistedFixture();
        $parent = (array) DB::table('generic_instrument_results')->where('public_id', $fixture['publicId'])->sole();
        $counterfeitId = (string) Str::ulid();
        $otherSession = DB::table('test_sessions')->where('id', $fixture['otherSession'])->sole();
        $counterfeit = [
            ...$parent, 'id' => null, 'public_id' => $counterfeitId,
            'session_id' => $fixture['otherSession'], 'session_public_id' => $otherSession->public_id,
            'attempt_no' => $otherSession->attempt_no, 'result_checksum' => str_repeat('0', 64),
        ];
        app(RlsContextRunner::class)->runAsService(function () use ($counterfeit, $parent): void {
            $newId = DB::table('generic_instrument_results')->insertGetId($counterfeit);
            foreach (DB::table('generic_instrument_result_sources')->where('result_id', $parent['id'])->get() as $source) {
                DB::table('generic_instrument_result_sources')->insert([...(array) $source, 'id' => null, 'result_id' => $newId]);
            }
        });

        try {
            app(RlsContextRunner::class)->runAsService(fn () => app(LoadPersistedIstResult::class)->execute($counterfeitId));
            $this->fail('Counterfeit stored rows must fail closed.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('IST_RESULT_READ_INVALID', $exception->getMessage());
        }
    }

    #[Group('sandbox')]
    public function test_postgres_runtime_role_reads_exact_persisted_state_without_lock_or_write(): void
    {
        $identity = DB::selectOne('SELECT current_user name,rolsuper,rolbypassrls FROM pg_roles WHERE rolname=current_user');
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $fixture = $this->persistedFixture();
        $queries = [];

        $loaded = app(RlsContextRunner::class)->runAsService(function () use ($fixture, &$queries) {
            DB::listen(static function (QueryExecuted $query) use (&$queries): void {
                $queries[] = strtolower(trim($query->sql));
            });

            return app(LoadPersistedIstResult::class)->execute($fixture['publicId']);
        });

        $this->assertSame($fixture['publicId'], $loaded->publicId);
        $this->assertCount(9, $loaded->subtests);
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', $query);
            $this->assertStringNotContainsString('for update', $query);
        }
        app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
            $resultId = DB::table('generic_instrument_results')
                ->where('public_id', $fixture['publicId'])->value('id');
            $this->assertSame(1, DB::table('generic_instrument_results')
                ->where('public_id', $fixture['publicId'])->count());
            $this->assertSame(9, DB::table('generic_instrument_result_sources')
                ->where('result_id', $resultId)->count());
        });
    }

    /** @return array{branch:int,participant:int,case:int,session:int,otherSession:int,result:SealedIstResult,publicId:string} */
    private function persistedFixture(): array
    {
        return app(RlsContextRunner::class)->runAsService(fn (): array => $this->createPersistedFixture());
    }

    /** @return array{branch:int,participant:int,case:int,session:int,otherSession:int,result:SealedIstResult,publicId:string} */
    private function createPersistedFixture(): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId(['code' => $key, 'ref_code' => $key, 'name' => $key, 'organization_code' => $key, 'display_name' => $key]);
        $participant = DB::table('participants')->insertGetId(['branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default', 'source_system' => 'R2C_TEST', 'full_name' => $key, 'phone' => '620000000000']);
        $case = DB::table('assessment_cases')->insertGetId(['public_id' => (string) Str::ulid(), 'participant_id' => $participant, 'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now()]);
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1', 'provenance' => 'synthetic-r2c-test-only',
            'total_duration_seconds' => 540,
            'subtests' => array_map(static fn (string $code): array => ['code' => $code, 'duration_seconds' => 60, 'item_count' => 1], ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME']),
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource)]);
        $submittedAt = '2026-09-13 04:20:00.654321+00:00';
        $session = $this->insertSession($case, $participant, $definition, $submittedAt);
        $otherSession = $this->insertSession($case, $participant, $definition, $submittedAt, 2);
        $payload = json_encode(['version' => 'synthetic-v1'], JSON_THROW_ON_ERROR);
        $versionId = DB::table('instrument_versions')->insertGetId(['code' => 'ist', 'version' => 'synthetic-v1', 'source_file' => 'synthetic-ist.json', 'checksum' => hash('sha256', $payload), 'payload' => $payload, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $source = SealedGenericAnswerSet::seal($case, $session, $participant, (string) DB::table('test_sessions')->where('id', $session)->value('public_id'), GenericAssessmentInstrument::Ist, 1, $submittedAt, 1, $definition, [['item_no' => 1, 'value' => 'A', 'revision' => 1, 'answered_at' => '2026-09-13T04:10:00.123456Z']]);
        $scoringSource = ['id' => $versionId, 'code' => 'ist', 'version' => 'synthetic-v1', 'sourceFile' => 'synthetic-ist.json', 'checksum' => hash('sha256', $payload)];
        $subtests = [];
        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'] as $offset => $code) {
            $subtests[] = ['code' => $code, 'rawScore' => $offset + 1, 'standardScore' => 90 + $offset, 'sourceScore' => 100 + $offset, 'level' => 3, 'category' => 'synthetic', 'band' => ['lo' => 90, 'hi' => 109]];
        }
        $result = SealedIstResult::seal($source, $scoringSource, $subtests, ['rawTotal' => 45, 'iq' => 100, 'level' => 3, 'sourceScores' => [100], 'category' => 'synthetic', 'band' => ['lo' => 90, 'hi' => 109]]);
        $publicId = app(PersistSealedIstResult::class)->execute($result);

        return compact('branch', 'participant', 'case', 'session', 'otherSession', 'result', 'publicId');
    }

    /** @param array{case:int,participant:int} $fixture */
    private function wrongInstrumentResultId(array $fixture): string
    {
        $source = [
            'instrument' => 'papi', 'version' => 'synthetic-papi-definition-v1',
            'provenance' => 'synthetic-r2c-test-only', 'total_duration_seconds' => 60,
            'subtests' => [['code' => 'P', 'duration_seconds' => 60, 'item_count' => 1]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([...$source, 'checksum' => SessionDefinition::checksumFor($source)]);
        $submittedAt = '2026-09-13 04:00:30.654321+00:00';
        $session = $this->insertSession($fixture['case'], $fixture['participant'], $definition, $submittedAt, 1, 'papi');
        $sessionPublicId = (string) DB::table('test_sessions')->where('id', $session)->value('public_id');
        $payload = json_encode(['version' => 'synthetic-papi-v1'], JSON_THROW_ON_ERROR);
        $checksum = hash('sha256', $payload);
        $versionId = DB::table('instrument_versions')->insertGetId([
            'code' => 'papi', 'version' => 'synthetic-papi-v1', 'source_file' => 'synthetic-papi.json',
            'checksum' => $checksum, 'payload' => $payload, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $publicId = (string) Str::ulid();
        app(RlsContextRunner::class)->runAsService(function () use (
            $fixture, $session, $sessionPublicId, $submittedAt, $definition,
            $versionId, $checksum, $publicId,
        ): void {
            DB::table('generic_instrument_results')->insert([
                'public_id' => $publicId, 'assessment_case_id' => $fixture['case'],
                'session_id' => $session, 'participant_id' => $fixture['participant'],
                'session_public_id' => $sessionPublicId, 'instrument_code' => 'papi',
                'attempt_no' => 1, 'submitted_at' => $submittedAt, 'answers_revision' => 1,
                'sealed_source_checksum' => str_repeat('d', 64),
                'session_definition_version' => $definition->version,
                'session_definition_provenance' => $definition->provenance,
                'session_definition_checksum' => $definition->checksum,
                'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
                'instrument_version_id' => $versionId, 'instrument_version' => 'synthetic-papi-v1',
                'instrument_source_file' => 'synthetic-papi.json', 'instrument_checksum' => $checksum,
                'result_contract_version' => 'papi-result:v1', 'result_payload' => '{}',
                'result_checksum' => str_repeat('e', 64), 'created_at' => now(),
            ]);
        });

        return $publicId;
    }

    private function insertSession(
        int $case,
        int $participant,
        SessionDefinition $definition,
        string $submittedAt,
        int $attempt = 1,
        string $instrument = 'ist',
    ): int {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant, 'assessment_case_id' => $case,
            'test_type' => $instrument, 'attempt_no' => $attempt, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $definition->totalDurationSeconds, 'status' => 'submitted',
            'answers_revision' => 1, 'started_at' => '2026-09-13 04:00:00.000000+00:00',
            'ends_at' => '2026-09-13 04:30:00.000000+00:00', 'submitted_at' => $submittedAt,
            'session_definition_version' => $definition->version, 'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum, 'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-13 04:00:00.000000+00:00', 'updated_at' => $submittedAt,
        ]);
    }

    private function isPostgresRun(): bool
    {
        $runId = getenv('ORG_TEST_RUN_ID');

        return is_string($runId) && preg_match('/\A[a-f0-9]{32}\z/', $runId) === 1;
    }
}
