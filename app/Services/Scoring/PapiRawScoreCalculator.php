<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class PapiRawScoreCalculator
{
    /** @var array<int, array{type: string, a: string, b: string}> */
    private array $mapping;

    /** @var array<string, array{type: string, opportunities: int}> */
    private array $dimensions;

    /** @param array<mixed> $mapping */
    public function __construct(array $mapping)
    {
        if (! array_is_list($mapping) || $mapping === []) {
            throw new InvalidArgumentException('PAPI mapping must be a non-empty list.');
        }

        $items = [];
        $dimensionTypes = [];
        $opportunities = [];
        $typeItemCounts = ['ROLE' => 0, 'NEED' => 0];

        foreach ($mapping as $definition) {
            if (! is_array($definition)
                || ! isset($definition['item'], $definition['type'], $definition['a'], $definition['b'])
                || ! is_int($definition['item'])
                || $definition['item'] < 1
                || ! is_string($definition['type'])
                || ! in_array($definition['type'], ['ROLE', 'NEED'], true)
                || ! is_string($definition['a'])
                || $definition['a'] === ''
                || ! is_string($definition['b'])
                || $definition['b'] === ''
                || $definition['a'] === $definition['b']) {
                throw new InvalidArgumentException('PAPI mapping item is invalid.');
            }

            if (array_key_exists($definition['item'], $items)) {
                throw new InvalidArgumentException('PAPI mapping item is duplicated.');
            }

            $items[$definition['item']] = [
                'type' => $definition['type'],
                'a' => $definition['a'],
                'b' => $definition['b'],
            ];
            $typeItemCounts[$definition['type']]++;

            foreach (['a', 'b'] as $choice) {
                $dimension = $definition[$choice];

                if (isset($dimensionTypes[$dimension]) && $dimensionTypes[$dimension] !== $definition['type']) {
                    throw new InvalidArgumentException('PAPI dimension must belong to exactly one score type.');
                }

                $dimensionTypes[$dimension] = $definition['type'];
                $opportunities[$dimension] = ($opportunities[$dimension] ?? 0) + 1;
            }
        }

        $itemDomain = array_keys($items);
        sort($itemDomain);

        if ($itemDomain !== range(1, count($items))) {
            throw new InvalidArgumentException('PAPI mapping item domain must be contiguous from one.');
        }

        if (count($dimensionTypes) !== 20) {
            throw new InvalidArgumentException('PAPI mapping must define exactly twenty dimensions.');
        }

        foreach ($opportunities as $count) {
            if ($count !== 9) {
                throw new InvalidArgumentException('Each PAPI dimension must have exactly nine scoring opportunities.');
            }
        }

        if ($typeItemCounts['ROLE'] !== $typeItemCounts['NEED']) {
            throw new InvalidArgumentException('PAPI mapping must balance ROLE and NEED items.');
        }

        $dimensions = [];

        foreach ($dimensionTypes as $dimension => $type) {
            $dimensions[$dimension] = [
                'type' => $type,
                'opportunities' => $opportunities[$dimension],
            ];
        }

        $this->mapping = $items;
        $this->dimensions = $dimensions;
    }

    /**
     * @param  array<mixed>  $responses
     * @return array{
     *     dimensions: array<string, array{raw_score: int, type: string, opportunities: int}>,
     *     type_totals: array{ROLE: int, NEED: int},
     *     response_count: int
     * }
     */
    public function calculate(array $responses): array
    {
        $scores = array_fill_keys(array_keys($this->dimensions), 0);
        $typeTotals = ['ROLE' => 0, 'NEED' => 0];
        $seen = [];

        foreach ($responses as $response) {
            if (! is_array($response)
                || ! isset($response['item'], $response['choice'])
                || ! is_int($response['item'])
                || ! is_string($response['choice'])) {
                throw new InvalidArgumentException('PAPI response must contain an integer item and string choice.');
            }

            if (! in_array($response['choice'], ['a', 'b'], true)) {
                throw new InvalidArgumentException('PAPI response choice must be exactly a or b.');
            }

            if (array_key_exists($response['item'], $seen)) {
                throw new InvalidArgumentException('PAPI response item is duplicated.');
            }

            if (! array_key_exists($response['item'], $this->mapping)) {
                throw new InvalidArgumentException('PAPI response item is outside the supplied mapping.');
            }

            $item = $this->mapping[$response['item']];
            $scores[$item[$response['choice']]]++;
            $typeTotals[$item['type']]++;
            $seen[$response['item']] = true;
        }

        if (count($seen) !== count($this->mapping)) {
            throw new InvalidArgumentException('PAPI responses must contain every configured item exactly once.');
        }

        $dimensions = [];

        foreach ($this->dimensions as $dimension => $provenance) {
            $dimensions[$dimension] = [
                'raw_score' => $scores[$dimension],
                'type' => $provenance['type'],
                'opportunities' => $provenance['opportunities'],
            ];
        }

        return [
            'dimensions' => $dimensions,
            'type_totals' => $typeTotals,
            'response_count' => count($seen),
        ];
    }
}
