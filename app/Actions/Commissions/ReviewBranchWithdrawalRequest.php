<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\WithdrawalRequest;
use App\Security\RlsContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ReviewBranchWithdrawalRequest
{
    public function __construct(private RlsContextRunner $runner) {}

    public function approve(Admin $actor, int $requestId): WithdrawalRequest
    {
        return $this->transition($actor, $requestId, 'approve');
    }

    public function reject(Admin $actor, int $requestId, string $reason): WithdrawalRequest
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Alasan penolakan wajib diisi dan maksimal 500 karakter.',
            ]);
        }

        return $this->transition($actor, $requestId, 'reject', $reason);
    }

    public function markPaid(Admin $actor, int $requestId, ?string $payoutReference): WithdrawalRequest
    {
        $reference = trim((string) $payoutReference);

        return $this->transition($actor, $requestId, 'mark_paid', $reference === '' ? null : $reference);
    }

    private function transition(Admin $actor, int $requestId, string $decision, ?string $value = null): WithdrawalRequest
    {
        $this->authorize($actor);

        return $this->runner->runAsService(fn (): WithdrawalRequest => DB::transaction(function () use ($actor, $requestId, $decision, $value): WithdrawalRequest {
            $admin = Admin::query()->lockForUpdate()->find($actor->id);
            $this->authorize($admin);

            $request = WithdrawalRequest::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($requestId);
            $now = now();
            $activeItemIds = $request->items->where('is_active', true)->pluck('id')->all();
            $commissionEntryIds = $request->items->where('is_active', true)->pluck('commission_entry_id')->all();

            if ($decision === 'approve') {
                if ($request->status !== 'submitted') {
                    throw ValidationException::withMessages(['status' => 'Hanya request submitted yang bisa disetujui.']);
                }
                $request->forceFill([
                    'status' => 'approved',
                    'approved_amount' => $request->requested_amount,
                    'approved_by_admin_id' => $admin->id,
                    'approved_at' => $now,
                ])->save();
            } elseif ($decision === 'reject') {
                if (! in_array($request->status, ['submitted', 'approved'], true)) {
                    throw ValidationException::withMessages(['status' => 'Request ini tidak bisa ditolak.']);
                }
                $request->forceFill([
                    'status' => 'rejected',
                    'rejection_reason' => $value,
                    'rejected_at' => $now,
                ])->save();
                DB::table('withdrawal_request_items')
                    ->whereIn('id', $activeItemIds)
                    ->update(['is_active' => false, 'updated_at' => $now]);
                DB::table('commission_entries')
                    ->whereIn('id', $commissionEntryIds)
                    ->where('status', 'withdrawal_pending')
                    ->update(['status' => 'accrued', 'updated_at' => $now]);
            } else {
                if ($request->status !== 'approved') {
                    throw ValidationException::withMessages(['status' => 'Hanya request approved yang bisa ditandai dibayar.']);
                }
                $request->forceFill([
                    'status' => 'paid',
                    'paid_by_admin_id' => $admin->id,
                    'paid_at' => $now,
                    'payout_reference' => $value,
                ])->save();
                DB::table('commission_entries')
                    ->whereIn('id', $commissionEntryIds)
                    ->where('status', 'withdrawal_pending')
                    ->update(['status' => 'withdrawn', 'updated_at' => $now]);
            }

            return WithdrawalRequest::query()->with(['branch', 'items'])->findOrFail($request->id);
        }));
    }

    private function authorize(?Admin $admin): void
    {
        if ($admin === null || $admin->trashed() || $admin->role !== AdminRole::SuperAdmin) {
            throw new AuthorizationException('Review pencairan hanya boleh dilakukan admin pusat aktif.');
        }
    }
}
