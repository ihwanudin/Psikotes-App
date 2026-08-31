<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\UpdateFundingPolicy;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FundingPolicyManagementTest extends TestCase
{
    private Admin $admin;

    private Branch $organization;

    private IntegrationSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->organization = Branch::create([
                'code' => 'FUNDING', 'ref_code' => 'FUNDING', 'name' => 'Synthetic funding',
                'organization_code' => 'FUNDING', 'display_name' => 'Synthetic funding',
                'allowed_payer_types' => ['self'],
            ]);
            $client = IntegrationClient::create([
                'organization_id' => $this->organization->id, 'client_id' => 'synthetic-funding',
                'credential_reference' => 'synthetic-not-a-secret',
            ]);
            $this->source = IntegrationSource::create([
                'integration_client_id' => $client->id, 'source_system' => 'SYNTHETIC',
                'allowed_assessment_packages' => [], 'allowed_funding_modes' => [],
                'allowed_payer_types' => ['self'],
            ]);
            $this->admin = Admin::create([
                'name' => 'Synthetic admin', 'email' => 'funding@example.test',
                'password' => 'synthetic-test-password', 'role' => AdminRole::SuperAdmin,
            ]);
        });
        DB::select("SELECT set_config('app.role', '', true)");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_super_admin_write_and_audit_work_under_forced_rls(): void
    {
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
        app(RlsContextRunner::class)->run(new RlsContext('super_admin'), function (): void {
            $action = app(UpdateFundingPolicy::class);
            $branch = $action->forOrganization($this->admin, $this->organization->id, [
                'allowed_payer_types' => ['self', 'organization'],
            ]);
            $source = $action->forSource($this->admin, $branch->id, $this->source->id, [
                'allowed_payer_types' => ['organization'], 'locked_payer_type' => 'organization',
            ]);
            $this->assertSame(['self', 'organization'], $branch->refresh()->allowed_payer_types);
            $this->assertSame('organization', $source->refresh()->locked_payer_type);
            $this->assertSame(2, DB::table('audit_logs')->where('action', 'funding_policy.updated')->count());
            $this->assertSame('super_admin', DB::selectOne("SELECT current_setting('app.role') AS role")->role);
            $this->assertSame('super_admin', app(RlsContextRunner::class)->current()?->role);
        });
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    public function test_revoked_actor_is_reloaded_before_service_write(): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('admins')->where('id', $this->admin->id)->update([
            'role' => 'staff', 'branch_id' => $this->organization->id, 'can_verify_payments' => true,
        ]));
        app(RlsContextRunner::class)->run(new RlsContext('staff', $this->organization->id), function (): void {
            try {
                app(UpdateFundingPolicy::class)->forOrganization($this->admin, $this->organization->id, ['allowed_payer_types' => ['organization']]);
                $this->fail('Revoked SuperAdmin was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(['self'], $this->organization->refresh()->allowed_payer_types);
                $this->assertSame('staff', DB::selectOne("SELECT current_setting('app.role') AS role")->role);
            }
        });
    }

    public function test_audit_failure_rolls_back_source_within_caught_outer_transaction(): void
    {
        $failAudit = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failAudit): void {
            if ($failAudit && str_starts_with($query, 'insert into "audit_logs"')) {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(RlsContextRunner::class)->run(new RlsContext('super_admin'), function (): void {
                try {
                    app(UpdateFundingPolicy::class)->forSource($this->admin, $this->organization->id, $this->source->id, [
                        'allowed_payer_types' => ['organization'], 'locked_payer_type' => 'organization',
                    ]);
                    $this->fail('Expected audit failure.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Synthetic audit failure', $exception->getMessage());
                }
                $this->assertSame(['self'], $this->source->refresh()->allowed_payer_types);
                $this->assertNull($this->source->locked_payer_type);
                $this->assertSame(0, DB::table('audit_logs')->where('action', 'funding_policy.updated')->count());
                $this->assertSame('super_admin', DB::selectOne("SELECT current_setting('app.role') AS role")->role);
            });
        } finally {
            $failAudit = false;
        }
    }
}
