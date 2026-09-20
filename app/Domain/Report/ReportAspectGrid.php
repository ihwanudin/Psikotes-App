<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

final readonly class ReportAspectGrid
{
    /** @var list<string> */
    public const ASPECTS = [
        'A1', 'A2',
        'B1', 'B2', 'B3', 'B4',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7',
        'D1', 'D2', 'D3', 'D4', 'D5',
    ];

    /** @param list<AspectLevelEntry> $entries */
    private function __construct(
        private array $entries,
    ) {}

    /**
     * Untyped at the boundary on purpose: this is a public factory and the
     * row shape is validated here, not merely declared, so a caller passing
     * malformed data gets InvalidArgumentException rather than a TypeError
     * from AspectLevelEntry::create()'s native-typed parameters.
     *
     * @param  array<mixed>  $rows
     */
    public static function fromArray(array $rows): self
    {
        $entries = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || ! isset($row['code'], $row['label_id'], $row['label_jp'], $row['level'])
                || ! is_string($row['code'])
                || ! is_string($row['label_id'])
                || ! is_string($row['label_jp'])
                || ! is_int($row['level'])
                || (array_key_exists('standard', $row) && $row['standard'] !== null && ! is_int($row['standard']))) {
                throw new InvalidArgumentException('Aspect grid row is invalid.');
            }

            $entries[] = AspectLevelEntry::create(
                $row['code'],
                $row['label_id'],
                $row['label_jp'],
                $row['level'],
                $row['standard'] ?? null,
            );
        }

        return self::fromEntries($entries);
    }

    /** @param list<AspectLevelEntry> $entries */
    public static function fromEntries(array $entries): self
    {
        $codes = array_map(static fn (AspectLevelEntry $entry): string => $entry->code, $entries);

        if ($codes !== self::ASPECTS) {
            throw new InvalidArgumentException('Aspect grid must contain all 18 canonical aspects in order.');
        }

        return new self($entries);
    }

    /** @return array{OK: int, GREY: int, BELUM: int} */
    public function zoneCounts(): array
    {
        $counts = ['OK' => 0, 'GREY' => 0, 'BELUM' => 0];
        foreach ($this->entries as $entry) {
            $zone = $entry->zone();
            if ($zone !== null) {
                $counts[$zone]++;
            }
        }

        return $counts;
    }

    /** @return list<string> */
    public function criticalBelumAspects(): array
    {
        $codes = [];
        foreach ($this->entries as $entry) {
            if ($entry->isCritical() && $entry->zone() === AspectLevelEntry::ZONE_BELUM) {
                $codes[] = $entry->code;
            }
        }

        return $codes;
    }

    /** @return list<array{label_id: string, label_jp: string, level: int, zone: string|null}> */
    public function hppRows(): array
    {
        return array_map(static fn (AspectLevelEntry $entry): array => $entry->toHppRow(), $this->entries);
    }

    /** @return list<array{code: string, cluster: string, label_id: string, label_jp: string, level: int, standard: int|null, zone: string|null, critical: bool}> */
    public function toArray(): array
    {
        return array_map(static fn (AspectLevelEntry $entry): array => $entry->toArray(), $this->entries);
    }
}
