<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\CommissionEntries\Pages\ListCommissionEntries;
use App\Filament\Resources\WithdrawalRequests\Pages\ListWithdrawalRequests;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CommissionEntry;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\DirectPublicOrderFixture;
use Tests\TestCase;

final class WithdrawalRequestFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_branch_admin_can_submit_but_staff_only_sees_read_only_resources(): void
    {
        ['branch' => $branch, 'entry' => $entry] = $this->seedCommissionEntry(20_000);
        ['entry' => $foreignEntry] = $this->seedCommissionEntry(30_000);
        $branchAdmin = $this->admin(AdminRole::BranchAdmin, $branch);
        $this->actingAs($branchAdmin, 'admin');

        $this->get('/admin/commission-entries')->assertOk();
        $this->get('/admin/withdrawal-requests')->assertOk();

        Livewire::test(ListCommissionEntries::class)
            ->assertCanSeeTableRecords([CommissionEntry::query()->findOrFail($entry)])
            ->assertCanNotSeeTableRecords([CommissionEntry::query()->findOrFail($foreignEntry)]);

        Livewire::test(ListWithdrawalRequests::class)
            ->assertActionVisible(TestAction::make('submitWithdrawal'))
            ->callAction(TestAction::make('submitWithdrawal'), data: ['period_month' => '2026-09-01'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('withdrawal_requests', [
            'branch_id' => $branch->id,
            'status' => 'submitted',
            'requested_amount' => 20_000,
        ]);

        $staff = $this->admin(AdminRole::Staff, $branch);
        $this->actingAs($staff, 'admin');
        Livewire::test(ListWithdrawalRequests::class)
            ->assertActionHidden(TestAction::make('submitWithdrawal'));
    }

    public function test_super_admin_can_approve_reject_and_mark_paid_but_branch_admin_cannot_review(): void
    {
        ['branch' => $branch] = $this->seedCommissionEntry(15_000);
        $branchAdmin = $this->admin(AdminRole::BranchAdmin, $branch);
        $this->actingAs($branchAdmin, 'admin');
        Livewire::test(ListWithdrawalRequests::class)
            ->callAction(TestAction::make('submitWithdrawal'), data: ['period_month' => '2026-09-01']);
        $request = WithdrawalRequest::query()->firstOrFail();

        Livewire::test(ListWithdrawalRequests::class)
            ->assertActionHidden(TestAction::make('approve')->table($request));

        $superAdmin = $this->admin(AdminRole::SuperAdmin);
        $this->actingAs($superAdmin, 'admin');

        Livewire::test(ListWithdrawalRequests::class)
            ->assertActionVisible(TestAction::make('approve')->table($request))
            ->callAction(TestAction::make('approve')->table($request))
            ->assertNotified();

        $request->refresh();
        Livewire::test(ListWithdrawalRequests::class)
            ->assertActionVisible(TestAction::make('markPaid')->table($request))
            ->callAction(TestAction::make('markPaid')->table($request), data: ['payout_reference' => 'PAYOUT-1'])
            ->assertNotified();

        $this->assertSame('paid', $request->fresh()->status);
        $this->assertDatabaseHas('commission_entries', ['status' => 'withdrawn']);
    }

    public function test_psychologist_cannot_access_commission_or_withdrawal_resources(): void
    {
        $psychologist = $this->admin(AdminRole::Psychologist);

        $this->actingAs($psychologist, 'admin')
            ->get('/admin/commission-entries')
            ->assertForbidden();
        $this->get('/admin/withdrawal-requests')->assertForbidden();
    }

    private function admin(AdminRole $role, ?Branch $branch = null): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branch?->id,
            'name' => 'Admin '.$role->value.' '.Str::random(4),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => $role,
            'has_email_authentication' => true,
        ]);
    }

    /**
     * @return array{branch: Branch, entry: int}
     */
    private function seedCommissionEntry(int $amount): array
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: 'fw-'.Str::lower(Str::random(6)),
            amount: 100_000,
            gatewayReference: 'FW-'.Str::upper(Str::random(8)),
        );
        $branch = $fixture['branch'];
        $participant = $fixture['participant'];
        $order = $fixture['order'];
        $order->forceFill([
            'status' => 'paid',
            'paid_at' => CarbonImmutable::parse('2026-09-17 10:00:00', 'Asia/Jakarta')->utc(),
        ])->save();
        $ruleAdmin = Admin::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Rule Admin',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::BranchAdmin,
        ]);
        $rule = DB::table('branch_fee_rules')->insertGetId([
            'branch_id' => $branch->id,
            'rate_basis' => 'base_amount',
            'rate_type' => 'percentage',
            'percentage_bps' => 1000,
            'currency' => 'IDR',
            'rounding_mode' => 'floor',
            'effective_from' => '2026-09-01 00:00:00',
            'created_by_admin_id' => $ruleAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entry = DB::table('commission_entries')->insertGetId([
            'branch_id' => $branch->id,
            'participant_id' => $participant->id,
            'order_id' => $order->id,
            'source_type' => 'direct_order',
            'source_id' => $order->id,
            'period_month' => '2026-09-01',
            'paid_at' => '2026-09-17 03:00:00',
            'fee_rule_id' => $rule,
            'rate_basis' => 'base_amount',
            'rate_basis_amount' => 100_000,
            'gross_amount' => 100_000,
            'commission_amount' => $amount,
            'currency' => 'IDR',
            'calculation_snapshot' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            'status' => 'accrued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['branch' => $branch, 'entry' => $entry];
    }
}
