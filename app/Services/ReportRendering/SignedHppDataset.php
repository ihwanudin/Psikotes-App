<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use App\Domain\Report\HppReportDraft;
use App\Domain\Report\ReportIdentity;
use LogicException;

/**
 * Outcome of assembling an HPP from a signed snapshot: either a ready
 * draft, or the exact list of gap codes that blocked it (fail closed).
 */
final readonly class SignedHppDataset
{
    /** @param list<string> $missing */
    private function __construct(
        public ?string $snapshotId,
        private ?HppReportDraft $draft,
        private ?ReportIdentity $identity,
        public array $missing,
    ) {}

    public static function ready(string $snapshotId, HppReportDraft $draft, ReportIdentity $identity): self
    {
        return new self($snapshotId, $draft, $identity, []);
    }

    /** @param list<string> $missing */
    public static function blocked(?string $snapshotId, array $missing): self
    {
        if ($missing === []) {
            throw new LogicException('A blocked HPP dataset must name at least one gap.');
        }

        return new self($snapshotId, null, null, array_values(array_unique($missing)));
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
