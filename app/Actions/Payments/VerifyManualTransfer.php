<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\Admin;
use App\Models\Entitlement;
use App\Models\Order;
use App\Security\RlsContextRunner;
use App\Services\Payments\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class VerifyManualTransfer
{
    public function __construct(
        private RlsContextRunner $runner,
        private OrderStateMachine $stateMachine,
    ) {}

    public function approve(Admin $admin, int $orderId): Order
    {
        return $this->handle($admin, $orderId, approved: true, rejectionReason: null);
    }

    public function reject(Admin $admin, int $orderId, string $reason): Order
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Alasan penolakan wajib diisi dan maksimal 500 karakter.',
            ]);
        }

        return $this->handle($admin, $orderId, approved: false, rejectionReason: $reason);
    }

    private function handle(
        Admin $admin,
        int $orderId,
        bool $approved,
        ?string $rejectionReason,
    ): Order {
        return $this->runner->runAsService(function () use (
            $admin,
            $orderId,
            $approved,
            $rejectionReason,
        ): Order {
            $order = Order::query()
                ->with(['participant', 'paymentMethod'])
                ->lockForUpdate()
                ->findOrFail($orderId);

            Gate::forUser($admin)->authorize('verifyPayment', $order);

            if ($order->paymentMethod->code !== 'manual_transfer'
                || ! is_string($order->proof_object_key)) {
                throw ValidationException::withMessages([
                    'payment_proof' => 'Bukti transfer manual belum tersedia.',
                ]);
            }

            $transition = $this->stateMachine->applyManualReview($order->status, $approved);

            if (! $transition->changed) {
                return $order;
            }

            $reviewedAt = now()->utc();
            $order->status = $transition->status;
            $order->verified_at = $reviewedAt;
            $order->verified_by_admin_id = $admin->id;
            $order->rejection_reason = $approved ? null : $rejectionReason;

            if ($approved) {
                $order->paid_at = $reviewedAt;
            }

            $order->save();

            if ($transition->unlocksEntitlements) {
                Entitlement::query()
                    ->where('order_id', $order->id)
                    ->where('status', 'locked')
                    ->update([
                        'status' => 'ready',
                        'ready_at' => $reviewedAt,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('audit_logs')->insert([
                'branch_id' => $order->participant->branch_id,
                'actor_type' => 'admin',
                'actor_id' => (string) $admin->id,
                'action' => $approved ? 'manual_transfer.approved' : 'manual_transfer.rejected',
                'subject_type' => Order::class,
                'subject_id' => $order->public_id,
                'context' => json_encode([
                    'amount' => $order->amount,
                    'currency' => $order->currency,
                    'rejection_reason_present' => ! $approved,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $reviewedAt,
                'expires_at' => $reviewedAt->addYears(2),
            ]);

            return $order;
        });
    }
}
