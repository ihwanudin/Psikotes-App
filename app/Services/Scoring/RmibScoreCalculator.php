<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

/**
 * F2 lane G7 (2026-09-20). Mirrors RmibRankLevelCalculator's structure for the
 * companion rank->score table (SCORING_ALGORITHM.md: "Sheet 11 memberi
 * rank->skor: 1->10, 2->9, ..., 12->1"), already present as approved data in
 * database/seeders/data/rmib.json's `rank_to_score` field (an object keyed
 * "1".."12", matching `rank_to_level`'s shape exactly) but not previously
 * wired to any calculator. Every value comes from the supplied table;
 * nothing is hardcoded here.
 */
final readonly class RmibScoreCalculator
{
    /** @var array<int, int> */
    private array $rankToScore;

    /** @param array<mixed> $rankToScore */
    public function __construct(array $rankToScore)
    {
        $validated = [];
        foreach (range(1, 12) as $rank) {
            $key = (string) $rank;
            if (! isset($rankToScore[$key]) || ! is_int($rankToScore[$key])
                || $rankToScore[$key] < 1 || $rankToScore[$key] > 10) {
                throw new InvalidArgumentException('RMIB rank-to-score mapping is invalid.');
            }
            $validated[$rank] = $rankToScore[$key];
        }

        if (count($rankToScore) !== 12) {
            throw new InvalidArgumentException('RMIB rank-to-score mapping must cover ranks one through twelve.');
        }

        for ($rank = 2; $rank <= 12; $rank++) {
            if ($validated[$rank] > $validated[$rank - 1]) {
                throw new InvalidArgumentException('RMIB rank-to-score mapping must be non-increasing as rank increases.');
            }
        }

        $this->rankToScore = $validated;
    }

    /** @return array{rank: int, score: int} */
    public function calculate(mixed $rank): array
    {
        if (! is_int($rank) || ! array_key_exists($rank, $this->rankToScore)) {
            throw new InvalidArgumentException('RMIB rank must be an integer from one through twelve.');
        }

        return ['rank' => $rank, 'score' => $this->rankToScore[$rank]];
    }
}
