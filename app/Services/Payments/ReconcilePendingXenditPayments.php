<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\PaymentReconciliationResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentWebhookOutcome;
use App\Models\Order;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final readonly class ReconcilePendingXenditPayments
{
    public function __construct(
        private RlsContextRunner $runner,
        private PaymentProvider $provider,
        private PaymentWebhookProcessor $processor,
    ) {}

    public function handle(int $limit = 100): PaymentReconciliationResult
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Reconciliation limit must be between 1 and 500.');
        }

        $references = $this->runner->run(
            new RlsContext('service'),
            fn (): array => Order::query()
                ->where('status', OrderStatus::Pending)
                ->whereNotNull('gateway_ref')
                ->whereHas('paymentMethod', fn ($query) => $query->where('code', 'xendit'))
                ->orderBy('id')
                ->limit($limit)
                ->pluck('gateway_ref')
                ->filter(fn (mixed $reference): bool => is_string($reference))
                ->values()
                ->all(),
        );
        $checked = 0;
        $applied = 0;
        $failed = 0;

        foreach ($references as $reference) {
            $checked++;

            try {
                $result = $this->processor->process('xendit', $this->provider->checkStatus($reference));

                if ($result->outcome === PaymentWebhookOutcome::Applied) {
                    $applied++;
                } elseif (in_array($result->outcome, [PaymentWebhookOutcome::Rejected, PaymentWebhookOutcome::Conflict], true)) {
                    $failed++;
                }
            } catch (PaymentProviderException) {
                $failed++;
                Log::warning('xendit_status_reconciliation_failed', [
                    'provider_reference' => $reference,
                ]);
            }
        }

        Log::info('xendit_status_reconciliation_completed', [
            'checked' => $checked,
            'applied' => $applied,
            'failed' => $failed,
        ]);

        return new PaymentReconciliationResult($checked, $applied, $failed);
    }
}
