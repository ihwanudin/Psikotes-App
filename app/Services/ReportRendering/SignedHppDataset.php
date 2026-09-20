<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use App\Domain\Report\HppReportDraft;
use App\Domain\Report\ReportIdentity;
use LogicException;

/**
 * Outcome of assembling an HPP from a signed snapshot: either ready, or
 * the exact list of gap codes that blocked it (fail closed). Warnings are
 * non-blocking gaps (DASS, and a not-yet-issued report number): the
 * report is still publishable and states the gap honestly instead of
 * hiding it.
 *
 * "Ready" has two shapes. When a report number is already known (an
 * override supplied by the issuer, or an existing number for the case),
 * draft()/identity() are materialized and safe to render. When nothing
 * else is missing but no number exists yet (REPORT_NUMBER_NOT_YET_ISSUED
 * is a warning, not a gap), isReady() is still true — the case is
 * eligible for generation — but draft()/identity() are not materialized:
 * there is nothing to render until SignedReportDataset::hpp() is called
 * again with a resolved reportNumberOverride, which is exactly what
 * ReportDocumentIssuer's render callback does.
 */
final readonly class SignedHppDataset
{
    /**
     * @param  array{case_id: int, snapshot_version: int, psychologist_admin_id: int, psychologist_name: string, psychologist_silp: string, psychologist_str: string, facility_name: string}|null  $issuance
     * @param  list<string>  $missing
     * @param  list<string>  $warnings
     */
    private function __construct(
        public ?string $snapshotId,
        private bool $ready,
        private ?HppReportDraft $draft,
        private ?ReportIdentity $identity,
        public ?array $issuance,
        public array $missing,
        public array $warnings,
    ) {}

    /**
     * @param  array{case_id: int, snapshot_version: int, psychologist_admin_id: int, psychologist_name: string, psychologist_silp: string, psychologist_str: string, facility_name: string}  $issuance
     * @param  list<string>  $warnings
     */
    public static function ready(
        string $snapshotId,
        array $issuance,
        ?HppReportDraft $draft,
        ?ReportIdentity $identity,
        array $warnings = [],
    ): self {
        return new self($snapshotId, true, $draft, $identity, $issuance, [], array_values(array_unique($warnings)));
    }

    /**
     * @param  list<string>  $missing
     * @param  list<string>  $warnings
     */
    public static function blocked(?string $snapshotId, array $missing, array $warnings = []): self
    {
        if ($missing === []) {
            throw new LogicException('A blocked HPP dataset must name at least one gap.');
        }

        return new self($snapshotId, false, null, null, null, array_values(array_unique($missing)), array_values(array_unique($warnings)));
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function draft(): HppReportDraft
    {
        return $this->draft ?? throw new LogicException($this->ready
            ? 'HPP draft is not yet materialized: the report has no number yet. Call hpp() again with a resolved reportNumberOverride to render.'
            : 'HPP dataset is blocked: '.implode(', ', $this->missing));
    }

    public function identity(): ReportIdentity
    {
        return $this->identity ?? throw new LogicException($this->ready
            ? 'HPP identity is not yet materialized: the report has no number yet. Call hpp() again with a resolved reportNumberOverride to render.'
            : 'HPP dataset is blocked: '.implode(', ', $this->missing));
    }
}
