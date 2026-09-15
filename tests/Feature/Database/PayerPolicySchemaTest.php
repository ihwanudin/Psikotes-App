<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Branch;
use App\Models\IntegrationSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\OrganizationPaymentTestCase;

final class PayerPolicySchemaTest extends OrganizationPaymentTestCase
{
    protected function tearDown(): void { try { $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true])); } finally { parent::tearDown(); } }
    protected function setUp(): void
    {
        parent::setUp();

        // The guarded application owns a fresh in-memory connection for each test.
        // Run DDL outside a transaction; only this migration's rollback is in scope.
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }

    public function test_new_organization_defaults_to_self_and_source_requires_opt_in(): void
    {
        [$branch, $source] = $this->createRegistry();

        $this->assertSame(['self'], Branch::findOrFail($branch)->allowed_payer_types);
        $this->assertSame([], IntegrationSource::findOrFail($source)->allowed_payer_types);
        $this->assertNull(IntegrationSource::findOrFail($source)->locked_payer_type);
    }

    public function test_explicit_policy_round_trips_through_models_without_creating_orders(): void
    {
        [$branchId, $sourceId] = $this->createRegistry();
        $branch = Branch::findOrFail($branchId);
        $source = IntegrationSource::findOrFail($sourceId);
        $branch->update(['allowed_payer_types' => ['self', 'organization']]);
        $source->update(['allowed_payer_types' => ['organization'], 'locked_payer_type' => 'organization']);

        $this->assertSame(['self', 'organization'], $branch->refresh()->allowed_payer_types);
        $this->assertSame(['organization'], $source->refresh()->allowed_payer_types);
        $this->assertSame('organization', $source->locked_payer_type);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('assessment_participants', 0);
    }

    public function test_upgrade_preserves_legacy_configuration_and_rollback_keeps_legacy_rows(): void
    {
        $migration = require database_path('migrations/2026_08_31_000100_add_payer_policy.php');
        $migration->down();
        [$branch, $source] = $this->createRegistry();
        $migration->up();

        $this->assertNull(Branch::findOrFail($branch)->allowed_payer_types);
        $this->assertNull(IntegrationSource::findOrFail($source)->allowed_payer_types);
        $this->assertNull(IntegrationSource::findOrFail($source)->locked_payer_type);
        $this->assertSame(['SPONSORED'], Branch::findOrFail($branch)->allowed_funding_modes);
        $this->assertSame(['SPONSORED'], IntegrationSource::findOrFail($source)->allowed_funding_modes);
        $this->assertSame('ACTIVE', IntegrationSource::findOrFail($source)->status);
        $this->assertSame('v1', IntegrationSource::findOrFail($source)->contract_version);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('branches', 'allowed_payer_types'));
        $this->assertFalse(Schema::hasColumn('integration_sources', 'locked_payer_type'));
        $this->assertDatabaseHas('branches', ['id' => $branch, 'name' => 'Synthetic policy']);
        $this->assertDatabaseHas('integration_sources', ['id' => $source, 'status' => 'ACTIVE']);
        $migration->up();
    }

    /** @return array{int, int} */
    private function createRegistry(): array
    {
        $branch = DB::table('branches')->insertGetId([
            'code' => 'POLICY', 'ref_code' => 'POLICY', 'name' => 'Synthetic policy',
            'organization_code' => 'POLICY', 'display_name' => 'Synthetic policy',
            'allowed_funding_modes' => '["SPONSORED"]',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $branch, 'client_id' => 'synthetic-policy',
            'credential_reference' => 'synthetic-not-a-secret',
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => 'SYNTHETIC_POLICY',
            'allowed_assessment_packages' => '[]', 'allowed_funding_modes' => '["SPONSORED"]',
        ]);

        return [$branch, $source];
    }
}
