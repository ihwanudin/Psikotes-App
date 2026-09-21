<?php

declare(strict_types=1);

namespace App\Services\Eligibility;

use App\Domain\Review\G7AspectResolution;
use InvalidArgumentException;

/**
 * F2 G7-server-side-sources, Phase 1 (2026-09-21). Mirrors, exactly, the
 * per-aspect dispatch ReportSigningService::sign() already performs at
 * app/Services/Review/ReportSigningService.php:151-173 (review_required
 * decides notRequired vs unresolved vs resolved; a final_level/reason
 * supplied for a not-required aspect is rejected) -- the only difference is
 * that the discrepancy comes from LoadLedgerAspectDiscrepancy (the ledger)
 * instead of AspectSourceDiscrepancyPolicy::evaluate() fed with client
 * `sources`. This exists so Phase 2's swap in ReportSigningService.php can
 * be one call replacing the client-trusting block, not a re-implementation
 * of the dispatch logic there.
 *
 * $finalLevel/$reason are the only caller-supplied values here, and they are
 * exactly what a psychologist legitimately provides: a professional
 * override decision, never a source level. There is no `sources` parameter
 * to forge.
 */
final readonly class ResolveG7AspectFromLedger
{
    public function __construct(private LoadLedgerAspectDiscrepancy $discrepancy) {}

    public function execute(
        int $assessmentCaseId,
        string $aspect,
        int $systemLevel,
        ?int $finalLevel,
        ?string $reason,
    ): G7AspectResolution {
        $discrepancy = $this->discrepancy->execute($assessmentCaseId, $aspect);

        if (! $discrepancy['review_required']) {
            if ($finalLevel !== null || $reason !== null) {
                throw new InvalidArgumentException(
                    "G7 aspect {$aspect} is not review-required but has resolution data.",
                );
            }

            return G7AspectResolution::notRequired($discrepancy, $systemLevel);
        }

        return $finalLevel === null
            ? G7AspectResolution::unresolved($discrepancy, $systemLevel)
            : G7AspectResolution::resolved($discrepancy, $systemLevel, $finalLevel, $reason);
    }
}
