<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\AssessmentBillStatusReconciliationResult;
use App\Enums\PaymentWebhookOutcome;
use App\Models\AssessmentBill;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/** Internal bounded status checks for assessment bills; deliberately has no operational wiring. */
final readonly class ReconcilePendingAssessmentBills
{
    private const int MAX_BOUND = 500;

    public function __construct(
        private RlsContextRunner $contexts,
        private PaymentProvider $provider,
        private PaymentWebhookProcessor $processor,
    ) {}

    public function handle(int $limit = 100, int $scan = 500): AssessmentBillStatusReconciliationResult
    {
        if ($limit < 1 || $limit > self::MAX_BOUND || $scan < 1 || $scan > self::MAX_BOUND || $limit > $scan) {
            throw new InvalidArgumentException('Assessment bill reconciliation bounds are invalid.');
        }
        $this->assertOutsideAuthorityBoundary();

        $candidates = $this->contexts->run(
            new RlsContext('service'),
            fn (): array => AssessmentBill::query()
                ->select(['id', 'gateway_ref'])
                ->where('status', 'pending')
                ->whereNotNull('gateway_ref')
                ->whereHas('paymentMethod', fn ($query) => $query->where('code', 'xendit'))
                ->orderBy('id')
                ->limit($scan)
                ->get()
                ->map(static fn (AssessmentBill $bill): array => [
                    'id' => $bill->id,
                    'providerReference' => $bill->gateway_ref,
                ])
                ->all(),
        );
        $this->assertOutsideAuthorityBoundary();

        $checked = 0;
        $applied = 0;
        $ignored = 0;
        $failed = 0;

        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $checked++;
            $this->assertOutsideAuthorityBoundary();

            try {
                $result = $this->processor->process(
                    'xendit',
                    $this->provider->checkStatus($candidate['providerReference']),
                );
            } catch (PaymentProviderException) {
                $failed++;
                Log::warning('assessment_bill_status_reconciliation_failed');

                continue;
            }

            match ($result->outcome) {
                PaymentWebhookOutcome::Applied => $applied++,
                PaymentWebhookOutcome::Duplicate, PaymentWebhookOutcome::Ignored => $ignored++,
                PaymentWebhookOutcome::Rejected, PaymentWebhookOutcome::Conflict => $failed++,
            };
        }

        $summary = new AssessmentBillStatusReconciliationResult(
            count($candidates), $checked, $applied, $ignored, $failed,
        );
        Log::info('assessment_bill_status_reconciliation_completed', [
            'scanned' => $summary->scanned,
            'checked' => $summary->checked,
            'applied' => $summary->applied,
            'ignored' => $summary->ignored,
            'failed' => $summary->failed,
        ]);

        return $summary;
    }

    private function assertOutsideAuthorityBoundary(): void
    {
        if (DB::transactionLevel() !== 0 || $this->contexts->current() !== null) {
            throw new LogicException('Assessment bill status checks require an empty transaction and RLS context.');
        }
    }
}
