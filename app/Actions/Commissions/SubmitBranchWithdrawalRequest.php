<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\WithdrawalRequest;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SubmitBranchWithdrawalRequest
{
    public function __construct(private RlsContextRunner $runner) {}

    public function execute(Admin $actor, string $periodMonth): WithdrawalRequest
    {
        $period = $this->normalizePeriod($periodMonth);
        $this->authorize($actor);

        return $this->runner->runAsService(fn (): WithdrawalRequest => DB::transaction(function () use ($actor, $period): WithdrawalRequest {
            $admin = Admin::query()->lockForUpdate()->find($actor->id);
            $this->authorize($admin);
            $branchId = (int) $admin->branch_id;

            $activeRequest = WithdrawalRequest::query()
                ->where('branch_id', $branchId)
                ->whereDate('period_month', $period->toDateString())
                ->whereIn('status', ['draft', 'submitted', 'approved', 'paid'])
                ->lockForUpdate()
                ->first();

            if ($activeRequest !== null) {
                throw ValidationException::withMessages([
                    'period_month' => 'Sudah ada request pencairan aktif untuk periode ini.',
                ]);
            }

            $entries = DB::table('commission_entries')
                ->where('branch_id', $branchId)
                ->whereDate('period_month', $period->toDateString())
                ->where('status', 'accrued')
                ->whereNull('voided_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'commission_amount', 'currency']);

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages([
                    'period_month' => 'Belum ada komisi accrued yang bisa diajukan pada periode ini.',
                ]);
            }

            $total = (int) $entries->sum('commission_amount');
            $now = now();
            $requestId = DB::table('withdrawal_requests')->insertGetId([
                'branch_id' => $branchId,
                'period_month' => $period->toDateString(),
                'public_reference' => 'WR_'.((string) Str::ulid()),
                'status' => 'submitted',
                'requested_amount' => $total,
                'currency' => 'IDR',
                'requested_by_admin_id' => $admin->id,
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($entries as $entry) {
                DB::table('withdrawal_request_items')->insert([
                    'branch_id' => $branchId,
                    'withdrawal_request_id' => $requestId,
                    'commission_entry_id' => (int) $entry->id,
                    'amount_snapshot' => (int) $entry->commission_amount,
                    'currency' => (string) $entry->currency,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('commission_entries')
                ->whereIn('id', $entries->pluck('id')->all())
                ->update(['status' => 'withdrawal_pending', 'updated_at' => $now]);

            return WithdrawalRequest::query()->with(['branch', 'items'])->findOrFail($requestId);
        }));
    }

    private function authorize(?Admin $admin): void
    {
        if ($admin === null || $admin->trashed() || $admin->role !== AdminRole::BranchAdmin || $admin->branch_id === null) {
            throw new AuthorizationException('Request pencairan hanya bisa diajukan admin cabang aktif.');
        }
    }

    private function normalizePeriod(string $periodMonth): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($periodMonth, 'Asia/Jakarta')->startOfMonth();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'period_month' => 'Periode pencairan tidak valid.',
            ]);
        }
    }
}
