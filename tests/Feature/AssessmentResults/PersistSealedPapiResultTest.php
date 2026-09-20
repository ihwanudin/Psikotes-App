<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedPapiResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\PersistSealedPapiResult;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionProperty;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Mirrors tests/Feature/AssessmentResults/PersistSealedIstResultTest.php for
 * PAPI (SQLite-portable subset only — the PostgreSQL two-process writer
 * concurrency proof from that file is not duplicated per instrument here;
 * PersistSealedPapiResult shares the exact same query/lock shape as
 * PersistSealedIstResult, already proven under PostgreSQL concurrency, and
 * this file adds no new locking behavior).
 */
final class PersistSealedPapiResultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OrganizationPaymentTestCase::assertSafeDatabase(
            config('app.env'), config('database.default'),
            config('database.connections.sqlite.database'), config('database.connections.sqlite.url'),
        );
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Keep DatabaseTruncation from reusing a migrated flag whose PDO
            // was discarded by this class's migrate:fresh setup.
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_it_appends_one_result_and_all_ordered_sources_without_owning_the_transaction(): void
    {
        $fixture = $this->fixture();
        $beforeStatus = DB::table('test_sessions')->where('id', $fixture['session'])->value('status');

        $identity = app(RlsContextRunner::class)->runAsService(function () use ($fixture): string {
            $level = DB::transactionLevel();
            $identity = app(PersistSealedPapiResult::class)->execute($fixture['result']);
            $this->assertSame($level, DB::transactionLevel());

            return $identity;
        });

        $this->assertTrue(Str::isUlid($identity));
        $this->assertSame(strtoupper($identity), $identity);
        $this->assertSame($beforeStatus, DB::table('test_sessions')->where('id', $fixture['session'])->value('status'));
        $parent = DB::table('generic_instrument_results')->sole();
        $this->assertSame($identity, $parent->public_id);
        $this->assertSame('papi', $parent->instrument_code);
        $this->assertSame($fixture['result']->resultChecksum, $parent->result_checksum);
        $this->assertSame(
            json_decode($fixture['result']->canonicalJson(), true, flags: JSON_THROW_ON_ERROR),
            json_decode($parent->result_payload, true, flags: JSON_THROW_ON_ERROR),
        );
        $sources = DB::table('generic_instrument_result_sources')->orderBy('ordinal')->get();
        $this->assertCount(20, $sources);
        foreach ($sources as $offset => $source) {
            $expected = $fixture['result']->dimensions[$offset];
            $this->assertSame($offset + 1, $source->ordinal);
            $this->assertSame($expected['code'], $source->source_code);
            $this->assertSame($expected['rawScore'], $source->raw_score);
            $this->assertSame($expected['standardScore'], $source->standard_score);
            $this->assertSame($expected['sourceScore'], $source->source_score);
            $this->assertSame($expected['level'], $source->level);
            $this->assertSame($expected['category'], $source->category);
            $this->assertSame($parent->created_at, $source->created_at);
        }
    }

    public function test_exact_replay_returns_original_identity_and_divergent_replay_fails_closed(): void
    {
        $fixture = $this->fixture();
        $identity = app(RlsContextRunner::class)->runAsService(
            fn (): string => app(PersistSealedPapiResult::class)->execute($fixture['result']),
        );
        $replay = app(RlsContextRunner::class)->runAsService(
            fn (): string => app(PersistSealedPapiResult::class)->execute($fixture['result']),
        );
        $this->assertSame($identity, $replay);
        $this->assertSame(1, DB::table('generic_instrument_results')->count());
        $this->assertSame(20, DB::table('generic_instrument_result_sources')->count());

        try {
            app(RlsContextRunner::class)->runAsService(
                fn (): string => app(PersistSealedPapiResult::class)->execute(
                    $this->sealedResult($fixture['source'], $fixture['scoringSource'], 1),
                ),
            );
            $this->fail('A divergent result for the same session must fail closed.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('PAPI_RESULT_PERSISTENCE_CONFLICT', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('generic_instrument_results')->count());
        $this->assertSame(20, DB::table('generic_instrument_result_sources')->count());
    }

    public function test_context_and_caller_owned_transaction_are_mandatory(): void
    {
        $fixture = $this->fixture();
        foreach ([
            fn () => app(PersistSealedPapiResult::class)->execute($fixture['result']),
            fn () => app(RlsContextRunner::class)->run(
                new RlsContext('participant', $fixture['branch'], $fixture['participant']),
                fn () => app(PersistSealedPapiResult::class)->execute($fixture['result']),
            ),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Persistence must require its trusted service transaction.');
            } catch (LogicException $exception) {
                $this->assertSame('PAPI_RESULT_PERSISTENCE_CONTEXT_REQUIRED', $exception->getMessage());
            }
        }

        $contexts = app(RlsContextRunner::class);
        $current = new ReflectionProperty($contexts, 'current');
        $current->setValue($contexts, new RlsContext('service'));
        try {
            $this->assertSame(0, DB::transactionLevel());
            app(PersistSealedPapiResult::class)->execute($fixture['result']);
            $this->fail('A service context without an outer transaction must be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('PAPI_RESULT_PERSISTENCE_CONTEXT_REQUIRED', $exception->getMessage());
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
                app(PersistSealedPapiResult::class)->execute($fixture['result']);
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
            instrument: GenericAssessmentInstrument::Papi,
            attemptNo: 1,
            submittedAt: $fixture['source']->submittedAt,
            answersRevision: 1,
            definition: $fixture['source']->definition,
            answers: $fixture['source']->answers,
        );
        try {
            app(RlsContextRunner::class)->runAsService(fn () => app(PersistSealedPapiResult::class)
                ->execute($this->sealedResult($counterfeit, $fixture['scoringSource'])));
            $this->fail('Stale or cross-scope authority must fail closed.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('PAPI_RESULT_PERSISTENCE_INVALID', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('generic_instrument_results')->count());
        $this->assertSame(0, DB::table('generic_instrument_result_sources')->count());
    }

    /** @return array{branch:int,participant:int,case:int,session:int,source:SealedGenericAnswerSet,scoringSource:array{id:int,code:string,version:string,sourceFile:string,checksum:string},result:SealedPapiResult} */
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
            'instrument' => 'papi', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-test-only', 'total_duration_seconds' => 60,
            'subtests' => [['code' => 'PAPI', 'duration_seconds' => 60, 'item_count' => 20]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $submittedAt = '2026-09-20 03:20:00.654321+00:00';
        $sessionPublicId = (string) Str::ulid();
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'papi', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 60, 'status' => 'submitted', 'answers_revision' => 1,
            'started_at' => '2026-09-20 03:00:00.000000+00:00',
            'ends_at' => '2026-09-20 03:30:00.000000+00:00',
            'submitted_at' => $submittedAt,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-20 03:00:00.000000+00:00',
            'updated_at' => '2026-09-20 03:20:00.654321+00:00',
        ]);
        $payload = json_encode(['version' => 'synthetic-v1'], JSON_THROW_ON_ERROR);
        $instrumentVersion = DB::table('instrument_versions')->insertGetId([
            'code' => 'papi', 'version' => 'synthetic-v1', 'source_file' => 'synthetic-papi.json',
            'checksum' => hash('sha256', $payload), 'payload' => $payload,
            'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $answers = [];
        foreach (range(1, 20) as $item) {
            $answers[] = [
                'item_no' => $item, 'value' => 'a', 'revision' => 1,
                'answered_at' => '2026-09-20T03:10:00.123456Z',
            ];
        }
        $source = SealedGenericAnswerSet::seal(
            assessmentCaseId: $case, sessionId: $session, participantId: $participant,
            sessionPublicId: $sessionPublicId, instrument: GenericAssessmentInstrument::Papi,
            attemptNo: 1, submittedAt: $submittedAt, answersRevision: 1,
            definition: $definition, answers: $answers,
        );
        $scoringSource = [
            'id' => $instrumentVersion, 'code' => 'papi', 'version' => 'synthetic-v1',
            'sourceFile' => 'synthetic-papi.json', 'checksum' => hash('sha256', $payload),
        ];

        return compact('branch', 'participant', 'case', 'session', 'source', 'scoringSource')
            + ['result' => $this->sealedResult($source, $scoringSource)];
    }

    /** @param array{id:int,code:string,version:string,sourceFile:string,checksum:string} $scoringSource */
    private function sealedResult(
        SealedGenericAnswerSet $source,
        array $scoringSource,
        int $rawOffset = 0,
    ): SealedPapiResult {
        $codes = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'I', 'K', 'L', 'N', 'O', 'P', 'R', 'S', 'T', 'V', 'W', 'X', 'Z'];
        $dimensions = [];
        foreach ($codes as $offset => $code) {
            $raw = min(9, $offset + $rawOffset);
            $dimensions[] = [
                'code' => $code, 'rawScore' => $raw, 'standardScore' => $offset,
                'sourceScore' => $raw, 'level' => 3,
                'category' => $offset % 2 === 0 ? 'ROLE' : 'NEED',
                'band' => ['lo' => 3, 'hi' => 6],
            ];
        }

        return SealedPapiResult::seal($source, $scoringSource, $dimensions);
    }
}
