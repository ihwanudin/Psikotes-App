<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\AspectSourceReadingStatus;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\LoadGenericInstrumentResultSourcesForAspect;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
use UnexpectedValueException;

final class LoadGenericInstrumentResultSourcesForAspectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
    }

    public function test_it_distinguishes_found_missing_not_found_and_ambiguous_sources(): void
    {
        // A2 = [IST_AN, IST_RA, IST_ZR, PAPI_R].
        $case = $this->makeCase();
        $result = $this->makeGenericInstrumentResult($case, 'ist', [
            ['source_code' => 'AN', 'level' => 4],
            ['source_code' => 'RA', 'level' => 2],
            // ZR is deliberately absent: SourceMissingFromResult.
        ]);

        $readings = app(RlsContextRunner::class)->runAsService(
            fn () => app(LoadGenericInstrumentResultSourcesForAspect::class)->execute($case, 'A2'),
        );

        $byConfiguredSource = [];
        foreach ($readings as $reading) {
            $byConfiguredSource[$reading->configuredSource] = $reading;
        }
        $this->assertSame(['IST_AN', 'IST_RA', 'IST_ZR', 'PAPI_R'], array_keys($byConfiguredSource));

        $an = $byConfiguredSource['IST_AN'];
        $this->assertSame('A2', $an->aspect);
        $this->assertSame('ist', $an->instrumentCode);
        $this->assertSame('AN', $an->sourceCode);
        $this->assertSame(AspectSourceReadingStatus::Found, $an->status);
        $this->assertSame(4, $an->level);

        $ra = $byConfiguredSource['IST_RA'];
        $this->assertSame(AspectSourceReadingStatus::Found, $ra->status);
        $this->assertSame(2, $ra->level);

        $zr = $byConfiguredSource['IST_ZR'];
        $this->assertSame('ist', $zr->instrumentCode);
        $this->assertSame('ZR', $zr->sourceCode);
        $this->assertSame(AspectSourceReadingStatus::SourceMissingFromResult, $zr->status);
        $this->assertNull($zr->level);

        $papiR = $byConfiguredSource['PAPI_R'];
        $this->assertSame('papi', $papiR->instrumentCode);
        $this->assertSame('R', $papiR->sourceCode);
        $this->assertSame(AspectSourceReadingStatus::NotFound, $papiR->status);
        $this->assertNull($papiR->level);
    }

    public function test_more_than_one_result_for_the_same_case_and_instrument_is_ambiguous_not_guessed(): void
    {
        $case = $this->makeCase();
        $this->makeGenericInstrumentResult($case, 'ist', [['source_code' => 'AN', 'level' => 3]], attemptNo: 1);
        $this->makeGenericInstrumentResult($case, 'ist', [['source_code' => 'AN', 'level' => 5]], attemptNo: 2);

        $readings = app(RlsContextRunner::class)->runAsService(
            fn () => app(LoadGenericInstrumentResultSourcesForAspect::class)->execute($case, 'A2'),
        );

        $an = current(array_filter($readings, static fn ($reading) => $reading->configuredSource === 'IST_AN'));
        $this->assertSame(AspectSourceReadingStatus::Ambiguous, $an->status);
        $this->assertNull($an->level);
    }

    public function test_it_requires_a_service_transaction_and_a_known_aspect(): void
    {
        $case = $this->makeCase();

        try {
            app(LoadGenericInstrumentResultSourcesForAspect::class)->execute($case, 'A2');
            $this->fail('The reader must require an existing service transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('ASPECT_SOURCE_READING_CONTEXT_REQUIRED', $exception->getMessage());
        }

        app(RlsContextRunner::class)->runAsService(function () use ($case): void {
            foreach (['', 'A99', 'a1', 'IST_AN'] as $bogusAspect) {
                try {
                    app(LoadGenericInstrumentResultSourcesForAspect::class)->execute($case, $bogusAspect);
                    $this->fail("Unknown aspect {$bogusAspect} must fail closed.");
                } catch (UnexpectedValueException $exception) {
                    $this->assertSame('ASPECT_SOURCE_READING_INVALID', $exception->getMessage());
                }
            }
        });
    }

    private function makeCase(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'source_system' => 'R2_READER_TEST',
            'full_name' => $key, 'phone' => '620000000000',
        ]);

        return DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param list<array{source_code:string,level:int}> $sources */
    private function makeGenericInstrumentResult(
        int $caseId,
        string $instrumentCode,
        array $sources,
        int $attemptNo = 1,
    ): int {
        $participantId = (int) DB::table('assessment_cases')->where('id', $caseId)->value('participant_id');
        $definitionSource = [
            'instrument' => $instrumentCode, 'version' => 'synthetic-definition-v1-attempt'.$attemptNo,
            'provenance' => 'synthetic-reader-test-only', 'total_duration_seconds' => 60,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 60, 'item_count' => 1]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $definitionPayload = json_encode($definition->toArray(), JSON_THROW_ON_ERROR);
        $sessionPublicId = (string) Str::ulid();
        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participantId,
            'assessment_case_id' => $caseId, 'test_type' => $instrumentCode, 'attempt_no' => $attemptNo,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $definition->totalDurationSeconds, 'status' => 'submitted', 'answers_revision' => 1,
            'started_at' => '2026-09-20 03:00:00.000000+00:00', 'ends_at' => '2026-09-20 03:30:00.000000+00:00',
            'submitted_at' => '2026-09-20 03:20:00.000000+00:00',
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => $definitionPayload,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $instrumentVersion = DB::table('instrument_versions')->where('code', $instrumentCode)
            ->where('is_active', true)->first(['id', 'version', 'source_file', 'checksum']);
        $resultPayload = json_encode(['synthetic' => true, 'session' => $sessionId], JSON_THROW_ON_ERROR);
        $resultId = DB::table('generic_instrument_results')->insertGetId([
            'public_id' => strtoupper((string) Str::ulid()), 'assessment_case_id' => $caseId,
            'session_id' => $sessionId, 'participant_id' => $participantId,
            'session_public_id' => $sessionPublicId, 'instrument_code' => $instrumentCode,
            'attempt_no' => $attemptNo, 'submitted_at' => '2026-09-20 03:20:00.000000+00:00', 'answers_revision' => 1,
            'sealed_source_checksum' => hash('sha256', 'synthetic-source'),
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => $definitionPayload,
            'instrument_version_id' => $instrumentVersion->id, 'instrument_version' => $instrumentVersion->version,
            'instrument_source_file' => $instrumentVersion->source_file,
            'instrument_checksum' => $instrumentVersion->checksum,
            'result_contract_version' => 'synthetic-result:v1', 'result_payload' => $resultPayload,
            'result_checksum' => hash('sha256', $resultPayload), 'created_at' => now(),
        ]);
        foreach ($sources as $offset => $source) {
            DB::table('generic_instrument_result_sources')->insert([
                'result_id' => $resultId, 'ordinal' => $offset + 1, 'source_code' => $source['source_code'],
                'raw_score' => 1, 'standard_score' => 1, 'source_score' => 1, 'level' => $source['level'],
                'category' => 'synthetic', 'band_low' => 1, 'band_high' => 1, 'created_at' => now(),
            ]);
        }

        return $resultId;
    }
}
