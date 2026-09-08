<?php

declare(strict_types=1);

namespace App\Domain\Narrative;

use InvalidArgumentException;

/**
 * @phpstan-type DevelopmentArea array{aspect: string, zone: 'GREY'|'BELUM', source_position: int}
 */
final readonly class IntegrationDevelopmentAreaOrder
{
    /** @var list<string> */
    private const ASPECTS = [
        'A1', 'A2',
        'B1', 'B2', 'B3', 'B4',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7',
    ];

    /** @var array{A: float, B: float, C: float} */
    private array $clusterPriorities;

    /** @param array<mixed> $clusterPriorities */
    public function __construct(array $clusterPriorities)
    {
        if ($clusterPriorities !== ['A' => 0.3, 'B' => 0.3, 'C' => 0.4]) {
            throw new InvalidArgumentException('Integration cluster priorities are invalid.');
        }

        $this->clusterPriorities = $clusterPriorities;
    }

    /**
     * @param  array<mixed>  $aspects
     * @return array{
     *     type: 'integration_development_area_order',
     *     ordered_aspects: list<DevelopmentArea>,
     *     review_required: bool,
     *     omitted_aspects: list<DevelopmentArea>
     * }
     */
    public function order(array $aspects): array
    {
        $validated = $this->validateAspects($aspects);
        $ordered = [];
        $omitted = [];

        foreach ($validated as $position => $item) {
            if ($item['zone'] === 'OK') {
                continue;
            }

            $area = [
                'aspect' => $item['aspect'],
                'zone' => $item['zone'],
                'source_position' => $position + 1,
            ];
            if ($item['review_required']) {
                $omitted[] = $area;

                continue;
            }

            $ordered[] = $area;
        }

        usort($ordered, function (array $left, array $right): int {
            $zoneComparison = $this->zoneOrder($left['zone']) <=> $this->zoneOrder($right['zone']);
            if ($zoneComparison !== 0) {
                return $zoneComparison;
            }

            $leftPriority = $this->clusterPriorities[$left['aspect'][0]];
            $rightPriority = $this->clusterPriorities[$right['aspect'][0]];
            $priorityComparison = $rightPriority <=> $leftPriority;

            return $priorityComparison !== 0
                ? $priorityComparison
                : strcmp($left['aspect'], $right['aspect']);
        });

        return [
            'type' => 'integration_development_area_order',
            'ordered_aspects' => $ordered,
            'review_required' => $omitted !== [],
            'omitted_aspects' => $omitted,
        ];
    }

    /**
     * @param  array<mixed>  $aspects
     * @return list<array{aspect: string, zone: 'OK'|'GREY'|'BELUM', review_required: bool}>
     */
    private function validateAspects(array $aspects): array
    {
        if (! array_is_list($aspects) || count($aspects) !== count(self::ASPECTS)) {
            throw new InvalidArgumentException('Integration development areas require all A-C aspects exactly once.');
        }

        $validated = [];
        foreach ($aspects as $position => $item) {
            if (! is_array($item) || ! $this->hasExactKeys($item, ['aspect', 'zone', 'review_required'])) {
                throw new InvalidArgumentException('Integration development area shape is invalid.');
            }

            $aspect = $item['aspect'];
            $zone = $item['zone'];
            $reviewRequired = $item['review_required'];
            if (! is_string($aspect) || $aspect !== self::ASPECTS[$position]) {
                throw new InvalidArgumentException('Integration development areas must use canonical A-C order.');
            }
            if (! is_string($zone) || ! in_array($zone, ['OK', 'GREY', 'BELUM'], true)) {
                throw new InvalidArgumentException('Integration development area zone is invalid.');
            }
            if (! is_bool($reviewRequired)) {
                throw new InvalidArgumentException('Integration development area review flag must be boolean.');
            }

            /** @var 'OK'|'GREY'|'BELUM' $zone */
            $validated[] = [
                'aspect' => $aspect,
                'zone' => $zone,
                'review_required' => $reviewRequired,
            ];
        }

        return $validated;
    }

    /** @param 'GREY'|'BELUM' $zone */
    private function zoneOrder(string $zone): int
    {
        return $zone === 'BELUM' ? 0 : 1;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }
}
