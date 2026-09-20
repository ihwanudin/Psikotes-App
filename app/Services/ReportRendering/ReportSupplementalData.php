<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

/**
 * HPP inputs that have no persisted source yet. Each method returns null
 * when the value is unavailable; the signed-report dataset then fails
 * closed and lists the gap instead of inventing a value. A real
 * implementation arrives once the coordinator approves the schema/data
 * for these fields.
 *
 * Deliberately does NOT have a psychologist licence number method:
 * SignedReportDataset reads admins.silp_number/str_number directly
 * (Lead's 2026-09-21 review) — a second path through this interface to
 * the same columns would be two sources of truth for a number printed
 * on an official document.
 */
interface ReportSupplementalData
{
    public function reportNumber(int $assessmentCaseId, string $snapshotId): ?string;

    public function recommendationRationale(string $snapshotId): ?string;

    /** @return array<string, array{label_id: string, label_jp: string}>|null */
    public function aspectLabels(string $standardVersion): ?array;

    /**
     * Follow-up text exists only for Sedang and Parah/Sangat Parah; for
     * Normal/Ringan it is null and the report omits that line.
     *
     * @return array{narrative: string, follow_up: string|null}|null
     */
    public function dassScreeningText(string $generalCategory): ?array;
}
