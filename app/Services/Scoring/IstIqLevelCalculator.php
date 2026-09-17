<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class IstIqLevelCalculator
{
    /** @var list<array{lo: int|null, hi: int|null, level: int, source_scores: list<int>, category: string}> */
    private array $bands;

    /** @param array<mixed> $bands */
    public function __construct(array $bands)
    {
        if (! array_is_list($bands) || count($bands) !== 5) {
            throw new InvalidArgumentException('IST IQ level bands must define exactly five levels.');
        }

        $validated = [];
        foreach ($bands as $band) {
            if (! is_array($band)
                || ! array_key_exists('lo', $band)
                || ! array_key_exists('hi', $band)
                || ! isset($band['level'], $band['source_scores'], $band['category'])
                || (! is_int($band['lo']) && $band['lo'] !== null)
                || (! is_int($band['hi']) && $band['hi'] !== null)
                || ! is_int($band['level'])
                || ! array_is_list($band['source_scores'])
                || $band['source_scores'] === []
                || array_filter($band['source_scores'], static fn (mixed $score): bool => ! is_int($score)) !== []
                || ! is_string($band['category'])
                || trim($band['category']) === '') {
                throw new InvalidArgumentException('IST IQ level band definition is invalid.');
            }

            $validated[] = $band;
        }

        usort($validated, static fn (array $left, array $right): int => ($left['level'] <=> $right['level']));
        $sourceScores = array_merge(...array_column($validated, 'source_scores'));
        sort($sourceScores);
        if (array_column($validated, 'level') !== range(1, 5)
            || $validated[0]['lo'] !== null
            || $validated[4]['hi'] !== null
            || $sourceScores !== range(1, 10)) {
            throw new InvalidArgumentException('IST IQ level bands must cover five ordered unbounded levels.');
        }

        for ($index = 1; $index < 5; $index++) {
            if (! is_int($validated[$index - 1]['hi'])
                || ! is_int($validated[$index]['lo'])
                || $validated[$index]['lo'] !== $validated[$index - 1]['hi'] + 1) {
                throw new InvalidArgumentException('IST IQ level bands must be contiguous.');
            }
        }

        $this->bands = $validated;
    }

    /** @return array{iq: int, level: int, source_scores: list<int>, category: string, band: array{lo: int|null, hi: int|null}} */
    public function calculate(mixed $iq): array
    {
        if (! is_int($iq)) {
            throw new InvalidArgumentException('IST IQ must be an integer.');
        }

        foreach ($this->bands as $band) {
            if (($band['lo'] === null || $iq >= $band['lo']) && ($band['hi'] === null || $iq <= $band['hi'])) {
                return [
                    'iq' => $iq,
                    'level' => $band['level'],
                    'source_scores' => $band['source_scores'],
                    'category' => $band['category'],
                    'band' => ['lo' => $band['lo'], 'hi' => $band['hi']],
                ];
            }
        }

        throw new InvalidArgumentException('IST IQ is outside the supplied level bands.');
    }
}
