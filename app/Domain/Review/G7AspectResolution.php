<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use InvalidArgumentException;

final readonly class G7AspectResolution
{
    public const STATE_NOT_REQUIRED = 'NOT_REQUIRED';

    public const STATE_UNRESOLVED = 'UNRESOLVED';

    public const STATE_RESOLVED = 'RESOLVED';

    /**
     * @param  array<mixed>  $discrepancy
     */
    private function __construct(
        private string $state,
        private array $discrepancy,
        private int $systemLevel,
        private ?int $finalLevel,
        private ?string $reason,
    ) {}

    /** @param array<mixed> $discrepancy */
    public static function notRequired(array $discrepancy, int $systemLevel): self
    {
        $canonical = self::authoritativeDiscrepancy($discrepancy);
        self::assertLevel($systemLevel, 'system');

        if ($canonical['review_required']) {
            throw new InvalidArgumentException('A G7 review-required aspect cannot be marked not required.');
        }

        return new self(self::STATE_NOT_REQUIRED, $canonical, $systemLevel, $systemLevel, null);
    }

    /** @param array<mixed> $discrepancy */
    public static function unresolved(array $discrepancy, int $systemLevel): self
    {
        $canonical = self::authoritativeDiscrepancy($discrepancy);
        self::assertLevel($systemLevel, 'system');

        if (! $canonical['review_required']) {
            throw new InvalidArgumentException('An aspect below the G7 threshold cannot be unresolved.');
        }

        return new self(self::STATE_UNRESOLVED, $canonical, $systemLevel, null, null);
    }

    /** @param array<mixed> $discrepancy */
    public static function resolved(
        array $discrepancy,
        int $systemLevel,
        int $finalLevel,
        ?string $reason,
    ): self {
        $canonical = self::authoritativeDiscrepancy($discrepancy);
        self::assertLevel($systemLevel, 'system');
        self::assertLevel($finalLevel, 'final');

        if (! $canonical['review_required']) {
            throw new InvalidArgumentException('An aspect below the G7 threshold cannot have a resolution.');
        }

        $canonicalReason = self::canonicalReason($reason);
        if ($finalLevel === $systemLevel) {
            $canonicalReason = $canonicalReason === '' ? null : $canonicalReason;
        } elseif ($canonicalReason === null || mb_strlen($canonicalReason) < 20) {
            throw new InvalidArgumentException('A changed G7 resolution requires a G6 reason of at least 20 characters.');
        }

        return new self(self::STATE_RESOLVED, $canonical, $systemLevel, $finalLevel, $canonicalReason);
    }

    public function aspect(): string
    {
        /** @var string $aspect */
        $aspect = $this->discrepancy['provenance']['aspect'];

        return $aspect;
    }

    public function state(): string
    {
        return $this->state;
    }

    /** @return array<mixed> */
    public function discrepancy(): array
    {
        return $this->discrepancy;
    }

    public function systemLevel(): int
    {
        return $this->systemLevel;
    }

    public function finalLevel(): ?int
    {
        return $this->finalLevel;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function isResolved(): bool
    {
        return $this->state !== self::STATE_UNRESOLVED;
    }

    /**
     * @param  array<mixed>  $result
     * @return array{
     *     type: 'aspect_source_discrepancy',
     *     review_required: bool,
     *     automatic_narrative_allowed: bool,
     *     reason_code: 'SOURCE_LEVEL_SPREAD'|null,
     *     provenance: array{
     *         aspect: string,
     *         sources: list<array{source: string, level: int}>,
     *         minimum_level: int,
     *         maximum_level: int,
     *         spread: int
     *     }
     * }
     */
    private static function authoritativeDiscrepancy(array $result): array
    {
        if (! self::hasExactKeys($result, ['type', 'review_required', 'automatic_narrative_allowed', 'reason_code', 'provenance'])
            || ! isset($result['provenance'])
            || ! is_array($result['provenance'])
            || ! self::hasExactKeys($result['provenance'], ['aspect', 'sources', 'minimum_level', 'maximum_level', 'spread'])
            || ! isset($result['provenance']['aspect'])
            || ! is_string($result['provenance']['aspect'])
            || ! isset($result['provenance']['sources'])
            || ! is_array($result['provenance']['sources'])) {
            throw new InvalidArgumentException('G7 requires the complete authoritative discrepancy result.');
        }

        try {
            $canonical = (new AspectSourceDiscrepancyPolicy)->evaluate([
                'aspect' => $result['provenance']['aspect'],
                'sources' => $result['provenance']['sources'],
            ]);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'G7 discrepancy provenance is invalid.',
                previous: $exception,
            );
        }

        if ($result !== $canonical) {
            throw new InvalidArgumentException('G7 discrepancy result is not authoritative.');
        }

        return $canonical;
    }

    private static function assertLevel(int $level, string $kind): void
    {
        if ($level < 1 || $level > 5) {
            throw new InvalidArgumentException("G7 {$kind} level must be between 1 and 5.");
        }
    }

    private static function canonicalReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        if (! mb_check_encoding($reason, 'UTF-8')) {
            throw new InvalidArgumentException('G7 resolution reason must be valid UTF-8.');
        }

        $trimmed = preg_replace('/^\s+|\s+$/u', '', $reason);
        if (! is_string($trimmed)) {
            throw new InvalidArgumentException('G7 resolution reason is invalid.');
        }

        return $trimmed;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private static function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }
}
