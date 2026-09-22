<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * F2 lane G7 (2026-09-20). PostgreSQL-authoritative proof for
 * database/migrations/2026_09_20_000200_widen_generic_instrument_result_source_fractional_columns.php,
 * required by Lead before Kraepelin's persistence classes: the exact golden
 * values from SCORING_ALGORITHM.md ("Panker 15,86... Hanker -0,622") persist
 * and re-read byte-for-byte identical through real `numeric(8,3)` columns —
 * not merely numerically close, but the exact decimal PostgreSQL stores.
 *
 * `generic_instrument_results`/`generic_instrument_result_sources` are
 * append-only (no DELETE, ever, per their own guard triggers/RLS), so every
 * mutation here runs inside an explicit outer transaction that is always
 * rolled back at the end of the test — the same convention
 * GenericInstrumentResultLedgerTest already uses — instead of relying on
 * cleanup, which these tables structurally cannot support.
 */
final class GenericInstrumentResultSourceFractionalColumnsTest extends TestCase
{
    public function test_column_types_are_numeric_with_the_expected_precision_and_scale(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_name', 'generic_instrument_result_sources')
            ->whereIn('column_name', ['raw_score', 'band_low', 'band_high', 'standard_score', 'source_score'])
            ->get(['column_name', 'data_type', 'numeric_precision', 'numeric_scale']);
        $byColumn = $columns->keyBy('column_name');

        foreach (['raw_score', 'band_low', 'band_high'] as $widened) {
            $this->assertSame('numeric', $byColumn[$widened]->data_type, $widened);
            $this->assertSame(8, $byColumn[$widened]->numeric_precision, $widened);
            $this->assertSame(3, $byColumn[$widened]->numeric_scale, $widened);
        }
        // Deliberately NOT widened — see the migration's docblock and
        // tasks/handoffs/f2/generic-instrument-result-field-mapping.md.
        foreach (['standard_score', 'source_score'] as $untouched) {
            $this->assertSame('integer', $byColumn[$untouched]->data_type, $untouched);
        }
    }

    /**
     * Ground truth for whether `raw_score >= 0` (written for IST/PAPI/RMIB's
     * non-negative raw counts) still rejects Kraepelin's negative Hanker.
     * Read the live constraint definition directly rather than trust the
     * original migration's literal SQL text, which this migration's
     * ALTER COLUMN TYPE does not edit.
     */
    public function test_the_contract_check_constraint_permits_a_negative_raw_score(): void
    {
        $definition = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint
                WHERE conname = 'generic_instrument_result_sources_contract_check'",
        );
        $this->assertNotNull($definition);
        $this->assertStringNotContainsString(
            'raw_score >= 0',
            $definition->definition,
            'If this fails, the live constraint still rejects negative raw_score — Kraepelin\'s Hanker '
            .'(e.g. -0.622) would be rejected at INSERT and this migration is incomplete.',
        );

        DB::beginTransaction();
        try {
            $resultId = app(RlsContextRunner::class)->runAsService(fn (): int => $this->makeResultRow());
            app(RlsContextRunner::class)->runAsService(fn () => DB::table('generic_instrument_result_sources')->insert([
                'result_id' => $resultId, 'ordinal' => 1, 'source_code' => 'HANKER',
                'raw_score' => '-0.622', 'standard_score' => 1, 'source_score' => 1,
                'level' => 1, 'category' => 'synthetic', 'band_low' => '-0.761', 'band_high' => '0.749',
                'created_at' => now(),
            ]));
            $this->assertSame(1, DB::table('generic_instrument_result_sources')
                ->where('result_id', $resultId)->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_exact_golden_values_round_trip_byte_for_byte(): void
    {
        DB::beginTransaction();
        try {
            $resultId = app(RlsContextRunner::class)->runAsService(fn (): int => $this->makeResultRow());

            app(RlsContextRunner::class)->runAsService(function () use ($resultId): void {
                DB::table('generic_instrument_result_sources')->insert([
                    'result_id' => $resultId, 'ordinal' => 1, 'source_code' => 'PANKER',
                    'raw_score' => '15.860', 'standard_score' => 9, 'source_score' => 9,
                    'level' => 5, 'category' => 'synthetic', 'band_low' => '15.485', 'band_high' => '16.700',
                    'created_at' => now(),
                ]);
                DB::table('generic_instrument_result_sources')->insert([
                    'result_id' => $resultId, 'ordinal' => 2, 'source_code' => 'HANKER',
                    'raw_score' => '-0.622', 'standard_score' => 1, 'source_score' => 1,
                    'level' => 1, 'category' => 'synthetic', 'band_low' => '-0.761', 'band_high' => '0.749',
                    'created_at' => now(),
                ]);
            });

            [$panker, $hanker] = app(RlsContextRunner::class)->runAsService(fn (): array => [
                DB::table('generic_instrument_result_sources')->where('result_id', $resultId)
                    ->where('source_code', 'PANKER')->sole(),
                DB::table('generic_instrument_result_sources')->where('result_id', $resultId)
                    ->where('source_code', 'HANKER')->sole(),
            ]);

            // PostgreSQL's true numeric(8,3) preserves the declared scale
            // exactly — the trailing zero in "15.860" and "16.700" is part of
            // the stored value, not formatting SELECT added.
            $this->assertSame('15.860', $panker->raw_score);
            $this->assertSame('15.485', $panker->band_low);
            $this->assertSame('16.700', $panker->band_high);
            $this->assertSame('-0.622', $hanker->raw_score);
            $this->assertSame('-0.761', $hanker->band_low);
            $this->assertSame('0.749', $hanker->band_high);
        } finally {
            DB::rollBack();
        }
    }

    public function test_append_only_guard_still_rejects_update_and_delete_after_the_type_change(): void
    {
        DB::beginTransaction();
        try {
            $resultId = app(RlsContextRunner::class)->runAsService(fn (): int => $this->makeResultRow());
            $sourceId = app(RlsContextRunner::class)->runAsService(
                fn (): int => DB::table('generic_instrument_result_sources')->insertGetId([
                    'result_id' => $resultId, 'ordinal' => 1, 'source_code' => 'PANKER',
                    'raw_score' => '1.000', 'standard_score' => 1, 'source_score' => 1,
                    'level' => 1, 'category' => 'synthetic', 'band_low' => 1, 'band_high' => 1,
                    'created_at' => now(),
                ]),
            );

            // psikotes_runtime is only ever GRANTed SELECT/INSERT on this
            // table (2026_09_13_000100_create_generic_instrument_result_ledger.php:247);
            // UPDATE/DELETE are refused by the GRANT itself (42501) before the
            // append-only guard trigger is ever reached for this role. The
            // trigger is defense-in-depth for a higher-privilege connection,
            // proven separately by the owner-rerun tests in
            // GenericInstrumentResultLedgerTest, not by this runtime-role test.
            $this->assertSqlState('42501', fn () => app(RlsContextRunner::class)->runAsService(
                fn () => DB::table('generic_instrument_result_sources')->where('id', $sourceId)
                    ->update(['raw_score' => '2.000']),
            ));
            $this->assertSqlState('42501', fn () => app(RlsContextRunner::class)->runAsService(
                fn () => DB::table('generic_instrument_result_sources')->where('id', $sourceId)->delete(),
            ));
        } finally {
            DB::rollBack();
        }
    }

    private function makeResultRow(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'source_system' => 'R2_PG_MIGRATION_TEST',
            'full_name' => $key, 'phone' => '620000000000',
        ]);
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-pg-migration-test-only', 'total_duration_seconds' => 60,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 60, 'item_count' => 1]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $definitionPayload = json_encode($definition->toArray(), JSON_THROW_ON_ERROR);
        $sessionPublicId = (string) Str::ulid();
        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $definition->totalDurationSeconds, 'status' => 'submitted', 'answers_revision' => 1,
            'started_at' => now()->subMinutes(30), 'ends_at' => now(), 'submitted_at' => now(),
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => $definitionPayload,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $instrumentPayload = json_encode(['version' => 'synthetic-pg-v1'], JSON_THROW_ON_ERROR);
        $instrumentVersionId = DB::table('instrument_versions')->insertGetId([
            'code' => 'ist', 'version' => 'synthetic-pg-v1', 'source_file' => 'synthetic-pg-ist.json',
            'checksum' => hash('sha256', $instrumentPayload), 'payload' => $instrumentPayload,
            'source_text' => $instrumentPayload, 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resultPayload = json_encode(['synthetic' => true], JSON_THROW_ON_ERROR);

        return DB::table('generic_instrument_results')->insertGetId([
            'public_id' => strtoupper((string) Str::ulid()), 'assessment_case_id' => $case,
            'session_id' => $sessionId, 'participant_id' => $participant,
            'session_public_id' => $sessionPublicId, 'instrument_code' => 'ist',
            'attempt_no' => 1, 'submitted_at' => DB::table('test_sessions')->where('id', $sessionId)->value('submitted_at'),
            'answers_revision' => 1, 'sealed_source_checksum' => hash('sha256', 'synthetic-source'),
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => $definitionPayload,
            'instrument_version_id' => $instrumentVersionId, 'instrument_version' => 'synthetic-pg-v1',
            'instrument_source_file' => 'synthetic-pg-ist.json', 'instrument_checksum' => hash('sha256', $instrumentPayload),
            'result_contract_version' => 'synthetic-result:v1', 'engine_version' => 'synthetic-scoring:v1',
            'result_payload' => $resultPayload,
            'result_checksum' => hash('sha256', $resultPayload), 'created_at' => now(),
        ]);
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        }
    }
}
