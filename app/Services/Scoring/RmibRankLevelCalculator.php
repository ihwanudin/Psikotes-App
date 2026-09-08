<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class RmibRankLevelCalculator
{
    /** @var array<int, int> */
    private array $rankToLevel;

    /** @param array<mixed> $rankToLevel */
    public function __construct(array $rankToLevel)
    {
        $validated = [];
        foreach (range(1, 12) as $rank) {
            $key = (string) $rank;
            if (! isset($rankToLevel[$key]) || ! is_int($rankToLevel[$key]) || $rankToLevel[$key] < 1 || $rankToLevel[$key] > 5) {
                throw new InvalidArgumentException('RMIB rank-to-level mapping is invalid.');
            }
            $validated[$rank] = $rankToLevel[$key];
        }

        if (count($rankToLevel) !== 12) {
            throw new InvalidArgumentException('RMIB rank-to-level mapping must cover ranks one through twelve.');
        }

        $configuredLevels = array_values($validated);
        $uniqueLevels = array_values(array_unique($configuredLevels));
        sort($uniqueLevels);
        for ($index = 1; $index < count($configuredLevels); $index++) {
            if ($configuredLevels[$index] > $configuredLevels[$index - 1]) {
                throw new InvalidArgumentException('RMIB rank-to-level mapping must be non-increasing.');
            }
        }
        if ($uniqueLevels !== range(1, 5)) {
            throw new InvalidArgumentException('RMIB rank-to-level mapping must cover all five levels.');
        }

        $this->rankToLevel = $validated;
    }

    /** @return array{rank: int, level: int} */
    public function calculate(mixed $rank): array
    {
        if (! is_int($rank) || ! array_key_exists($rank, $this->rankToLevel)) {
            throw new InvalidArgumentException('RMIB rank must be an integer from one through twelve.');
        }

        return ['rank' => $rank, 'level' => $this->rankToLevel[$rank]];
    }
}
