<?php

declare(strict_types=1);

namespace App\Domain\Narrative;

use InvalidArgumentException;

final readonly class ReportingNarrativeCatalog
{
    /** @var list<string> */
    private const ASPECTS = [
        'A1', 'A2',
        'B1', 'B2', 'B3', 'B4',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7',
        'D1', 'D2', 'D3', 'D4', 'D5',
    ];

    /** @var list<string> */
    private const CONNECTOR_GROUPS = ['ADITIF', 'KONTRAS', 'PENUTUP'];

    /** @var list<array{key: string, aspect: string, level: int, text: string}> */
    private array $narrativeBank;

    /** @var array{additive: list<array{key: string, text: string}>, contrast: list<array{key: string, text: string}>} */
    private array $connectorPools;

    /** @var list<array{key: string, text: string}> */
    private array $endingPool;

    /**
     * @param  array<mixed>  $narratives
     * @param  array<mixed>  $connectors
     */
    public function __construct(array $narratives, array $connectors)
    {
        $this->narrativeBank = $this->adaptNarratives($narratives);
        $adaptedConnectors = $this->adaptConnectors($connectors);
        $this->connectorPools = [
            'additive' => $adaptedConnectors['ADITIF'],
            'contrast' => $adaptedConnectors['KONTRAS'],
        ];
        $this->endingPool = $adaptedConnectors['PENUTUP'];
    }

    /**
     * @return array{
     *     narrative_bank: list<array{key: string, aspect: string, level: int, text: string}>,
     *     connector_pools: array{
     *         additive: list<array{key: string, text: string}>,
     *         contrast: list<array{key: string, text: string}>
     *     }
     * }
     */
    public function assemblerInputs(): array
    {
        return [
            'narrative_bank' => $this->narrativeBank,
            'connector_pools' => $this->connectorPools,
        ];
    }

    /** @return list<array{key: string, text: string}> */
    public function endingPool(): array
    {
        return $this->endingPool;
    }

    /**
     * @param  array<mixed>  $narratives
     * @return list<array{key: string, aspect: string, level: int, text: string}>
     */
    private function adaptNarratives(array $narratives): array
    {
        if (! array_is_list($narratives)) {
            throw new InvalidArgumentException('Reporting narratives must be a list.');
        }

        $adapted = [];
        $seenKeys = [];
        $seenPairs = [];

        foreach ($narratives as $narrative) {
            if (! is_array($narrative)
                || ! $this->hasExactKeys($narrative, ['key', 'aspect', 'level', 'id', 'jp'])) {
                throw new InvalidArgumentException('Reporting narrative entry shape is invalid.');
            }

            $key = $narrative['key'];
            $aspect = $narrative['aspect'];
            $level = $narrative['level'];
            $id = $narrative['id'];
            $jp = $narrative['jp'];

            if (! $this->isNonBlankTrimmedString($key)
                || ! $this->isNonBlankTrimmedString($id)
                || ! $this->isNonBlankTrimmedString($jp)) {
                throw new InvalidArgumentException('Reporting narrative text and key must be nonblank.');
            }
            if (! is_string($aspect) || ! in_array($aspect, self::ASPECTS, true)) {
                throw new InvalidArgumentException('Reporting narrative aspect is unknown.');
            }
            if (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Reporting narrative level must be an integer from one through five.');
            }

            $pair = $this->pairKey($aspect, $level);
            if (isset($seenKeys[$key]) || isset($seenPairs[$pair])) {
                throw new InvalidArgumentException('Reporting narrative keys and aspect-level pairs must be unique.');
            }

            $seenKeys[$key] = true;
            $seenPairs[$pair] = true;
            $adapted[] = [
                'key' => $key,
                'aspect' => $aspect,
                'level' => $level,
                'text' => $id,
            ];
        }

        $expectedPairs = [];
        foreach (self::ASPECTS as $aspect) {
            for ($level = 1; $level <= 5; $level++) {
                $expectedPairs[] = $this->pairKey($aspect, $level);
            }
        }
        $actualPairs = array_keys($seenPairs);
        sort($expectedPairs);
        sort($actualPairs);
        if ($actualPairs !== $expectedPairs) {
            throw new InvalidArgumentException('Reporting narratives must contain every aspect and level exactly once.');
        }

        return $adapted;
    }

    /**
     * @param  array<mixed>  $connectors
     * @return array{
     *     ADITIF: list<array{key: string, text: string}>,
     *     KONTRAS: list<array{key: string, text: string}>,
     *     PENUTUP: list<array{key: string, text: string}>
     * }
     */
    private function adaptConnectors(array $connectors): array
    {
        if (! array_is_list($connectors)) {
            throw new InvalidArgumentException('Reporting connectors must be a list.');
        }

        $adapted = ['ADITIF' => [], 'KONTRAS' => [], 'PENUTUP' => []];
        $nextOrders = ['ADITIF' => 1, 'KONTRAS' => 1, 'PENUTUP' => 1];
        $seenTexts = [];

        foreach ($connectors as $connector) {
            if (! is_array($connector)
                || ! $this->hasExactKeys($connector, ['group', 'order', 'text'])) {
                throw new InvalidArgumentException('Reporting connector entry shape is invalid.');
            }

            $group = $connector['group'];
            $order = $connector['order'];
            $text = $connector['text'];
            if (! is_string($group) || ! in_array($group, self::CONNECTOR_GROUPS, true)) {
                throw new InvalidArgumentException('Reporting connector group is unknown.');
            }
            if (! is_int($order) || $order < 1 || $order !== $nextOrders[$group]) {
                throw new InvalidArgumentException('Reporting connector orders must be positive, contiguous, and source-ordered.');
            }
            if (! $this->isNonBlankTrimmedString($text) || isset($seenTexts[$text])) {
                throw new InvalidArgumentException('Reporting connector texts must be nonblank and unique.');
            }

            $seenTexts[$text] = true;
            $adapted[$group][] = [
                'key' => 'connector.'.$group.'.'.$order,
                'text' => $text,
            ];
            $nextOrders[$group]++;
        }

        foreach (self::CONNECTOR_GROUPS as $group) {
            if ($adapted[$group] === []) {
                throw new InvalidArgumentException('Reporting connector groups must all be present.');
            }
        }

        return $adapted;
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

    private function isNonBlankTrimmedString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && trim($value) === $value;
    }

    private function pairKey(string $aspect, int $level): string
    {
        return $aspect.'|'.$level;
    }
}
