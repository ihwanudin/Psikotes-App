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

        // Phase one owns the canonical config validation and outbox-only reservation.
        $leases = $this->reservations->execute($batch, $scan);
        $validated = 0;
        $issued = 0;
        $unknown = 0;
        $recoveryRequired = 0;

        foreach ($leases as $lease) {
            $permit = $this->validation->execute($lease);
            if ($permit === null) {
                $recoveryRequired++;

                continue;
            }
            $validated++;
            $result = $this->reconciliation->executeLeased($permit);
            match ($result['decision']) {
                'issued' => $issued++,
                'unknown' => $unknown++,
                'recovery_required' => $recoveryRequired++,
                default => throw new LogicException('Invoice reconciliation returned an invalid decision.'),
            };
        }

        return [
            'batchLimit' => $batch,
            'scanLimit' => $scan,
            'maxLookups' => $max,
            'reserved' => count($leases),
            'validated' => $validated,
            'issued' => $issued,
            'unknown' => $unknown,
            'recoveryRequired' => $recoveryRequired,
        ];
    }
}
