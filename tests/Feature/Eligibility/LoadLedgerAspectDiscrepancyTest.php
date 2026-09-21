<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Security\RlsContextRunner;
use App\Services\Eligibility\LoadLedgerAspectDiscrepancy;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * F2 G7-server-side-sources, Phase 1 (2026-09-21). See
 * tasks/handoffs/f2/g7-server-side-sources.md for the full design record.
 */
final class LoadLedgerAspectDiscrepancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
    }

    public function test_execute_has_no_sources_parameter_for_a_caller_to_forge(): void
    {
        $method = new ReflectionMethod(LoadLedgerAspectDiscrepancy::class, 'execute');
        $names = array_map(static fn ($parameter): string => $parameter->getName(), $method->getParameters());

        $this->assertSame(['assessmentCaseId', 'aspect'], $names);
    }

    public function test_a_real_spread_across_instruments_is_computed_entirely_from_the_ledger(): void
    {
        // A2 = [IST_AN, IST_RA, IST_ZR, PAPI_R]. A genuine spread of 4 exists
        // in the ledger; nothing supplies it as an argument.
        $case = $this->makeCase();
        $this->makeGenericInstrumentResult($case, 'ist', [
            ['source_code' => 'AN', 'level' => 5],
            ['source_code' => 'RA', 'level' => 4],
            ['source_code' => 'ZR', 'level' => 4],
        ]);
        $this->makeGenericInstrumentResult($case, 'papi', [
            ['source_code' => 'R', 'level' => 1],
        ]);

        $result = app(RlsContextRunner::class)->runAsService(
            fn () => app(LoadLedgerAspectDiscrepancy::class)->execute($case, 'A2'),
        );

        $this->assertTrue($result['review_required']);
        $this->assertSame('SOURCE_LEVEL_SPREAD', $result['reason_code']);
        $this->assertSame(4, $result['provenance']['spread']);
        $this->assertSame(1, $result['provenance']['minimum_level']);
        $this->assertSame(5, $result['provenance']['maximum_level']);

        // Proof there is no structural path from a forged client-shaped
        // input to what execute() actually trusts: a hand-built call to the
        // underlying policy with fabricated low-spread sources produces a
        // completely different, structurally unreachable result from here --
        // execute() never receives, forwards, or is influenced by any such
        // array. The only way to change $result above is to change the
        // database rows.
        $forged = (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => 'A2',
            'sources' => [
                ['source' => 'IST_AN', 'level' => 3],
                ['source' => 'IST_RA', 'level' => 3],
                ['source' => 'IST_ZR', 'level' => 3],
                ['source' => 'PAPI_R', 'level' => 3],
            ],
        ]);
        $this->assertFalse($forged['review_required']);
        $this->assertNotSame($forged, $result);
    }

    public function test_a_missing_source_is_incomplete_not_silently_satisfied_by_the_others(): void
    {
        $case = $this->makeCase();
        $this->makeGenericInstrumentResult($case, 'ist', [
            ['source_code' => 'AN', 'level' => 3],
            ['source_code' => 'RA', 'level' => 3],
            // ZR deliberately absent.
        ]);
        $this->makeGenericInstrumentResult($case, 'papi', [
            ['source_code' => 'R', 'level' => 3],
        ]);

        $result = app(RlsContextRunner::class)->runAsService(
            fn () => app(LoadLedgerAspectDiscrepancy::class)->execute($case, 'A2'),
        );

        $this->assertTrue($result['review_required']);
        $this->assertSame('SOURCE_INCOMPLETE', $result['reason_code']);
        $this->assertNull($result['provenance']['spread']);
        $byConfiguredSource = array_column($result['provenance']['sources'], 'level', 'source');
        $this->assertSame(3, $byConfiguredSource['IST_AN']);
        $this->assertSame(3, $byConfiguredSource['IST_RA']);
        $this->assertNull($byConfiguredSource['IST_ZR']);
        $this->assertSame(3, $byConfiguredSource['PAPI_R']);
    }

    public function test_kraepelin_with_no_ledger_rows_is_incomplete_with_no_special_casing(): void
    {
        // B1 = [IST_ME, KRAEPELIN_PANKER, KRAEPELIN_JANKER]. No Kraepelin
        // rows exist for any case today (raw-digit grading is blocked), so
        // this exercises the exact production state, not a contrived one.
        $case = $this->makeCase();
        $this->makeGenericInstrumentResult($case, 'ist', [
            ['source_code' => 'ME', 'level' => 3],
        ]);

        $result = app(RlsContextRunner::class)->runAsService(
            fn () => app(LoadLedgerAspectDiscrepancy::class)->execute($case, 'B1'),
        );

        $this->assertTrue($result['review_required']);
        $this->assertSame('SOURCE_INCOMPLETE', $result['reason_code']);
        $byConfiguredSource = array_column($result['provenance']['sources'], 'level', 'source');
        $this->assertSame(3, $byConfiguredSource['IST_ME']);
        $this->assertNull($byConfiguredSource['KRAEPELIN_PANKER']);
        $this->assertNull($byConfiguredSource['KRAEPELIN_JANKER']);
    }

    public function test_no_result_at_all_for_the_case_is_incomplete_for_every_aspect(): void
    {
        $case = $this->makeCase();

        $result = app(RlsContextRunner::class)->runAsService(
            fn () => app(LoadLedgerAspectDiscrepancy::class)->execute($case, 'D1'),
        );

        $this->assertTrue($result['review_required']);
        $this->assertSame('SOURCE_INCOMPLETE', $result['reason_code']);
    }

    public function test_it_requires_an_existing_service_transaction(): void
    {
        $case = $this->makeCase();

        try {
            app(LoadLedgerAspectDiscrepancy::class)->execute($case, 'A2');
            $this->fail('The aggregator must require an existing service transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('ASPECT_SOURCE_READING_CONTEXT_REQUIRED', $exception->getMessage());
        }
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
            'referral_source' => 'default', 'source_system' => 'R2_LEDGER_DISCREPANCY_TEST',
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
    ): int {
        $participantId = (int) DB::table('assessment_cases')->where('id', $caseId)->value('participant_id');
        $definitionSource = [
            'instrument' => $instrumentCode, 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-aggregator-test-only', 'total_duration_seconds' => 60,
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
            'assessment_case_id' => $caseId, 'test_type' => $instrumentCode, 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $definition->totalDurationSeconds, 'status' => 'submitted', 'answers_revision' => 1,
            'started_at' => '2026-09-21 03:00:00.000000+00:00', 'ends_at' => '2026-09-21 03:30:00.000000+00:00',
            'submitted_at' => '2026-09-21 03:20:00.000000+00:00',
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
            'attempt_no' => 1, 'submitted_at' => '2026-09-21 03:20:00.000000+00:00', 'answers_revision' => 1,
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
