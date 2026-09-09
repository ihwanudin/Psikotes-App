<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class LegacySelectionCaseIdentityMigrationTest extends OrganizationPaymentTestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->migration = require database_path('migrations/2026_09_09_000500_bind_legacy_selection_assessment_cases.php');
        $this->migration->down();
    }

    public function test_historical_selection_mapping_gets_an_opaque_case_without_inventing_package_or_field(): void
    {
        $fixture = $this->legacyFixture('history');

        $this->migration->up();

        $mapping = DB::table('selection_participants')->where('id', $fixture['selection'])->first();
        $case = DB::table('assessment_cases')->where('id', $mapping->assessment_case_id)->first();
        $this->assertTrue(Str::isUlid($case->public_id));
        $this->assertNotSame($fixture['candidate'], $case->public_id);
        $this->assertSame($fixture['participant'], $case->participant_id);
        $this->assertSame($fixture['branch'], $case->organization_id);
        $this->assertNull($case->package_id);
        $this->assertNull($case->intended_field_snapshot);
        $this->assertSame('LEGACY_SELECTION', $case->origin);
        $this->assertSame('2026-08-29 03:15:00', $case->created_at);

        $column = collect(DB::select("PRAGMA table_info('selection_participants')"))->firstWhere('name', 'assessment_case_id');
        $this->assertSame(1, (int) $column->notnull);
        $this->assertContains('selection_participants_case_unique', collect(DB::select(
            "PRAGMA index_list('selection_participants')",
        ))->pluck('name')->all());
        $foreign = collect(DB::select("PRAGMA foreign_key_list('selection_participants')"))
            ->filter(fn (object $row): bool => $row->table === 'assessment_cases')->sortBy('seq')->values();
        $this->assertSame(['assessment_case_id', 'participant_id'], $foreign->pluck('from')->all());
        $this->assertSame(['id', 'participant_id'], $foreign->pluck('to')->all());
    }

    public function test_wrong_source_history_aborts_without_leaving_column_or_case(): void
    {
        $fixture = $this->legacyFixture('wrong-source');
        DB::table('participants')->where('id', $fixture['participant'])->update(['source_system' => 'DIRECT_PUBLIC']);

        try {
            $this->migration->up();
            $this->fail('Wrong source history must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('historical Selection identity is incomplete', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('selection_participants', 'assessment_case_id'));
        $this->assertSame(0, DB::table('assessment_cases')->count());
    }

    public function test_guards_reject_wrong_origin_and_identity_rebinding(): void
    {
        $fixture = $this->legacyFixture('guard');
        $this->migration->up();
        $mapping = DB::table('selection_participants')->where('id', $fixture['selection'])->first();

        $other = $this->participant('other', 'SELEKSI_BEASISWA_JEPANG');
        $directCase = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $other['participant'],
            'organization_id' => $other['branch'], 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertRejected(fn () => DB::table('selection_participants')->insert([
            'client_id' => 'other-client', 'external_candidate_id' => 'other-candidate',
            'selection_round_id' => 'other-round', 'registration_id' => 'other-registration',
            'participant_id' => $other['participant'], 'assessment_case_id' => $directCase,
            'idempotency_key' => 'other-key', 'request_hash' => hash('sha256', 'other'),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertRejected(fn () => DB::table('selection_participants')->where('id', $fixture['selection'])
            ->update(['assessment_case_id' => $directCase]));
        $this->assertSame($mapping->assessment_case_id, DB::table('selection_participants')
            ->where('id', $fixture['selection'])->value('assessment_case_id'));
    }

    public function test_populated_rollback_refuses_without_changing_history(): void
    {
        $fixture = $this->legacyFixture('rollback');
        $this->migration->up();
        $case = DB::table('selection_participants')->where('id', $fixture['selection'])->value('assessment_case_id');

        try {
            $this->migration->down();
            $this->fail('Populated rollback must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Legacy Selection case history prevents rollback.', $exception->getMessage());
        }

        $this->assertSame($case, DB::table('selection_participants')->where('id', $fixture['selection'])
            ->value('assessment_case_id'));
    }

    /** @return array{branch:int,participant:int,selection:int,candidate:string} */
    private function legacyFixture(string $suffix): array
    {
        $graph = $this->participant($suffix, 'SELEKSI_BEASISWA_JEPANG');
        $candidate = 'candidate-'.$suffix;
        $selection = DB::table('selection_participants')->insertGetId([
            'client_id' => 'client-'.$suffix, 'external_candidate_id' => $candidate,
            'selection_round_id' => 'round-'.$suffix, 'registration_id' => 'registration-'.$suffix,
            'participant_id' => $graph['participant'], 'idempotency_key' => 'key-'.$suffix,
            'request_hash' => hash('sha256', $suffix),
            'created_at' => '2026-08-29 03:15:00', 'updated_at' => '2026-08-29 03:15:00',
        ]);

        return [...$graph, 'selection' => $selection, 'candidate' => $candidate];
    }

    /** @return array{branch:int,participant:int} */
    private function participant(string $suffix, string $source): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $suffix, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
            'source_system' => $source, 'full_name' => $suffix, 'intended_field' => 'KAIGO',
            'phone' => '620000000000',
        ]);

        return compact('branch', 'participant');
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Database invariant was not enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
