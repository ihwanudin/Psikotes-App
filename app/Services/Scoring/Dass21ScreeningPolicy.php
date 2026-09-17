<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class Dass21ScreeningPolicy
{
    /** @var list<string> */
    private const SCALES = ['D', 'A', 'S'];

    public function __construct(private int $minimumCompletionSeconds)
    {
        if ($this->minimumCompletionSeconds < 1) {
            throw new InvalidArgumentException('DASS-21 minimum completion duration must be positive.');
        }
    }

    /**
     * @param  array<mixed>  $scoredResult
     * @return array{
     *     general: array{category: string, level: int, basis_scales: list<string>},
     *     follow_up: array{type: 'none'|'monitoring'|'referral_support_offer', support_offer: bool},
     *     validity_flags: array{uniform_responses: bool, completion_under_minimum: bool},
     *     active_flag_codes: list<string>,
     *     provenance: array{
     *         completion_duration_seconds: int,
     *         minimum_completion_seconds: int,
     *         response_count: int,
     *         distinct_response_values: int
     *     }
     * }
     */
    public function evaluate(array $scoredResult, int $completionDurationSeconds): array
    {
        if ($completionDurationSeconds < 0) {
            throw new InvalidArgumentException('DASS-21 completion duration must be non-negative.');
        }

        if (! isset($scoredResult['general'], $scoredResult['subscales'])
            || ! is_array($scoredResult['general'])
            || ! is_array($scoredResult['subscales'])
            || ! isset(
                $scoredResult['general']['category'],
                $scoredResult['general']['level'],
                $scoredResult['general']['basis_scales'],
            )
            || ! is_string($scoredResult['general']['category'])
            || $scoredResult['general']['category'] === ''
            || ! is_int($scoredResult['general']['level'])
            || ! is_array($scoredResult['general']['basis_scales'])) {
            throw new InvalidArgumentException('DASS-21 scored result metadata is invalid.');
        }

        $generalLevel = $scoredResult['general']['level'];

        if ($generalLevel < 1 || $generalLevel > 5) {
            throw new InvalidArgumentException('DASS-21 general level must be between one and five.');
        }

        $subscaleNames = array_keys($scoredResult['subscales']);

        if ($subscaleNames !== self::SCALES) {
            throw new InvalidArgumentException('DASS-21 scored result must contain D, A, and S subscales.');
        }

        $responseScores = [];
        $expectedBasisScales = [];
        $maximumSubscaleLevel = 0;

        foreach (self::SCALES as $scale) {
            $subscale = $scoredResult['subscales'][$scale];

            if (! is_array($subscale)
                || ! isset($subscale['level'], $subscale['category'], $subscale['item_scores'])
                || ! is_int($subscale['level'])
                || $subscale['level'] < 1
                || $subscale['level'] > 5
                || ! is_string($subscale['category'])
                || ! is_array($subscale['item_scores'])
                || count($subscale['item_scores']) !== 7) {
                throw new InvalidArgumentException('DASS-21 scored subscale metadata is invalid.');
            }

            foreach ($subscale['item_scores'] as $item => $score) {
                if (! is_int($item)
                    || ! is_int($score)
                    || $score < 0
                    || $score > 3
                    || array_key_exists($item, $responseScores)) {
                    throw new InvalidArgumentException('DASS-21 scored item provenance is invalid.');
                }

                $responseScores[$item] = $score;
            }

            $maximumSubscaleLevel = max($maximumSubscaleLevel, $subscale['level']);

            if ($subscale['level'] === $generalLevel) {
                if ($subscale['category'] !== $scoredResult['general']['category']) {
                    throw new InvalidArgumentException('DASS-21 general category is inconsistent with its basis subscales.');
                }

                $expectedBasisScales[] = $scale;
            }
        }

        ksort($responseScores);

        if (array_keys($responseScores) !== range(1, 21)) {
            throw new InvalidArgumentException('DASS-21 scored item provenance is invalid.');
        }

        if ($maximumSubscaleLevel !== $generalLevel
            || $scoredResult['general']['basis_scales'] !== $expectedBasisScales) {
            throw new InvalidArgumentException('DASS-21 general basis scales are inconsistent with subscale levels.');
        }

        if ($generalLevel >= 4) {
            $followUpType = 'referral_support_offer';
            $supportOffer = true;
        } elseif ($generalLevel === 3) {
            $followUpType = 'monitoring';
            $supportOffer = false;
        } else {
            $followUpType = 'none';
            $supportOffer = false;
        }

        $distinctResponseValues = count(array_unique($responseScores));
        $uniformResponses = $distinctResponseValues === 1;
        $completionUnderMinimum = $completionDurationSeconds < $this->minimumCompletionSeconds;
        $activeFlagCodes = [];

        if ($uniformResponses) {
            $activeFlagCodes[] = 'uniform_responses';
        }

        if ($completionUnderMinimum) {
            $activeFlagCodes[] = 'completion_under_minimum';
        }

        return [
            'general' => [
                'category' => $scoredResult['general']['category'],
                'level' => $generalLevel,
                'basis_scales' => $expectedBasisScales,
            ],
            'follow_up' => [
                'type' => $followUpType,
                'support_offer' => $supportOffer,
            ],
            'validity_flags' => [
                'uniform_responses' => $uniformResponses,
                'completion_under_minimum' => $completionUnderMinimum,
            ],
            'active_flag_codes' => $activeFlagCodes,
            'provenance' => [
                'completion_duration_seconds' => $completionDurationSeconds,
                'minimum_completion_seconds' => $this->minimumCompletionSeconds,
                'response_count' => count($responseScores),
                'distinct_response_values' => $distinctResponseValues,
            ],
        ];
    }
}
