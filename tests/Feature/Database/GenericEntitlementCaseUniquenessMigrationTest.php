<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class GenericEntitlementCaseUniquenessMigrationTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->migration('down');
    }

    public function test_generic_uniqueness_is_case_scoped_while_dass_remains_a_participant_singleton(): void
    {
        $graph = $this->integratedGraph('scope');
        $this->migration('up');

        DB::table('entitlements')->insert($this->entitlement($graph['participant'], $graph['cases'][0], 'ist'));
        DB::table('entitlements')->insert($this->entitlement($graph['participant'], $graph['cases'][1], 'ist'));
        DB::table('entitlements')->insert($this->entitlement($graph['participant'], null, 'dass21'));

        $this->assertSame(2, DB::table('entitlements')->where('test_type', 'ist')->count());
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlement($graph['participant'], $graph['cases'][0], 'ist'),
        ));
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlement($graph['participant'], null, 'dass21'),
        ));
        $this->assertNull(DB::table('entitlements')->where('test_type', 'dass21')->sole()->assessment_case_id);
    }

    public function test_down_refuses_multi_case_history_and_preserves_the_new_indexes(): void
    {
        $graph = $this->integratedGraph('rollback');
        $this->migration('up');
        DB::table('entitlements')->insert($this->entitlement($graph['participant'], $graph['cases'][0], 'ist'));
        DB::table('entitlements')->insert($this->entitlement($graph['participant'], $graph['cases'][1], 'ist'));
        $before = $this->indexes();

        try {
            $this->migration('down');
            $this->fail('Multi-case history must prevent legacy uniqueness restoration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('multi-case entitlement history prevents rollback', $exception->getMessage());
        }

        $this->assertSame($before, $this->indexes());
    }

    public function test_up_is_idempotent_and_counterfeit_named_index_fails_closed(): void
    {
        $this->migration('up');
        $exact = $this->indexes();
        $this->migration('up');
        $this->assertSame($exact, $this->indexes());

        $this->migration('down');
        $down = $this->indexes();
        $this->assertArrayHasKey('entitlements_participant_id_test_type_unique', $down);
        $this->assertArrayNotHasKey('entitlements_dass_participant_unique', $down);
        $this->migration('up');
        $this->assertSame($exact, $this->indexes());

        DB::statement('DROP INDEX entitlements_dass_participant_unique');
        DB::statement("CREATE UNIQUE INDEX entitlements_dass_participant_unique ON entitlements (participant_id) WHERE test_type = 'ist'");
        $counterfeit = $this->indexes();

        try {
            $this->migration('up');
            $this->fail('Counterfeit uniqueness index must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('counterfeit or partial uniqueness state', $exception->getMessage());
        }
        $this->assertSame($counterfeit, $this->indexes());
    }

    public function test_up_refuses_to_repair_a_missing_000400_boundary(): void
    {
        $requirement = require database_path('migrations/2026_09_10_000400_enforce_generic_entitlement_case_identity.php');
        $this->assertInstanceOf(Migration::class, $requirement);
        (new \ReflectionMethod($requirement, 'down'))->invoke($requirement);
        $before = $this->sqliteSchema();

        try {
            $this->migration('up');
            $this->fail('Missing 000400 boundary must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('000400 requirement and graph guard state is not exact', $exception->getMessage());
        }
        $this->assertSame($before, $this->sqliteSchema());
    }

    /** @return array{participant:int,cases:list<int>} */
    private function integratedGraph(string $suffix): array
    {
        $key = strtoupper(substr(hash('sha256', $suffix), 0, 20));
        $branch = DB::table('branches')->insertGetId(['code' => $key, 'name' => $suffix, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $suffix]);
        $package = DB::table('packages')->insertGetId(['code' => 'uniq-'.$suffix, 'name' => $suffix,
            'amount' => 99000, 'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['dass21', 'ist'] as $sort => $type) {
            DB::table('package_items')->insert(['package_id' => $package, 'test_type' => $type,
                'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now()]);
        }
        $participant = DB::table('participants')->insertGetId(['branch_id' => $branch,
            'referral_branch_id' => $branch, 'referral_source' => 'manual', 'package_id' => $package,
            'source_system' => 'SYNTHETIC', 'full_name' => $suffix, 'intended_field' => 'UMUM',
            'phone' => '620000000001']);
        $client = DB::table('integration_clients')->insertGetId(['organization_id' => $branch,
            'client_id' => 'uniq-'.$suffix, 'credential_reference' => 'synthetic',
            'result_delivery_mode' => 'POLL', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $cases = [];
        foreach ([1, 2] as $number) {
            $publicId = (string) Str::ulid();
            $case = DB::table('assessment_cases')->insertGetId(['public_id' => $publicId,
                'participant_id' => $participant, 'organization_id' => $branch, 'package_id' => $package,
                'origin' => 'INTEGRATED', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('assessment_participants')->insert(['assessment_case_id' => $case,
                'integration_client_id' => $client, 'organization_id' => $branch, 'participant_id' => $participant,
                'package_id' => $package, 'assessment_attempt_id' => $publicId, 'source_system' => 'SYNTHETIC',
                'external_candidate_id' => "candidate-{$suffix}-{$number}", 'funding_mode' => 'SPONSORED',
                'assessment_status' => 'READY', 'result_version' => 0, 'idempotency_key' => "key-{$suffix}-{$number}",
                'request_hash' => hash('sha256', "request-{$suffix}-{$number}"),
                'logical_assessment_key' => hash('sha256', "logical-{$suffix}-{$number}"),
                'created_at' => now(), 'updated_at' => now()]);
            $cases[] = $case;
        }

        return compact('participant', 'cases');
    }

    /** @return array<string,mixed> */
    private function entitlement(int $participant, ?int $case, string $type): array
    {
        return ['participant_id' => $participant, 'order_id' => null, 'assessment_case_id' => $case,
            'test_type' => $type, 'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Uniqueness was not enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string,string> */
    private function indexes(): array
    {
        return collect(DB::select("SELECT name,sql FROM sqlite_master WHERE type='index' AND tbl_name='entitlements' AND sql IS NOT NULL ORDER BY name"))
            ->mapWithKeys(function (object $row): array {
                $data = (array) $row;

                return [(string) $data['name'] => (string) $data['sql']];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function sqliteSchema(): array
    {
        return array_values(array_map(static fn (object $row): array => (array) $row, DB::select(
            "SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name",
        )));
    }

    private function migration(string $operation): void
    {
        $migration = require database_path('migrations/2026_09_10_000500_contract_generic_entitlement_uniqueness.php');
        if (! $migration instanceof Migration || ! in_array($operation, ['up', 'down'], true)) {
            throw new RuntimeException('Uniqueness migration unavailable.');
        }
        (new \ReflectionMethod($migration, $operation))->invoke($migration);
    }
}
