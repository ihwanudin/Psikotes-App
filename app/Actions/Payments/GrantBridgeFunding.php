<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Notifications\EnqueueParticipantActivation;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\Admin;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Security\RlsContextRunner;
use App\Services\Payments\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Item 18 (dana talang / bridge funding): the holding company covers a
 * DIRECT_PUBLIC participant's assessment, an admin approves on management's
 * instruction. See tasks/handoffs/f2/legacy-entitlement-provisioning-closure-plan.md.
 */
final readonly class GrantBridgeFunding
{
    public function __construct(
        private RlsContextRunner $runner,
        private OrderStateMachine $stateMachine,
        private EnqueueParticipantActivation $enqueueActivation,
        private RetentionPolicy $retention,
    ) {}

    public function handle(Admin $admin, int $orderId, string $managementReference): Order
    {
        $managementReference = trim($managementReference);

        if ($managementReference === '' || mb_strlen($managementReference) > 500) {
            throw ValidationException::withMessages([
                'management_reference' => 'Referensi instruksi manajemen wajib diisi dan maksimal 500 karakter.',
            ]);
        }

        return $this->runner->runAsService(function () use ($admin, $orderId, $managementReference): Order {
            // lockForUpdate() is the primary concurrency defense: two admins
            // approving the same order at once serialize here, so the
            // second one observes the first one's already-changed status.
            // The partial unique index on bridge_funding_grants (order_id
            // WHERE status IN ('invoiced','collected')) is the second,
            // independent safety net for if this lock is ever bypassed.
            $order = Order::query()
                ->with('participant')
                ->lockForUpdate()
                ->findOrFail($orderId);

            Gate::forUser($admin)->authorize('approveBridgeFunding', $order);

            $maxAmount = config('bridge_funding.max_amount');

            if ($maxAmount !== null && $order->amount > $maxAmount) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal talangan melebihi batas yang dikonfigurasi.',
                ]);
            }

            $transition = $this->stateMachine->applyBridgeFunding($order->status);

            if (! $transition->changed) {
                // Idempotent replay: this order is already bridge-funded.
                return $order;
            }

            $approvedAt = now()->utc()->toImmutable();

            // insertOrIgnore(), not insert(): on PostgreSQL a unique
            // violation would abort the whole transaction (25P02), and this
            // service-context closure's own cleanup on the way out would
            // then fail too -- the exact trap documented in
            // tasks/handoffs/f5/report-signing-conflict-500.md. Returning
            // zero affected rows instead of throwing sidesteps that
            // entirely; see ReportSigningService::sign() for the same
            // pattern already established in this codebase.
            $inserted = DB::table('bridge_funding_grants')->insertOrIgnore([
                'order_id' => $order->id,
                'participant_id' => $order->participant_id,
                'branch_id' => $order->participant->branch_id,
                'amount' => $order->amount,
                'currency' => $order->currency,
                'management_reference' => $managementReference,
                'approved_by_admin_id' => $admin->id,
                'approved_at' => $approvedAt,
                'status' => 'invoiced',
                'created_at' => $approvedAt,
                'updated_at' => $approvedAt,
            ]);

            if ($inserted === 0) {
                // Should not happen with the row lock above in place --
                // second, independent safety net for if it's ever bypassed.
                throw ValidationException::withMessages([
                    'order' => 'Order ini sudah memiliki talangan aktif.',
                ]);
            }

            // Lazily created, not seeded by a migration: many existing
            // tests assert on the payment_methods table's exact contents
            // right after a bare `migrate` (no seed), so a migration-time
            // insert here would be a real regression (confirmed: it broke
            // CheckoutSummaryLifecycleTest's blanket UPDATE ... SET code
            // assuming a single row).
            // insertOrIgnore(), not firstOrCreate(): two orders being
            // bridge-funded for the very first time ever could otherwise
            // race to create this row (a narrow but real case of the same
            // insert()-throws trap noted above). Also why code/display_name
            // go through a raw insert rather than Eloquent create() --
            // they're deliberately not mass-assignable on PaymentMethod
            // (payment methods are seed-only data everywhere else in this
            // codebase).
            DB::table('payment_methods')->insertOrIgnore([
                'code' => 'bridge_funding',
                'display_name' => 'Dana Talang',
                'is_active' => false,
                'created_at' => $approvedAt,
                'updated_at' => $approvedAt,
            ]);
            $bridgeFundingMethodId = PaymentMethod::query()->where('code', 'bridge_funding')->value('id');

            $order->status = $transition->status;
            $order->payment_method_id = $bridgeFundingMethodId;
            $order->save();

            if ($transition->unlocksEntitlements) {
                Entitlement::query()
                    ->where('order_id', $order->id)
                    ->where('status', 'locked')
                    ->update([
                        'status' => 'ready',
                        'ready_at' => $approvedAt,
                        'updated_at' => now(),
                    ]);

                $this->enqueueActivation->handle($order);
            }

            DB::table('audit_logs')->insert([
                'branch_id' => $order->participant->branch_id,
                'actor_type' => 'admin',
                'actor_id' => (string) $admin->id,
                'action' => 'order.bridge_funding_granted',
                'subject_type' => Order::class,
                'subject_id' => $order->public_id,
                'context' => json_encode([
                    'amount' => $order->amount,
                    'currency' => $order->currency,
                    'management_reference' => $managementReference,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $approvedAt,
                'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $approvedAt),
            ]);

            return $order;
        });
    }
}
