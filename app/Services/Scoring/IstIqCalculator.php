<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class IstIqCalculator
{
    /** @var array<int, int> */
    private array $iqLookup;

    /** @var list<string> */
    private array $subtests;

    /** @var array<string, int> */
    private array $maximumRawScores;

    /**
     * @param  array<mixed>  $iqRanges
     * @param  array<mixed>  $subtestNorms
     */
    public function __construct(array $iqRanges, array $subtestNorms)
    {
        if (count($subtestNorms) !== 9) {
            throw new InvalidArgumentException('IST IQ calculation requires norms for exactly nine subtests.');
        }

        $subtests = [];
        $maximumRawScores = [];

        foreach ($subtestNorms as $subtest => $norm) {
            if (! is_string($subtest) || $subtest === '' || ! is_array($norm) || $norm === []) {
                throw new InvalidArgumentException('IST IQ subtest norm configuration is invalid.');
            }

            $rawScoreDomain = array_keys($norm);
            sort($rawScoreDomain);

            if ($rawScoreDomain !== range(0, count($norm) - 1)) {
                throw new InvalidArgumentException('IST IQ subtest norm domain must be contiguous from zero.');
            }

            foreach ($norm as $standardScore) {
                if (! is_int($standardScore)) {
                    throw new InvalidArgumentException('IST IQ subtest standard scores must be integers.');
                }
            }

            $subtests[] = $subtest;
            $maximumRawScores[$subtest] = $rawScoreDomain[count($rawScoreDomain) - 1];
        }

        if (! array_is_list($iqRanges) || $iqRanges === []) {
            throw new InvalidArgumentException('IST IQ lookup must contain at least one range.');
        }

        $lookup = [];

        foreach ($iqRanges as $range) {
            if (! is_array($range)
                || ! array_key_exists('lo', $range)
                || ! array_key_exists('hi', $range)
                || ! array_key_exists('iq', $range)
                || ! is_int($range['lo'])
                || ! is_int($range['hi'])
                || ! is_int($range['iq'])
                || $range['lo'] < 0
                || $range['hi'] < $range['lo']) {
                throw new InvalidArgumentException('IST IQ range definition is invalid.');
            }

            for ($total = $range['lo']; $total <= $range['hi']; $total++) {
                if (array_key_exists($total, $lookup)) {
                    throw new InvalidArgumentException('IST IQ lookup ranges must not overlap.');
                }

                $lookup[$total] = $range['iq'];
            }
        }

        ksort($lookup);
        $domain = array_keys($lookup);

        if ($domain !== range($domain[0], $domain[count($domain) - 1])) {
            throw new InvalidArgumentException('IST IQ lookup domain must not contain gaps.');
        }

        $this->iqLookup = $lookup;
        $this->subtests = $subtests;
        $this->maximumRawScores = $maximumRawScores;
    }

    /** @param array<mixed> $rawScores */
    public function calculate(array $rawScores): int
    {
        foreach ($rawScores as $subtest => $rawScore) {
            if (! is_string($subtest) || ! in_array($subtest, $this->subtests, true)) {
                throw new InvalidArgumentException('IST raw score subtest is outside the supplied subtests.');
            }

            if (! is_int($rawScore) || $rawScore < 0) {
                throw new InvalidArgumentException('IST raw scores used for IQ must be non-negative integers.');
            }
        }

        if (count($rawScores) !== 9) {
            throw new InvalidArgumentException('IST raw scores must contain exactly the nine supplied subtests.');
        }

        $total = 0;

        foreach ($this->subtests as $subtest) {
            if (! array_key_exists($subtest, $rawScores)) {
                throw new InvalidArgumentException('IST raw scores must contain exactly the nine supplied subtests.');
            }

            if ($rawScores[$subtest] > $this->maximumRawScores[$subtest]) {
                throw new InvalidArgumentException('IST raw score is outside the supplied subtest norm domain.');
            }

            $total += $rawScores[$subtest];
        }

        if (! array_key_exists($total, $this->iqLookup)) {
            throw new InvalidArgumentException('IST raw-score sum is outside the supplied IQ lookup.');
        }

        return $this->iqLookup[$total];
    }
}
