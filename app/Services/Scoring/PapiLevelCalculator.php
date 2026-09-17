<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class PapiLevelCalculator
{
    /** @var array<string, array{0: int, 1: int}> */
    private array $whiteZones;

    /** @var array<int, int> */
    private array $distanceToLevel;

    /** @var list<string> */
    private array $excludedFromHpp;

    /**
     * @param  array<mixed>  $whiteZones
     * @param  array<mixed>  $normalization
     */
    public function __construct(array $whiteZones, array $normalization)
    {
        if (count($whiteZones) !== 20
            || ($normalization['method'] ?? null) !== 'distance_from_white_zone'
            || ! isset($normalization['version'], $normalization['distance_to_level'], $normalization['excluded_from_hpp'])
            || ! is_string($normalization['version'])
            || ! is_array($normalization['distance_to_level'])
            || ! is_array($normalization['excluded_from_hpp'])) {
            throw new InvalidArgumentException('PAPI normalization configuration is invalid.');
        }

        $validatedZones = [];
        foreach ($whiteZones as $dimension => $zone) {
            if (! is_string($dimension)
                || $dimension === ''
                || ! is_array($zone)
                || ! array_is_list($zone)
                || count($zone) !== 2
                || ! is_int($zone[0])
                || ! is_int($zone[1])
                || $zone[0] < 0
                || $zone[1] > 9
                || $zone[0] > $zone[1]) {
                throw new InvalidArgumentException('PAPI white-zone definition is invalid.');
            }
            $validatedZones[$dimension] = [$zone[0], $zone[1]];
        }

        $distanceToLevel = [];
        foreach (range(0, 4) as $distance) {
            $key = (string) $distance;
            if (! isset($normalization['distance_to_level'][$key]) || ! is_int($normalization['distance_to_level'][$key])) {
                throw new InvalidArgumentException('PAPI distance-to-level mapping is invalid.');
            }
            $distanceToLevel[$distance] = $normalization['distance_to_level'][$key];
        }

        $configuredLevels = $distanceToLevel;
        $uniqueLevels = array_unique($configuredLevels);
        sort($uniqueLevels);
        if ($uniqueLevels !== range(1, 5)
            || $configuredLevels !== array_reverse($uniqueLevels)) {
            throw new InvalidArgumentException('PAPI distance-to-level mapping must define all five levels in descending order.');
        }

        $excluded = array_values($normalization['excluded_from_hpp']);
        if (count($excluded) !== 4
            || count(array_unique($excluded)) !== 4
            || array_filter($excluded, static fn (mixed $dimension): bool => ! is_string($dimension) || ! array_key_exists($dimension, $validatedZones)) !== []) {
            throw new InvalidArgumentException('PAPI HPP exclusion list is invalid.');
        }

        $this->whiteZones = $validatedZones;
        $this->distanceToLevel = $distanceToLevel;
        $this->excludedFromHpp = $excluded;
    }

    /** @return array{dimension: string, raw_score: int, white_zone: array{0: int, 1: int}, distance: int, level: int, hpp_excluded: bool} */
    public function calculate(mixed $dimension, mixed $rawScore): array
    {
        if (! is_string($dimension) || ! array_key_exists($dimension, $this->whiteZones)) {
            throw new InvalidArgumentException('PAPI dimension is not configured.');
        }
        if (! is_int($rawScore) || $rawScore < 0 || $rawScore > 9) {
            throw new InvalidArgumentException('PAPI raw score must be an integer from zero through nine.');
        }

        [$lo, $hi] = $this->whiteZones[$dimension];
        $distance = $rawScore < $lo ? $lo - $rawScore : ($rawScore > $hi ? $rawScore - $hi : 0);

        return [
            'dimension' => $dimension,
            'raw_score' => $rawScore,
            'white_zone' => [$lo, $hi],
            'distance' => $distance,
            'level' => $this->distanceToLevel[min($distance, 4)],
            'hpp_excluded' => in_array($dimension, $this->excludedFromHpp, true),
        ];
    }
}
