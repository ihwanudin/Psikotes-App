<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class IstStandardScoreCalculator
{
    private IstRawScoreLookup $lookup;

    /** @var list<string> */
    private array $subtests;

    /** @param array<mixed> $norms */
    public function __construct(array $norms)
    {
        if ($norms === []) {
            throw new InvalidArgumentException('IST norms must contain at least one subtest.');
        }

        $subtests = [];

        foreach ($norms as $subtest => $subtestNorm) {
            if (! is_string($subtest) || $subtest === '' || ! is_array($subtestNorm) || $subtestNorm === []) {
                throw new InvalidArgumentException('IST subtest norm configuration is invalid.');
            }

            $rawScoreDomain = array_keys($subtestNorm);
            sort($rawScoreDomain);

            if ($rawScoreDomain !== range(0, count($subtestNorm) - 1)) {
                throw new InvalidArgumentException('IST subtest norm raw-score domain must be contiguous from zero.');
            }

            foreach ($subtestNorm as $standardScore) {
                if (! is_int($standardScore)) {
                    throw new InvalidArgumentException('IST standard scores in supplied norms must be integers.');
                }
            }

            $subtests[] = $subtest;
        }

        $this->lookup = new IstRawScoreLookup($norms);
        $this->subtests = $subtests;
    }

    /**
     * @param  array<mixed>  $rawScores
     * @return array<string, int>
     */
    public function calculate(array $rawScores): array
    {
        foreach ($rawScores as $subtest => $rawScore) {
            if (! is_string($subtest) || ! in_array($subtest, $this->subtests, true)) {
                throw new InvalidArgumentException('IST raw score subtest is outside the supplied norms.');
            }

            if (! is_int($rawScore)) {
                throw new InvalidArgumentException('IST raw score must be an integer.');
            }
        }

        if (count($rawScores) !== count($this->subtests)) {
            throw new InvalidArgumentException('IST raw scores must contain every configured subtest exactly once.');
        }

        $standardScores = [];

        foreach ($this->subtests as $subtest) {
            if (! array_key_exists($subtest, $rawScores)) {
                throw new InvalidArgumentException('IST raw scores must contain every configured subtest exactly once.');
            }

            $standardScores[$subtest] = $this->lookup->standardScore($subtest, $rawScores[$subtest]);
        }

        return $standardScores;
    }
}
