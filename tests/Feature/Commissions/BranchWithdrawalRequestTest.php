<?php

declare(strict_types=1);

namespace Tests\Feature\Commissions;

use App\Actions\Commissions\ReviewBranchWithdrawalRequest;
use App\Actions\Commissions\SubmitBranchWithdrawalRequest;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\DirectPublicOrderFixture;
use Tests\TestCase;

final class BranchWithdrawalRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_withdrawal_can_be_resubmitted_without_losing_historical_items(): void
    {
        ['branch' => $branch, 'entry' => $entry] = $this->commissionEntry(amount: 12_500);
        $branchAdmin = $this->admin(AdminRole::BranchAdmin, $branch);
        $centralAdmin = $this->admin(AdminRole::SuperAdmin);
        $submit = app(SubmitBranchWithdrawalRequest::class);
        $review = app(ReviewBranchWithdrawalRequest::class);

        $first = $submit->execute($branchAdmin, '2026-09-01');
        $review->reject($centralAdmin, $first->id, 'Data rekening perlu diperbaiki.');
        $second = $submit->execute($branchAdmin, '2026-09-01');

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseHas('withdrawal_requests', [
            'id' => $first->id,
            'status' => 'rejected',
            'rejection_reason' => 'Data rekening perlu diperbaiki.',
        ]);
        $this->assertDatabaseHas('withdrawal_requests', [
            'id' => $second->id,
            'status' => 'submitted',
            'requested_amount' => 12_500,
        ]);
        $this->assertDatabaseHas('withdrawal_request_items', [
            'withdrawal_request_id' => $first->id,
            'commission_entry_id' => $entry,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('withdrawal_request_items', [
            'withdrawal_request_id' => $second->id,
            'commission_entry_id' => $entry,
            'is_active' => true,
        ]);
        $this->assertSame(2, DB::table('withdrawal_request_items')->where('commission_entry_id', $entry)->count());
        $this->assertDatabaseHas('commission_entries', ['id' => $entry, 'status' => 'withdrawal_pending']);
    }

    public function test_submit_fails_closed_when_active_request_exists_or_no_entries_exist(): void
    {
        ['branch' => $branch] = $this->commissionEntry(amount: 10_000);
        $branchAdmin = $this->admin(AdminRole::BranchAdmin, $branch);
        $submit = app(SubmitBranchWithdrawalRequest::class);

        $submit->execute($branchAdmin, '2026-09-01');

        $this->expectException(ValidationException::class);
        $submit->execute($branchAdmin, '2026-09-01');
    }

    public function test_only_branch_admin_can_submit_and_only_super_admin_can_review(): void
    {
        ['branch' => $branch, 'entry' => $entry] = $this->commissionEntry(amount: 10_000);
        $staff = $this->admin(AdminRole::Staff, $branch);
        $branchAdmin = $this->admin(AdminRole::BranchAdmin, $branch);
        $submit = app(SubmitBranchWithdrawalRequest::class);

        try {
            $submit->execute($staff, '2026-09-01');
            $this->fail('Staff must not submit withdrawal requests.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('commission_entries', ['id' => $entry, 'status' => 'accrued']);
        }

        $request = $submit->execute($branchAdmin, '2026-09-01');

        $this->expectException(AuthorizationException::class);
        app(ReviewBranchWithdrawalRequest::class)->approve($branchAdmin, $request->id);
    }

    private function admin(AdminRole $role, ?Branch $branch = null): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branch?->id,
            'name' => 'Admin '.$role->value.' '.Str::random(4),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    /**
     * @return array{branch: Branch, entry: int}
     */
    private function commissionEntry(int $amount): array
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: 'wd-'.Str::lower(Str::random(6)),
            amount: 100_000,
            gatewayReference: 'WD-'.Str::upper(Str::random(8)),
        );
        $branch = $fixture['branch'];
        $participant = $fixture['participant'];
        $order = $fixture['order'];
        $order->forceFill([
            'status' => 'paid',
            'paid_at' => CarbonImmutable::parse('2026-09-17 10:00:00', 'Asia/Jakarta')->utc(),
        ])->save();
        $ruleAdmin = $this->admin(AdminRole::BranchAdmin, $branch);
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
