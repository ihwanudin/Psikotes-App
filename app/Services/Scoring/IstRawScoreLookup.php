<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;
use UnexpectedValueException;

final readonly class IstRawScoreLookup
{
    /** @param array<string, mixed> $norms */
    public function __construct(private array $norms) {}

    public function standardScore(string $subtest, int $rawScore): int
    {
        if (! array_key_exists($subtest, $this->norms) || ! is_array($this->norms[$subtest])) {
            throw new InvalidArgumentException('IST subtest is not present in the supplied norms.');
        }

        $subtestNorm = $this->norms[$subtest];

        if (! array_key_exists($rawScore, $subtestNorm)) {
            throw new InvalidArgumentException('IST raw score is not present in the supplied subtest norm.');
        }

        $standardScore = $subtestNorm[$rawScore];

        if (! is_int($standardScore)) {
            throw new UnexpectedValueException('IST standard score in the supplied norm must be an integer.');
        }

        return $standardScore;
    }
}
