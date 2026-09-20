<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\SealedKraepelinResult;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\PersistSealedKraepelinResult;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionProperty;
use Tests\OrganizationPaymentTestCase;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Mirrors tests/Feature/AssessmentResults/PersistSealedIstResultTest.php for
 * Kraepelin (SQLite-portable subset only — see
 * PersistSealedPapiResultTest for why the PostgreSQL two-process writer
 * concurrency proof is not duplicated per instrument). The one addition over
 * the IST/PAPI/RMIB equivalents: an explicit proof that the fractional
 * Panker/Hanker values persist and read back exactly, since
 * PersistSealedKraepelinResult deliberately does not reuse the `(int)`-cast
 * comparison the other three use for raw_score/band_low/band_high.
 */
final class PersistSealedKraepelinResultTest extends TestCase
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
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_it_appends_one_result_and_the_fractional_factors_round_trip_exactly(): void
    {
        $fixture = $this->fixture();
        $beforeStatus = DB::table('test_sessions')->where('id', $fixture['session'])->value('status');

        $identity = app(RlsContextRunner::class)->runAsService(function () use ($fixture): string {
            $level = DB::transactionLevel();
            $identity = app(PersistSealedKraepelinResult::class)->execute($fixture['result']);
            $this->assertSame($level, DB::transactionLevel());

            return $identity;
        });

        $this->assertTrue(Str::isUlid($identity));
        $this->assertSame(strtoupper($identity), $identity);
        $this->assertSame($beforeStatus, DB::table('test_sessions')->where('id', $fixture['session'])->value('status'));
        $parent = DB::table('generic_instrument_results')->sole();
        $this->assertSame($identity, $parent->public_id);
        $this->assertSame('kraepelin', $parent->instrument_code);
        $this->assertSame($fixture['result']->resultChecksum, $parent->result_checksum);

        $sources = DB::table('generic_instrument_result_sources')->orderBy('ordinal')->get();
        $this->assertCount(4, $sources);
        foreach ($sources as $offset => $source) {
            $expected = $fixture['result']->factors[$offset];
            $this->assertSame($offset + 1, $source->ordinal);
            $this->assertSame($expected['code'], $source->source_code);
            $this->assertSame((float) $expected['rawScore'], (float) $source->raw_score, $expected['code']);
            $this->assertSame($expected['standardScore'], $source->standard_score);
            $this->assertSame($expected['sourceScore'], $source->source_score);
            $this->assertSame($expected['level'], $source->level);
            $this->assertSame($parent->created_at, $source->created_at);
        }

        // The exact golden values, byte-for-byte, not just numerically close.
        $panker = $sources->firstWhere('source_code', 'PANKER');
        $hanker = $sources->firstWhere('source_code', 'HANKER');
        $this->assertSame(15.86, (float) $panker->raw_score);
        $this->assertSame(-0.622, (float) $hanker->raw_score);
    }

    public function test_exact_replay_returns_original_identity_and_divergent_replay_fails_closed(): void
    {
        $fixture = $this->fixture();
        $identity = app(RlsContextRunner::class)->runAsService(
            fn (): string => app(PersistSealedKraepelinResult::class)->execute($fixture['result']),
        );
        $replay = app(RlsContextRunner::class)->runAsService(
            fn (): string => app(PersistSealedKraepelinResult::class)->execute($fixture['result']),
        );
        $this->assertSame($identity, $replay);
        $this->assertSame(1, DB::table('generic_instrument_results')->count());
        $this->assertSame(4, DB::table('generic_instrument_result_sources')->count());

        try {
            app(RlsContextRunner::class)->runAsService(
                fn (): string => app(PersistSealedKraepelinResult::class)->execute(
                    $this->sealedResult($fixture['identity'], $fixture['scoringSource'], panker: 20.0),
                ),
            );
            $this->fail('A divergent result for the same session must fail closed.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('KRAEPELIN_RESULT_PERSISTENCE_CONFLICT', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('generic_instrument_results')->count());
        $this->assertSame(4, DB::table('generic_instrument_result_sources')->count());
    }

    public function test_context_and_caller_owned_transaction_are_mandatory(): void
    {
        $fixture = $this->fixture();
        foreach ([
            fn () => app(PersistSealedKraepelinResult::class)->execute($fixture['result']),
            fn () => app(RlsContextRunner::class)->run(
                new RlsContext('participant', $fixture['branch'], $fixture['participant']),
                fn () => app(PersistSealedKraepelinResult::class)->execute($fixture['result']),
            ),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Persistence must require its trusted service transaction.');
            } catch (LogicException $exception) {
                $this->assertSame('KRAEPELIN_RESULT_PERSISTENCE_CONTEXT_REQUIRED', $exception->getMessage());
            }
        }

        $contexts = app(RlsContextRunner::class);
        $current = new ReflectionProperty($contexts, 'current');
        $current->setValue($contexts, new RlsContext('service'));
        try {
            $this->assertSame(0, DB::transactionLevel());
            app(PersistSealedKraepelinResult::class)->execute($fixture['result']);
            $this->fail('A service context without an outer transaction must be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('KRAEPELIN_RESULT_PERSISTENCE_CONTEXT_REQUIRED', $exception->getMessage());
        } finally {
            $current->setValue($contexts, null);
        }
        $this->assertSame(0, DB::table('generic_instrument_results')->count());
    }

    /** @return array{branch:int,participant:int,case:int,session:int,identity:array<string,mixed>,scoringSource:array{id:int,code:string,version:string,sourceFile:string,checksum:string},result:SealedKraepelinResult} */
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
            'instrument' => 'kraepelin', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-test-only', 'total_duration_seconds' => 750,
            'subtests' => [['code' => 'KRAEPELIN', 'duration_seconds' => 750, 'item_count' => 1350]],
            'randomization' => 'seeded', 'seed' => 'synthetic-seed-01',
            'generator' => [
                'algorithm' => 'synthetic-test-generator', 'version' => 'v1', 'columns' => 50,
                'seconds_per_column' => 15, 'numbers_per_column' => 28, 'answer_slots_per_column' => 27,
            ],
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $submittedAt = '2026-09-20 03:20:00.654321+00:00';
        $sessionPublicId = (string) Str::ulid();
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant, 'assessment_case_id' => $case,
            'test_type' => 'kraepelin', 'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $definition->totalDurationSeconds, 'status' => 'submitted',
            'answers_revision' => 1, 'started_at' => '2026-09-20 03:00:00.000000+00:00',
            'ends_at' => '2026-09-20 03:30:00.000000+00:00', 'submitted_at' => $submittedAt,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-20 03:00:00.000000+00:00', 'updated_at' => $submittedAt,
        ]);
        $payload = json_encode(['version' => 'synthetic-v1'], JSON_THROW_ON_ERROR);
        $instrumentVersion = DB::table('instrument_versions')->insertGetId([
            'code' => 'kraepelin', 'version' => 'synthetic-v1', 'source_file' => 'synthetic-kraepelin.json',
            'checksum' => hash('sha256', $payload), 'payload' => $payload,
            'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $identity = [
            'assessmentCaseId' => $case, 'sessionId' => $session, 'participantId' => $participant,
            'sessionPublicId' => strtoupper($sessionPublicId), 'attemptNo' => 1,
            'submittedAt' => $submittedAt, 'answersRevision' => 1,
            'sessionDefinition' => $definition->toArray(),
        ];
        $scoringSource = [
            'id' => $instrumentVersion, 'code' => 'kraepelin', 'version' => 'synthetic-v1',
            'sourceFile' => 'synthetic-kraepelin.json', 'checksum' => hash('sha256', $payload),
        ];

        return compact('branch', 'participant', 'case', 'session', 'identity', 'scoringSource')
            + ['result' => $this->sealedResult($identity, $scoringSource)];
    }

    /**
     * @param  array<string,mixed>  $identity
     * @param  array{id:int,code:string,version:string,sourceFile:string,checksum:string}  $scoringSource
     */
    private function sealedResult(array $identity, array $scoringSource, float $panker = 15.86): SealedKraepelinResult
    {
        $factors = [
            ['code' => 'PANKER', 'rawScore' => $panker, 'standardScore' => 7, 'sourceScore' => 7, 'level' => 4, 'category' => 'Baik', 'band' => ['lo' => 14.973, 'hi' => 16.09]],
            ['code' => 'TIANKER', 'rawScore' => 7, 'standardScore' => 6, 'sourceScore' => 6, 'level' => 3, 'category' => 'Sedang', 'band' => ['lo' => 3, 'hi' => 8]],
            ['code' => 'HANKER', 'rawScore' => -0.622, 'standardScore' => 4, 'sourceScore' => 4, 'level' => 2, 'category' => 'Kurang', 'band' => ['lo' => -1.209, 'hi' => -0.469]],
            ['code' => 'JANKER', 'rawScore' => 7, 'standardScore' => 6, 'sourceScore' => 6, 'level' => 3, 'category' => 'Sedang', 'band' => ['lo' => 7, 'hi' => 8]],
        ];

        return SealedKraepelinResult::seal(
            identity: $identity,
            scoringSource: $scoringSource,
            normGroup: 'S1/S2 (IPA)',
            factors: $factors,
            sealedSourceChecksum: hash('sha256', 'synthetic-precomputed-factors'),
        );
    }
}
