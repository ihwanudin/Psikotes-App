<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

/**
 * Honest default: none of the supplemental HPP inputs are persisted yet,
 * so every lookup reports "unavailable".
 */
final class UnavailableReportSupplementalData implements ReportSupplementalData
{
    public function reportNumber(int $assessmentCaseId, string $snapshotId): ?string
    {
        return null;
    }

    public function psychologistSippNumber(int $adminId): ?string
    {
        return null;
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
