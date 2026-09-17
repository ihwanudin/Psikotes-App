<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\PaymentMethod;
use App\Security\RlsContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class SetPaymentMethodActivation
{
    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    public function handle(Admin $admin, int $paymentMethodId, bool $active): PaymentMethod
    {
        if (! $admin->canPerform(AdminAbility::ManagePaymentMethods)) {
            throw new AuthorizationException('Payment method management is not allowed.');
        }

        return $this->runner->runAsService(
            function () use ($admin, $paymentMethodId, $active): PaymentMethod {
                $method = PaymentMethod::query()->lockForUpdate()->findOrFail($paymentMethodId);

                if ($method->is_active === $active) {
                    return $method;
                }

                $previous = $method->is_active;
                $method->is_active = $active;
                $method->save();

                $occurredAt = now()->utc()->toImmutable();
                DB::table('audit_logs')->insert([
                    'branch_id' => null,
                    'actor_type' => 'admin',
                    'actor_id' => (string) $admin->id,
                    'action' => 'payment_method.activation_changed',
                    'subject_type' => PaymentMethod::class,
                    'subject_id' => (string) $method->id,
                    'context' => json_encode([
                        'code' => $method->code,
                        'from' => $previous,
                        'to' => $active,
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurredAt,
                    'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $occurredAt),
                ]);

                return $method;
            },
        );
    }
}
