<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

/**
 * HPP inputs that have no persisted source yet. Each method returns null
 * when the value is unavailable; the signed-report dataset then fails
 * closed and lists the gap instead of inventing a value. A real
 * implementation arrives once the coordinator approves the schema/data
 * for these fields.
 */
interface ReportSupplementalData
{
    public function reportNumber(int $assessmentCaseId, string $snapshotId): ?string;

    public function psychologistSippNumber(int $adminId): ?string;

    public function recommendationRationale(string $snapshotId): ?string;

    /** @return array<string, array{label_id: string, label_jp: string}>|null */
    public function aspectLabels(string $standardVersion): ?array;

    /** @return array{narrative: string, follow_up: string}|null */
    public function dassScreeningText(string $generalCategory): ?array;
}
