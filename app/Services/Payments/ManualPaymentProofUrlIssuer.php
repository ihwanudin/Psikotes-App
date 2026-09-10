<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\Admin;
use App\Models\Order;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use LogicException;

final readonly class ManualPaymentProofUrlIssuer
{
    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    public function issue(Admin $admin, string $publicId): string
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(
            (int) config('payments.manual_proof_temporary_url_minutes', 15),
        );
        $load = function () use ($admin, $publicId, $expiresAt): array {
            $order = Order::query()
                ->with(['participant', 'paymentMethod'])
                ->where('public_id', $publicId)
                ->firstOrFail();

            Gate::forUser($admin)->authorize('verifyPayment', $order);
            abort_unless(
                $order->payment_method_id !== null
                    && $order->paymentMethod->code === 'manual_transfer'
                    && is_string($order->proof_object_key),
                404,
            );

            $disk = (string) config('payments.manual_proof_disk', 'payment-proofs');
            $storedDisk = $order->metadata['manual_payment_proof']['disk'] ?? null;
            abort_unless($storedDisk === $disk, 404);

            return [
                $order,
                Storage::disk($disk)->temporaryUrl($order->proof_object_key, $expiresAt),
            ];
        };
        $adminContext = $admin->rlsContext();
        $currentContext = $this->runner->current();

        if ($currentContext === null) {
            [$order, $url] = $this->runner->run($adminContext, $load);
        } elseif ($currentContext == $adminContext) {
            [$order, $url] = $load();
        } else {
            throw new LogicException('Manual proof access requires the authenticated admin RLS context.');
        }

        $occurredAt = CarbonImmutable::now()->utc();
        $this->runner->runAsService(function () use ($admin, $order, $expiresAt, $occurredAt): void {
            DB::table('audit_logs')->insert([
                'branch_id' => $order->participant->branch_id,
                'actor_type' => 'admin',
                'actor_id' => (string) $admin->id,
                'action' => 'manual_payment_proof.temporary_url_issued',
                'subject_type' => Order::class,
                'subject_id' => $order->public_id,
                'context' => json_encode([
                    'url_expires_at' => $expiresAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $occurredAt),
            ]);
        });

        return $url;
    }
}
