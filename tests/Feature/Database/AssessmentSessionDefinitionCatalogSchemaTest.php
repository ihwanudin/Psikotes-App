<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class AssessmentSessionDefinitionCatalogSchemaTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    public function test_catalog_starts_empty_with_exact_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('assessment_session_definitions'));
        $this->assertSame(0, DB::table('assessment_session_definitions')->count());
        $this->assertSame([
            'activated_at', 'deactivated_at', 'id', 'instrument', 'is_active', 'provenance',
            'template_checksum', 'template_payload', 'version',
        ], collect(DB::select("PRAGMA table_info('assessment_session_definitions')"))
            ->pluck('name')->sort()->values()->all());

        $indexes = collect(DB::select("PRAGMA index_list('assessment_session_definitions')"))
            ->pluck('name')->all();
        $this->assertContains('assessment_session_definitions_instrument_version_unique', $indexes);
        $this->assertContains('assessment_session_definitions_one_active_instrument_unique', $indexes);
    }

    public function test_catalog_rejects_dass_duplicate_active_and_noncanonical_identity(): void
    {
        $this->insert($this->template('ist', 'v1'));

        $this->assertRejected(fn () => $this->insert($this->template('ist', 'v2')));
        $this->assertRejected(fn () => $this->insert($this->template('dass21', 'v1')));
        $this->assertRejected(fn () => $this->insert($this->template('papi', ' bad')));
    }

    public function test_only_one_way_deactivation_is_mutable_and_history_cannot_be_deleted(): void
    {
        $this->insert($this->template('ist', 'v1'));

        DB::table('assessment_session_definitions')->where('instrument', 'ist')->update([
            'is_active' => false,
            'deactivated_at' => now()->addSecond(),
        ]);
        $this->insert($this->template('ist', 'v2'));

        $this->assertRejected(fn () => DB::table('assessment_session_definitions')
            ->where('version', 'v1')->update([
                'is_active' => true,
                'deactivated_at' => null,
            ]));
        $this->assertRejected(fn () => DB::table('assessment_session_definitions')
            ->where('version', 'v2')->update(['provenance' => 'changed']));
        $this->assertRejected(fn () => DB::table('assessment_session_definitions')
            ->where('version', 'v1')->delete());
    }

    public function test_catalog_rejects_non_exact_subtests_and_generator_configuration(): void
    {
        $durationMismatch = $this->template('ist', 'duration-mismatch');
        $durationMismatch['subtests'][0]['duration_seconds'] = 30;
        $this->assertRejected(fn () => $this->insert($durationMismatch));

        $extraSubtestField = $this->template('papi', 'extra-subtest-field');
        $extraSubtestField['subtests'][0]['unexpected'] = true;
        $this->assertRejected(fn () => $this->insert($extraSubtestField));

        $kraepelin = $this->kraepelinTemplate();
        $kraepelin['generator']['columns'] = '50';
        $this->assertRejected(fn () => $this->insert($kraepelin));
    }

    public function test_populated_catalog_refuses_down_while_empty_catalog_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_09_10_000200_create_assessment_session_definitions.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('assessment_session_definitions'));
        $migration->up();

        $this->insert($this->template('ist', 'v1'));
        try {
            $migration->down();
            $this->fail('Populated definition history must refuse rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Assessment session definition history prevents rollback.', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('assessment_session_definitions'));
    }

    /** @param array<string, mixed> $template */
    private function insert(array $template): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('assessment_session_definitions')->insert([
            'instrument' => $template['instrument'],
            'version' => $template['version'],
            'provenance' => $template['provenance'],
            'template_checksum' => SessionDefinition::checksumFor($template),
            'template_payload' => json_encode($template, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'activated_at' => now(),
            'deactivated_at' => null,
        ]));
    }

    /** @return array<string, mixed> */
    private function template(string $instrument, string $version): array
    {
        return [
            'instrument' => $instrument,
            'version' => $version,
            'provenance' => 'synthetic-schema-test',
            'total_duration_seconds' => 60,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 60,
                'item_count' => 1,
            ]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function kraepelinTemplate(): array
    {
        return [
            'instrument' => 'kraepelin',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-schema-test',
            'total_duration_seconds' => 750,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 750,
                'item_count' => 1350,
            ]],
            'randomization' => 'seeded',
            'seed' => null,
            'generator' => [
                'algorithm' => 'synthetic-generator',
                'version' => 'synthetic-v1',
                'columns' => 50,
                'seconds_per_column' => 15,
                'numbers_per_column' => 28,
                'answer_slots_per_column' => 27,
            ],
        ];
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected catalog invariant rejection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
