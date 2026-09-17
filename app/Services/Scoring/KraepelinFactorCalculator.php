<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final class KraepelinFactorCalculator
{
    private const COLUMN_COUNT = 50;

    private const MAX_ACHIEVEMENT = 27;

    /**
     * @param  array<mixed>  $columns
     * @return array{
     *     panker: float,
     *     tianker: int,
     *     hanker: float,
     *     janker: int,
     *     review_required: bool,
     *     review_reason: string|null,
     *     provenance: array{
     *         column_count: int,
     *         sum_achievement: int,
     *         sum_correct: int,
     *         sum_incorrect: int,
     *         sum_skipped: int,
     *         sum_x: int,
     *         sum_xy: int,
     *         sum_x_squared: int,
     *         slope: float
     *     }
     * }
     */
    public function calculate(array $columns): array
    {
        if (! array_is_list($columns) || count($columns) !== self::COLUMN_COUNT) {
            throw new InvalidArgumentException('Kraepelin input must contain exactly 50 columns.');
        }

        $sumAchievement = 0;
        $sumCorrect = 0;
        $sumIncorrect = 0;
        $sumSkipped = 0;
        $sumX = 0;
        $sumXy = 0;
        $sumXSquared = 0;
        $achievementValues = [];

        foreach ($columns as $offset => $column) {
            if (! is_array($column)
                || ! array_key_exists('achievement', $column)
                || ! array_key_exists('correct', $column)
                || ! array_key_exists('incorrect', $column)
                || ! array_key_exists('skipped', $column)
                || ! is_int($column['achievement'])
                || ! is_int($column['correct'])
                || ! is_int($column['incorrect'])
                || ! is_int($column['skipped'])) {
                throw new InvalidArgumentException('Each Kraepelin column must contain integer achievement, correct, incorrect, and skipped values.');
            }

            if ($column['achievement'] < 0
                || $column['correct'] < 0
                || $column['incorrect'] < 0
                || $column['skipped'] < 0) {
                throw new InvalidArgumentException('Kraepelin column values must be non-negative.');
            }

            if ($column['achievement'] > self::MAX_ACHIEVEMENT) {
                throw new InvalidArgumentException('Kraepelin achievement cannot exceed 27.');
            }

            if ($column['achievement'] + $column['skipped'] > self::MAX_ACHIEVEMENT) {
                throw new InvalidArgumentException('Kraepelin achievement plus skipped cannot exceed 27.');
            }

            if ($column['achievement'] !== $column['correct'] + $column['incorrect']) {
                throw new InvalidArgumentException('Kraepelin correct plus incorrect must equal achievement.');
            }

            $x = $offset + 1;
            $achievement = $column['achievement'];
            $sumAchievement += $achievement;
            $sumCorrect += $column['correct'];
            $sumIncorrect += $column['incorrect'];
            $sumSkipped += $column['skipped'];
            $sumX += $x;
            $sumXy += $x * $achievement;
            $sumXSquared += $x * $x;
            $achievementValues[] = $achievement;
        }

        $slope = (self::COLUMN_COUNT * $sumXy - $sumX * $sumAchievement)
            / (self::COLUMN_COUNT * $sumXSquared - $sumX * $sumX);
        $hanker = round($slope * self::COLUMN_COUNT, 3);
        $janker = max($achievementValues) - min($achievementValues);
        $reviewRequired = abs($hanker) > $janker;

        return [
            'panker' => round($sumAchievement / self::COLUMN_COUNT, 3),
            'tianker' => $sumIncorrect + $sumSkipped,
            'hanker' => $hanker,
            'janker' => $janker,
            'review_required' => $reviewRequired,
            'review_reason' => $reviewRequired ? 'absolute_hanker_exceeds_janker' : null,
            'provenance' => [
                'column_count' => self::COLUMN_COUNT,
                'sum_achievement' => $sumAchievement,
                'sum_correct' => $sumCorrect,
                'sum_incorrect' => $sumIncorrect,
                'sum_skipped' => $sumSkipped,
                'sum_x' => $sumX,
                'sum_xy' => $sumXy,
                'sum_x_squared' => $sumXSquared,
                'slope' => round($slope, 6),
            ],
        ];
    }
}
