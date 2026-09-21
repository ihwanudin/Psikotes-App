<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\Review\G7AspectResolution;
use App\Security\RlsContextRunner;
use App\Services\Eligibility\ResolveG7AspectFromLedger;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * F2 G7-server-side-sources, Phase 1 (2026-09-21). This mirrors, and must
 * keep matching, ReportSigningService::sign()'s existing per-aspect dispatch
 * (app/Services/Review/ReportSigningService.php:151-173) so that Phase 2's
 * swap changes only where the discrepancy comes from, not the decision
 * logic. See tasks/handoffs/f2/g7-server-side-sources.md.
 */
final class ResolveG7AspectFromLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        app(RlsContextRunner::class)->runAsService(fn () => (new InstrumentSeeder)->run());
    }

    public function test_no_discrepancy_resolves_not_required(): void
    {
        // D1 = [RMIB_Out] only -- a single source can never have a spread.
        $case = $this->makeCase();
        $this->makeGenericInstrumentResult($case, 'rmib', [['source_code' => 'Out', 'level' => 3]]);

        $resolution = app(RlsContextRunner::class)->runAsService(
            fn () => app(ResolveG7AspectFromLedger::class)->execute($case, 'D1', 3, null, null),
        );

        $this->assertSame(G7AspectResolution::STATE_NOT_REQUIRED, $resolution->state());
        $this->assertSame(3, $resolution->finalLevel());
    }

    public function test_not_required_rejects_resolution_data(): void
    {
        $case = $this->makeCase();
        $this->makeGenericInstrumentResult($case, 'rmib', [['source_code' => 'Out', 'level' => 3]]);

        $this->expectException(InvalidArgumentException::class);

        app(RlsContextRunner::class)->runAsService(
            fn () => app(ResolveG7AspectFromLedger::class)->execute($case, 'D1', 3, 4, 'Tidak seharusnya ada di sini.'),
        );
    }

    public function test_review_required_without_a_final_level_resolves_unresolved(): void
    {
        // Incomplete ledger data (no result at all) is review-required.
        $case = $this->makeCase();

        $resolution = app(RlsContextRunner::class)->runAsService(
            fn () => app(ResolveG7AspectFromLedger::class)->execute($case, 'D1', 3, null, null),
        );

        $this->assertSame(G7AspectResolution::STATE_UNRESOLVED, $resolution->state());
        $this->assertNull($resolution->finalLevel());
        $this->assertSame('SOURCE_INCOMPLETE', $resolution->discrepancy()['reason_code']);
    }

    public function test_review_required_with_a_final_level_resolves_resolved(): void
    {
        $case = $this->makeCase();

        $resolution = app(RlsContextRunner::class)->runAsService(
            fn () => app(ResolveG7AspectFromLedger::class)->execute(
                $case, 'D1', 3, 4, 'Bukti observasi mendukung level akhir empat.',
            ),
        );

        $this->assertSame(G7AspectResolution::STATE_RESOLVED, $resolution->state());
        $this->assertSame(4, $resolution->finalLevel());
        $this->assertSame('Bukti observasi mendukung level akhir empat.', $resolution->reason());
    }

    public function test_the_discrepancy_the_resolution_carries_is_the_ledger_one_not_a_caller_supplied_one(): void
    {
        // A2 with a genuine spread of 4 in the ledger -- execute() has no
        // parameter through which a caller could substitute a different one.
        $case = $this->makeCase();
        $this->makeGenericInstrumentResult($case, 'ist', [
            ['source_code' => 'AN', 'level' => 5],
            ['source_code' => 'RA', 'level' => 5],
            ['source_code' => 'ZR', 'level' => 5],
        ]);
        $this->makeGenericInstrumentResult($case, 'papi', [['source_code' => 'R', 'level' => 1]]);

        $resolution = app(RlsContextRunner::class)->runAsService(
            fn () => app(ResolveG7AspectFromLedger::class)->execute(
                $case, 'A2', 3, 4, 'Selisih sumber terverifikasi dari data ledger.',
            ),
        );

        $this->assertSame(4, $resolution->discrepancy()['provenance']['spread']);
        $this->assertSame('SOURCE_LEVEL_SPREAD', $resolution->discrepancy()['reason_code']);
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
            'referral_source' => 'default', 'source_system' => 'R2_RESOLVE_LEDGER_TEST',
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
            'provenance' => 'synthetic-resolve-ledger-test-only', 'total_duration_seconds' => 60,
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
