<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture;

final class GenericAssessmentResultVersionsMigrationTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_sqlite_up_down_up_preserves_keys_and_append_only_version_invariants(): void
    {
        $this->assertSchemaContract();
        $migration = require database_path('migrations/2026_09_05_000100_create_generic_assessment_result_versions.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('generic_assessment_result_versions'));
        $parentIndexes = collect(DB::select("PRAGMA index_list('assessment_participants')"));
        $this->assertFalse($parentIndexes->contains(
            fn (object $index): bool => $index->name === 'assessment_participants_id_attempt_unique',
        ));

        $migration->up();
        $this->assertSchemaContract();
        $fixture = AssessmentBillingFixture::create('self');
        $attempt = DB::table('assessment_participants')->where('id', $fixture['attempt'])->sole();
        $first = $this->row((int) $attempt->id, (string) $attempt->assessment_attempt_id, 1);
        DB::table('generic_assessment_result_versions')->insert($first);
        $second = $this->row((int) $attempt->id, (string) $attempt->assessment_attempt_id, 2, $first['id'], 101.25);
        DB::table('generic_assessment_result_versions')->insert($second);

        $this->assertDatabaseCount('generic_assessment_result_versions', 2);
        $this->assertQueryFails(fn () => DB::table('generic_assessment_result_versions')->insert(
            $this->row((int) $attempt->id, (string) $attempt->assessment_attempt_id, 2, $first['id'], 102.5),
        ));
        $this->assertQueryFails(fn () => DB::table('generic_assessment_result_versions')->insert(
            $this->row((int) $attempt->id, (string) Str::ulid(), 1),
        ));
        $this->assertQueryFails(fn () => DB::table('generic_assessment_result_versions')
            ->where('id', $first['id'])->update(['iq' => 99, 'iq_canonical' => '99']));
        $this->assertQueryFails(fn () => DB::table('generic_assessment_result_versions')
            ->where('id', $first['id'])->delete());
        $this->assertDatabaseCount('generic_assessment_result_versions', 2);
    }

    private function assertSchemaContract(): void
    {
        $this->assertTrue(Schema::hasTable('generic_assessment_result_versions'));
        $columns = collect(DB::select("PRAGMA table_info('generic_assessment_result_versions')"));
        $this->assertSame(1, (int) $columns->firstWhere('name', 'id')->pk);
        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('generic_assessment_result_versions')"));
        $self = $foreignKeys->first(
            fn (object $foreign): bool => $foreign->from === 'supersedes_id'
                && $foreign->table === 'generic_assessment_result_versions' && $foreign->to === 'id',
        );
        $this->assertNotNull($self);
        $this->assertSame('RESTRICT', $self->on_delete);
        $owner = $foreignKeys->filter(fn (object $foreign): bool => $foreign->table === 'assessment_participants')
            ->groupBy('id')->first(fn ($group): bool => $group->count() === 2);
        $this->assertNotNull($owner);
        $this->assertSame(['assessment_attempt_id', 'assessment_participant_id'],
            $owner->pluck('from')->sort()->values()->all());
        $indexes = collect(DB::select("PRAGMA index_list('generic_assessment_result_versions')"));
        foreach (['generic_result_attempt_version_unique', 'generic_result_supersedes_unique'] as $name) {
            $index = $indexes->firstWhere('name', $name);
            $this->assertNotNull($index, $name);
            $this->assertSame(1, (int) $index->unique, $name);
        }
        $parentIndexes = collect(DB::select("PRAGMA index_list('assessment_participants')"));
        $this->assertTrue($parentIndexes->contains(
            fn (object $index): bool => $index->name === 'assessment_participants_id_attempt_unique'
                && (int) $index->unique === 1,
        ));
    }

    /** @return array<string, mixed> */
    private function row(int $attemptId, string $attemptReference, int $version,
        ?string $supersedes = null, float $iq = 100.5): array
    {
        $id = (string) Str::ulid();

        return [
            'id' => $id, 'assessment_participant_id' => $attemptId,
            'assessment_attempt_id' => $attemptReference, 'result_version' => $version,
            'supersedes_id' => $supersedes, 'iq' => $iq, 'iq_canonical' => (string) $iq,
            'engine_version' => 'synthetic-1.0', 'completed_at' => '2026-09-05 05:00:00',
            'finality' => 'FINALIZED', 'revoked_at' => null,
            'result_checksum' => hash('sha256', $id), 'created_at' => '2026-09-05 05:01:00',
        ];
    }

    private function assertQueryFails(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the database invariant to reject the operation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
