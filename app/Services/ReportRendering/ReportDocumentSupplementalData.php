<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

/**
 * Production ReportSupplementalData: reportNumber() is a real, read-only
 * lookup of the existing number for a case (report_documents, shared with
 * ReportDocumentIssuer::existingReportNumberFor() rather than duplicated).
 * Null means no document has ever been issued for the case yet, which
 * SignedReportDataset reports as a warning, not a blocking gap — issuing
 * the actual number is ReportDocumentIssuer's job, never this class's.
 *
 * The other three inputs have no persisted source yet
 * (tasks/handoffs/f6/report-supplemental-data-proposal.md); they return
 * null exactly like UnavailableReportSupplementalData rather than
 * duplicating "always null" through composition.
 */
final readonly class ReportDocumentSupplementalData implements ReportSupplementalData
{
    public function __construct(
        private ReportDocumentIssuer $issuer,
    ) {}

    public function reportNumber(int $assessmentCaseId, string $snapshotId): ?string
    {
        return $this->issuer->existingReportNumberFor($assessmentCaseId);
    }

    public function recommendationRationale(string $snapshotId): ?string
    {
        return null;
    }

    public function aspectLabels(string $standardVersion): ?array
    {
        return null;
    }

    public function dassScreeningText(string $generalCategory): ?array
    {
        return null;
    }
}
