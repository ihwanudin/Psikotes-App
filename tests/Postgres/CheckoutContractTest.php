<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\IntegrationContractViolation;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutContractAdapter;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

final class CheckoutContractTest extends TestCase
{
    public function test_cutover_guard_sees_marker_under_runtime_rls_and_preserves_scope(): void
    {
        DB::beginTransaction();
        try {
            $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
            app(RlsContextRunner::class)->runAsService(function (): void {
                $organization = Branch::create([
                    'code' => 'P5', 'ref_code' => 'P5', 'name' => 'Synthetic',
                    'organization_code' => 'P5', 'display_name' => 'Synthetic',
                ]);
                $client = IntegrationClient::create([
                    'organization_id' => $organization->id, 'client_id' => 'p5-test',
                    'credential_reference' => 'synthetic-only',
                ]);
                IntegrationSource::create([
                    'integration_client_id' => $client->id, 'source_system' => 'P5_SOURCE',
                    'contract_version' => 'checkout-v2', 'status' => 'SUSPENDED',
                    'allowed_assessment_packages' => [], 'allowed_funding_modes' => [],
                ]);
                $adapter = app(CheckoutContractAdapter::class);
                $adapter->assertLegacyAllowed($organization->id, 'OTHER_SOURCE');
                $adapter->assertLegacyAllowed($organization->id + 1, 'P5_SOURCE');
                try {
                    $adapter->assertLegacyAllowed($organization->id, 'P5_SOURCE');
                    $this->fail('Cutover hidden by RLS.');
                } catch (IntegrationContractViolation $exception) {
                    $this->assertSame('CHECKOUT_CONTRACT_REQUIRED', $exception->errorCode);
                }
                $this->assertSame(0, DB::table('orders')->count());
                $this->assertSame('service', DB::selectOne("SELECT current_setting('app.role') AS role")->role);
            });
            $this->assertNull(app(RlsContextRunner::class)->current());
        } finally {
            DB::rollBack();
        }
    }

    public function test_guard_refuses_missing_service_context_instead_of_missing_hidden_rows(): void
    {
        $this->expectException(\LogicException::class);
        app(CheckoutContractAdapter::class)->assertLegacyAllowed(1, 'P5_SOURCE');
    }
}
