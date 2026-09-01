<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use LogicException;

/** Internal bounded coordinator. No command, job, scheduler, or route is registered. */
final readonly class CoordinateAssessmentInvoiceReconciliation
{
    public function __construct(
        private ReserveAssessmentInvoiceReconciliationHints $reservations,
        private ValidateAssessmentInvoiceReconciliationLease $validation,
        private ReconcileAssessmentBillInvoice $reconciliation,
    ) {}

    /**
     * @return array{
     *     batchLimit: int,
     *     scanLimit: int,
     *     maxLookups: int,
     *     reserved: int,
     *     validated: int,
     *     issued: int,
     *     unknown: int,
     *     validationRejected: int,
     *     recoveryRequired: int
     * }
     */
    public function execute(): array
    {
        $batch = config('assessment_billing.invoice_reconciliation_batch_size');
        $scan = config('assessment_billing.invoice_reconciliation_scan_limit');
        $max = config('assessment_billing.invoice_reconciliation_max_lookups');
        if (! is_int($batch) || ! is_int($scan) || ! is_int($max)) {
            throw new LogicException('Invoice reconciliation configuration is invalid.');
        }

        $remainingLookups = $batch;
        $remainingScan = $scan;
        $seen = [];
        $reserved = 0;
        $validated = 0;
        $issued = 0;
        $unknown = 0;
        $validationRejected = 0;
        $recoveryRequired = 0;

        while ($remainingLookups > 0 && $remainingScan > 0) {
            // Phase one owns canonical config validation and outbox-only reservation.
            $leases = $this->reservations->execute($remainingLookups, $remainingScan, $seen);
            if ($leases === []) {
                break;
            }

            foreach ($leases as $lease) {
                $reserved++;
                $remainingScan--;
                $seen[] = $lease->messageId;
                $permit = $this->validation->execute($lease);
                if ($permit === null) {
                    $validationRejected++;

                    continue;
                }
                $validated++;
                $remainingLookups--;
                $result = $this->reconciliation->executeLeased($permit);
                match ($result['decision']) {
                    'issued' => $issued++,
                    'unknown' => $unknown++,
                    'recovery_required' => $recoveryRequired++,
                    default => throw new LogicException('Invoice reconciliation returned an invalid decision.'),
                };
            }
        }

        return [
            'batchLimit' => $batch,
            'scanLimit' => $scan,
            'maxLookups' => $max,
            'reserved' => $reserved,
            'validated' => $validated,
            'issued' => $issued,
            'unknown' => $unknown,
            'validationRejected' => $validationRejected,
            'recoveryRequired' => $recoveryRequired,
        ];
    }
}
