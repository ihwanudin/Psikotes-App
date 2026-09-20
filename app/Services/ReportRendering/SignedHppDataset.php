<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use App\Domain\Report\HppReportDraft;
use App\Domain\Report\ReportIdentity;
use LogicException;

/**
 * Outcome of assembling an HPP from a signed snapshot: either a ready
 * draft, or the exact list of gap codes that blocked it (fail closed).
 * Warnings are non-blocking gaps (currently DASS): the report is still
 * publishable and states the gap honestly instead of hiding it.
 */
final readonly class SignedHppDataset
{
    /**
     * @param  list<string>  $missing
     * @param  list<string>  $warnings
     */
    private function __construct(
        public ?string $snapshotId,
        private ?HppReportDraft $draft,
        private ?ReportIdentity $identity,
        public array $missing,
        public array $warnings,
    ) {}

    /** @param list<string> $warnings */
    public static function ready(string $snapshotId, HppReportDraft $draft, ReportIdentity $identity, array $warnings = []): self
    {
        return new self($snapshotId, $draft, $identity, [], array_values(array_unique($warnings)));
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

        return new self($snapshotId, null, null, array_values(array_unique($missing)), array_values(array_unique($warnings)));
    }

    public function isReady(): bool
    {
        return $this->draft !== null;
    }

    public function draft(): HppReportDraft
    {
        return $this->draft ?? throw new LogicException('HPP dataset is blocked: '.implode(', ', $this->missing));
    }

    public function identity(): ReportIdentity
    {
        return $this->identity ?? throw new LogicException('HPP dataset is blocked: '.implode(', ', $this->missing));
    }
}
