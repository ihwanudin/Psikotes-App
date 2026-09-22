<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\AssessmentSessions\SessionDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F2 lane G7 (2026-09-20). SQLite-side proof for
 * database/migrations/2026_09_20_000200_widen_generic_instrument_result_source_fractional_columns.php:
 * the fresh migrate leaves fractional values byte-exact (not just "close
 * enough"), and the table's hand-written append-only guard triggers — which
 * Laravel's SQLite column-rebuild does not know about — are still present
 * and still enforced afterward. See that migration's docblock for why this
 * matters: Laravel rebuilds the whole SQLite table to change a column type,
 * and a rebuild that silently dropped these triggers would be a real
 * regression in the ledger's append-only guarantee.
 */
final class GenericInstrumentResultSourceFractionalColumnsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_fractional_raw_score_and_band_round_trip_exactly(): void
    {
        $fixture = $this->makeMinimalResultRow();

        // The exact golden-test values from SCORING_ALGORITHM.md ("Panker
        // 15,86... Hanker -0,622"), stored and read back through the real
        // column, not asserted against a PHP float in memory.
        DB::table('generic_instrument_result_sources')->insert([
            'result_id' => $fixture, 'ordinal' => 1, 'source_code' => 'PANKER',
            'raw_score' => '15.860', 'standard_score' => 9, 'source_score' => 9,
            'level' => 5, 'category' => 'synthetic', 'band_low' => '15.485', 'band_high' => '16.700',
            'created_at' => now(),
        ]);
        DB::table('generic_instrument_result_sources')->insert([
            'result_id' => $fixture, 'ordinal' => 2, 'source_code' => 'HANKER',
            'raw_score' => '-0.622', 'standard_score' => 1, 'source_score' => 1,
            'level' => 1, 'category' => 'synthetic', 'band_low' => '-0.761', 'band_high' => '0.749',
            'created_at' => now(),
        ]);

        $panker = DB::table('generic_instrument_result_sources')->where('source_code', 'PANKER')->sole();
        $hanker = DB::table('generic_instrument_result_sources')->where('source_code', 'HANKER')->sole();

        // SQLite's dynamic column affinity may drop an insignificant trailing
        // zero (storing "15.860" back out as "15.86"); that is not a
        // precision loss — the numeric value and its three meaningful
        // decimal digits are unchanged. Comparing as floats (not by
        // formatted string) is the correct test for "the value round-tripped
        // exactly", which PostgreSQL's true numeric(8,3) type preserves
        // byte-for-byte and SQLite preserves value-for-value.
        $this->assertSame(15.86, (float) $panker->raw_score);
        $this->assertSame(15.485, (float) $panker->band_low);
        $this->assertSame(16.7, (float) $panker->band_high);
        $this->assertSame(-0.622, (float) $hanker->raw_score);
        $this->assertSame(-0.761, (float) $hanker->band_low);
        $this->assertSame(0.749, (float) $hanker->band_high);
    }

    public function test_existing_integer_style_values_still_round_trip(): void
    {
        $fixture = $this->makeMinimalResultRow();
        DB::table('generic_instrument_result_sources')->insert([
            'result_id' => $fixture, 'ordinal' => 1, 'source_code' => 'TIANKER',
            'raw_score' => 7, 'standard_score' => 3, 'source_score' => 3,
            'level' => 3, 'category' => 'synthetic', 'band_low' => 90, 'band_high' => 109,
            'created_at' => now(),
        ]);

        $row = DB::table('generic_instrument_result_sources')->where('source_code', 'TIANKER')->sole();
        $this->assertSame('7', $this->normalizeDecimal($row->raw_score));
        $this->assertSame('90', $this->normalizeDecimal($row->band_low));
        $this->assertSame('109', $this->normalizeDecimal($row->band_high));
    }

    public function test_append_only_guard_triggers_survive_the_sqlite_column_rebuild(): void
    {
        $fixture = $this->makeMinimalResultRow();
        $sourceId = DB::table('generic_instrument_result_sources')->insertGetId([
            'result_id' => $fixture, 'ordinal' => 1, 'source_code' => 'PANKER',
            'raw_score' => '1.000', 'standard_score' => 1, 'source_score' => 1,
            'level' => 1, 'category' => 'synthetic', 'band_low' => 1, 'band_high' => 1,
            'created_at' => now(),
        ]);

        try {
            DB::table('generic_instrument_result_sources')->where('id', $sourceId)
                ->update(['raw_score' => '2.000']);
            $this->fail('The append-only update guard must still be enforced after the column rebuild.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            DB::table('generic_instrument_result_sources')->where('id', $sourceId)->delete();
            $this->fail('The append-only delete guard must still be enforced after the column rebuild.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('generic_instrument_result_sources')->count());
    }

    private function normalizeDecimal(mixed $value): string
    {
        // SQLite's decimal columns are stored as-is when written as a string
        // and read back as a string via PDO here; normalize only to strip an
        // inconsequential leading "+" some drivers may add, never rounding.
        return ltrim((string) $value, '+');
    }

    private function makeMinimalResultRow(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'source_system' => 'R2_MIGRATION_TEST',
            'full_name' => $key, 'phone' => '620000000000',
        ]);
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Instrument/shape realism does not matter for this migration test —
        // only that a real generic_instrument_results row exists to attach
        // source rows to; 'ist' with a trivial fixed shape avoids Kraepelin's
        // generator-linked item-count validation entirely.
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-migration-test-only', 'total_duration_seconds' => 60,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 60, 'item_count' => 1]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource,
            'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $definitionPayload = json_encode($definition->toArray(), JSON_THROW_ON_ERROR);
        $sessionPublicId = (string) Str::ulid();
        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $definition->totalDurationSeconds, 'status' => 'submitted', 'answers_revision' => 1,
            'started_at' => '2026-09-20 03:00:00.000000+00:00', 'ends_at' => '2026-09-20 03:30:00.000000+00:00',
            'submitted_at' => '2026-09-20 03:20:00.000000+00:00',
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => $definitionPayload,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $instrumentPayload = json_encode(['version' => 'synthetic-v1'], JSON_THROW_ON_ERROR);
        $instrumentVersionId = DB::table('instrument_versions')->insertGetId([
            'code' => 'ist', 'version' => 'synthetic-v1', 'source_file' => 'synthetic-ist.json',
            'checksum' => hash('sha256', $instrumentPayload), 'payload' => $instrumentPayload,
            'source_text' => $instrumentPayload, 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $instrumentVersion = DB::table('instrument_versions')->where('id', $instrumentVersionId)
            ->first(['id', 'version', 'source_file', 'checksum']);
        $resultPayload = json_encode(['synthetic' => true], JSON_THROW_ON_ERROR);

        return DB::table('generic_instrument_results')->insertGetId([
            'public_id' => strtoupper((string) Str::ulid()), 'assessment_case_id' => $case,
            'session_id' => $sessionId, 'participant_id' => $participant,
            'session_public_id' => $sessionPublicId, 'instrument_code' => 'ist',
            'attempt_no' => 1, 'submitted_at' => '2026-09-20 03:20:00.000000+00:00', 'answers_revision' => 1,
            'sealed_source_checksum' => hash('sha256', 'synthetic-source'),
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => $definitionPayload,
            'instrument_version_id' => $instrumentVersion->id, 'instrument_version' => $instrumentVersion->version,
            'instrument_source_file' => $instrumentVersion->source_file,
            'instrument_checksum' => $instrumentVersion->checksum,
            'result_contract_version' => 'synthetic-result:v1', 'engine_version' => 'synthetic-scoring:v1',
            'result_payload' => $resultPayload,
            'result_checksum' => hash('sha256', $resultPayload), 'created_at' => now(),
        ]);
    }
}
