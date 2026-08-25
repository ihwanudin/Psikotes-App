<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\PaymentMethod;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class SetPaymentMethodActivation
{
    public function __construct(private RlsContextRunner $runner) {}

    public function handle(Admin $admin, int $paymentMethodId, bool $active): PaymentMethod
    {
        if (! $admin->canPerform(AdminAbility::ManagePaymentMethods)) {
            throw new AuthorizationException('Payment method management is not allowed.');
        }

        return $this->runner->run(
            new RlsContext('service'),
            function () use ($admin, $paymentMethodId, $active): PaymentMethod {
                $method = PaymentMethod::query()->lockForUpdate()->findOrFail($paymentMethodId);

                if ($method->is_active === $active) {
                    return $method;
                }

                $previous = $method->is_active;
                $method->is_active = $active;
                $method->save();

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
                    'occurred_at' => now(),
                    'expires_at' => now()->addYears(2),
                ]);

                return $method;
            },
        );
    }
}
