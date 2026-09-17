<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

final readonly class RmibResultBlock
{
    /**
     * Twelve RMIB interest categories. The first five map to the HPP
     * interest aspects: D1 outdoor, D2 mechanical, D3 practical,
     * D4 medical, D5 social service.
     *
     * @var array<string, string|null>
     */
    public const CATEGORY_D_ASPECTS = [
        'out' => 'D1',
        'mech' => 'D2',
        'prac' => 'D3',
        'med' => 'D4',
        'socsvc' => 'D5',
        'aesth' => null,
        'sci' => null,
        'bus' => null,
        'cler' => null,
        'comm' => null,
        'lit' => null,
        'mus' => null,
    ];

    /** @param array<string, array{label: string, rank: int, d_aspect: string|null}> $categories */
    private function __construct(
        private array $categories,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (! isset($input['categories']) || ! is_array($input['categories'])) {
            throw new InvalidArgumentException('RMIB result block input is invalid.');
        }

        if (count($input['categories']) !== count(self::CATEGORY_D_ASPECTS)) {
            throw new InvalidArgumentException('RMIB result block must contain all twelve categories.');
        }

        $categories = [];
        $ranks = [];
        foreach (self::CATEGORY_D_ASPECTS as $code => $dAspect) {
            $row = $input['categories'][$code] ?? null;
            if (! is_array($row) || ! isset($row['label'], $row['rank'])) {
                throw new InvalidArgumentException("RMIB result category [{$code}] is invalid.");
            }

            if (! is_string($row['label']) || trim($row['label']) === '') {
                throw new InvalidArgumentException("RMIB result category [{$code}] label is invalid.");
            }

            if (! is_int($row['rank']) || $row['rank'] < 1 || $row['rank'] > 12) {
                throw new InvalidArgumentException("RMIB result category [{$code}] rank is invalid.");
            }

            $categories[$code] = ['label' => $row['label'], 'rank' => $row['rank'], 'd_aspect' => $dAspect];
            $ranks[] = $row['rank'];
        }

        sort($ranks);
        if ($ranks !== range(1, 12)) {
            throw new InvalidArgumentException('RMIB result ranks must be a permutation of 1..12.');
        }

        return new self($categories);
    }

    /**
     * Categories ordered from most to least preferred (rank 1 first).
     *
     * @return list<array{code: string, label: string, rank: int, d_aspect: string|null}>
     */
    public function rankedCategories(): array
    {
        $rows = [];
        foreach ($this->categories as $code => $category) {
            $rows[] = ['code' => $code, 'label' => $category['label'], 'rank' => $category['rank'], 'd_aspect' => $category['d_aspect']];
        }

        usort($rows, static fn (array $left, array $right): int => $left['rank'] <=> $right['rank']);

        return $rows;
    }

    /** @return array<string, array{label: string, rank: int, d_aspect: string|null}> */
    public function toArray(): array
    {
        return $this->categories;
    }
}
