<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class AssessmentSessionDefinitionCatalogSchemaTest extends OrganizationPaymentTestCase
{
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

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

    public function test_catalog_identity_fields_match_the_domain_unicode_contract(): void
    {
        foreach ($this->nonCanonicalUnicodeTemplates() as $label => $template) {
            $this->assertRejected(fn () => $this->insert($template), $label);
        }

        $canonical = $this->kraepelinTemplate();
        $canonical['version'] = 'versi-é';
        $canonical['provenance'] = 'sumber-日本';
        $canonical['subtests'][0]['code'] = '分析';
        $canonical['generator']['algorithm'] = 'algoritme™\\u0000';
        $canonical['generator']['version'] = 'versi-é';
        $this->insert($canonical);

        $this->assertSame(
            'versi-é',
            DB::table('assessment_session_definitions')->value('version'),
        );
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
            'randomization' => 'fixed',
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

    /** @return array<string, array<string, mixed>> */
    private function nonCanonicalUnicodeTemplates(): array
    {
        $templates = [];
        foreach (['version', 'provenance', 'subtest_code'] as $field) {
            $template = $this->template('ist', 'unicode-'.$field);
            match ($field) {
                'version' => $template['version'] .= "\u{2060}",
                'provenance' => $template['provenance'] .= "\u{2060}",
                'subtest_code' => $template['subtests'][0]['code'] .= "\u{2060}",
            };
            $templates['word joiner in '.$field] = $template;
        }
        foreach (['algorithm', 'version'] as $field) {
            $template = $this->kraepelinTemplate();
            $template['version'] = 'unicode-generator-'.$field;
            $template['generator'][$field] .= "\u{2060}";
            $templates['word joiner in generator '.$field] = $template;
        }
        foreach (['version', 'provenance', 'subtest_code'] as $field) {
            $template = $this->template('ist', 'nul-'.$field);
            match ($field) {
                'version' => $template['version'] .= "\u{0000}",
                'provenance' => $template['provenance'] .= "\u{0000}",
                'subtest_code' => $template['subtests'][0]['code'] .= "\u{0000}",
            };
            $templates['nul in '.$field] = $template;
        }
        foreach (['algorithm', 'version'] as $field) {
            $template = $this->kraepelinTemplate();
            $template['version'] = 'nul-generator-'.$field;
            $template['generator'][$field] .= "\u{0000}";
            $templates['nul in generator '.$field] = $template;
        }

        $unassigned = $this->template('papi', "unassigned-\u{0378}");
        $templates['unassigned category Cn'] = $unassigned;
        $privateUse = $this->template('rmib', 'private-use');
        $privateUse['provenance'] .= "\u{E000}";
        $templates['private-use category Co'] = $privateUse;
        $separator = $this->template('papi', 'separator');
        $separator['subtests'][0]['code'] .= "\u{2007}";
        $templates['separator category Zs'] = $separator;

        return $templates;
    }

    private function assertRejected(callable $operation, string $message = ''): void
    {
        try {
            $operation();
            $this->fail('Expected catalog invariant rejection. '.$message);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
