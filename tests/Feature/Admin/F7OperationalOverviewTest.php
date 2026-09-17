<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Widgets\F7OperationalOverview;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class F7OperationalOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_admin_metrics_cover_all_branches_without_clinical_data(): void
    {
        $package = $this->package();
        $method = $this->paymentMethod();
        $branchA = $this->branch('A');
        $branchB = $this->branch('B');
        $this->assessmentGraph($branchA, $package, $method, 'A', 'pending', 'ready');
        $this->assessmentGraph($branchB, $package, $method, 'B', 'paid', 'locked');
        $admin = Admin::query()->create([
            'name' => 'Central Admin',
            'email' => 'central-f7@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
        ]);

        $metrics = F7OperationalOverview::metricsFor($admin);

        $this->assertSame(2, $metrics['participants']);
        $this->assertSame(1, $metrics['pendingBills']);
        $this->assertSame(1, $metrics['paidBills']);
        $this->assertSame(1, $metrics['readyEntitlements']);
        $this->assertSame(0, $metrics['proctoringRisk']);
        $this->assertSame(0, $metrics['commissionLedgerGaps']);
        $this->assertSame('Semua cabang', $metrics['scope']);
    }

    public function test_branch_admin_metrics_are_scoped_to_own_branch(): void
    {
        $package = $this->package();
        $method = $this->paymentMethod();
        $branchA = $this->branch('A');
        $branchB = $this->branch('B');
        $this->assessmentGraph($branchA, $package, $method, 'A', 'pending', 'ready');
        $this->assessmentGraph($branchB, $package, $method, 'B', 'paid', 'ready');
        $admin = Admin::query()->create([
            'branch_id' => $branchA->id,
            'name' => 'Branch Admin',
            'email' => 'branch-f7@example.test',
            'password' => 'password',
            'role' => AdminRole::BranchAdmin,
        ]);

        $metrics = F7OperationalOverview::metricsFor($admin);

        $this->assertSame(1, $metrics['participants']);
        $this->assertSame(1, $metrics['pendingBills']);
        $this->assertSame(0, $metrics['paidBills']);
        $this->assertSame(1, $metrics['readyEntitlements']);
        $this->assertSame(0, $metrics['proctoringRisk']);
        $this->assertSame(0, $metrics['commissionLedgerGaps']);
        $this->assertSame('Cabang sendiri', $metrics['scope']);
    }

    public function test_commission_ledger_gap_metric_is_scoped_for_central_and_branch_admins(): void
    {
        $branchA = $this->branch('A');
        $branchB = $this->branch('B');
        DB::table('commission_ledger_gaps')->insert([
            $this->commissionGap($branchA->id, 1),
            $this->commissionGap($branchB->id, 2),
        ]);
        $central = Admin::query()->create([
            'name' => 'Central Gap Admin',
            'email' => 'central-gap-f7@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
        ]);
        $branchAdmin = Admin::query()->create([
            'branch_id' => $branchA->id,
            'name' => 'Branch Gap Admin',
            'email' => 'branch-gap-f7@example.test',
            'password' => 'password',
            'role' => AdminRole::BranchAdmin,
        ]);

        $this->assertSame(2, F7OperationalOverview::metricsFor($central)['commissionLedgerGaps']);
        $this->assertSame(1, F7OperationalOverview::metricsFor($branchAdmin)['commissionLedgerGaps']);
    }

    private function branch(string $suffix): Branch
    {
        return Branch::query()->create([
            'code' => 'F7'.$suffix,
            'name' => 'F7 Branch '.$suffix,
            'ref_code' => 'F7-'.$suffix,
            'organization_code' => 'F7_'.$suffix,
            'organization_type' => 'EXTERNAL_LPK',
            'display_name' => 'F7 Branch '.$suffix,
            'status' => 'ACTIVE',
            'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['organization'],
            'is_default' => $suffix === 'A',
            'is_active' => true,
        ]);
    }

    private function package(): TestPackage
    {
        return TestPackage::query()->create([
            'code' => 'F7_PACKAGE',
            'name' => 'F7 Package',
            'amount' => 100_000,
            'consultation_amount' => 25_000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        $id = DB::table('payment_methods')->insertGetId([
            'code' => 'f7_manual',
            'display_name' => 'F7 Manual',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PaymentMethod::query()->findOrFail($id);
    }

    private function assessmentGraph(
        Branch $branch,
        TestPackage $package,
        PaymentMethod $method,
        string $suffix,
        string $billStatus,
        string $entitlementStatus,
    ): void {
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'link',
            'package_id' => $package->id,
            'full_name' => 'Participant '.$suffix,
            'gender' => 'male',
            'birth_date' => '2000-01-01',
            'education_level' => 'SMA',
            'intended_field' => 'UMUM',
            'phone' => '62811111111'.$suffix,
            'test_number' => 'F7-'.$suffix,
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $branch->id,
            'client_id' => 'F7_CLIENT_'.$suffix,
            'credential_reference' => 'f7-'.$suffix,
            'result_delivery_mode' => 'PORTAL_ONLY',
            'enabled' => true,
        ]);
        $attemptPublicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $attemptPublicId,
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $package->id,
            'origin' => 'INTEGRATED',
            'intended_field_snapshot' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'assessment_case_id' => $case,
            'integration_client_id' => $client->id,
            'organization_id' => $branch->id,
            'participant_id' => $participant->id,
            'package_id' => $package->id,
            'assessment_attempt_id' => $attemptPublicId,
            'source_system' => 'PORTAL_ONLY',
            'external_candidate_id' => 'F7-CAND-'.$suffix,
            'funding_mode' => 'SPONSORED',
            'assessment_status' => 'READY',
            'result_version' => 0,
            'idempotency_key' => 'f7-'.$suffix,
            'request_hash' => hash('sha256', 'request-'.$suffix),
            'logical_assessment_key' => hash('sha256', 'logical-'.$suffix),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $charge = DB::table('assessment_charges')->insertGetId([
            'assessment_participant_id' => $attempt,
            'organization_id' => $branch->id,
            'participant_id' => $participant->id,
            'package_id' => $package->id,
            'payer_type' => 'organization',
            'base_amount' => 100_000,
            'consultation_amount' => 0,
            'consultation_requested' => false,
            'amount' => 100_000,
            'currency' => 'IDR',
            'price_snapshot' => json_encode(['packageName' => 'F7 Package'], JSON_THROW_ON_ERROR),
            'policy_snapshot' => json_encode(['payerType' => 'organization'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assessment_bills')->insert([
            'organization_id' => $branch->id,
            'payer_type' => 'organization',
            'payer_participant_id' => null,
            'public_reference' => 'AB_'.$this->ulidReference(),
            'amount' => 100_000,
            'currency' => 'IDR',
            'item_count' => 1,
            'selection_hash' => hash('sha256', 'selection-'.$suffix),
            'idempotency_key' => 'f7-bill-'.$suffix,
            'request_hash' => hash('sha256', 'bill-request-'.$suffix),
            'status' => $billStatus,
            'payment_method_id' => $method->id,
            'paid_at' => $billStatus === 'paid' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assessment_entitlements')->insert([
            'charge_id' => $charge,
            'assessment_participant_id' => $attempt,
            'organization_id' => $branch->id,
            'participant_id' => $participant->id,
            'test_type' => 'ist',
            'status' => $entitlementStatus,
            'ready_at' => $entitlementStatus === 'ready' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ulidReference(): string
    {
        return (string) Str::ulid();
    }

    /** @return array<string, mixed> */
    private function commissionGap(int $branchId, int $sourceId): array
    {
        return [
            'branch_id' => $branchId,
            'source_type' => 'direct_order',
            'source_id' => $sourceId,
            'reason_code' => 'fee_rule_missing',
            'paid_at' => now(),
            'currency' => 'IDR',
            'amount' => 0,
            'context' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
