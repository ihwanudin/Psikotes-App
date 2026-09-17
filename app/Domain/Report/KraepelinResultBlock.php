<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

final readonly class KraepelinResultBlock
{
    /** @var list<string> */
    public const FACTORS = ['panker', 'tianker', 'hanker', 'janker'];

    /** @param array<string, array{value: string, level: int, band: string}> $factors */
    private function __construct(
        public string $normGroup,
        private array $factors,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (! isset($input['norm_group'], $input['factors']) || ! is_array($input['factors'])) {
            throw new InvalidArgumentException('Kraepelin result block input is invalid.');
        }

        if (! is_string($input['norm_group']) || trim($input['norm_group']) === '') {
            throw new InvalidArgumentException('Kraepelin result block norm group is invalid.');
        }

        if (count($input['factors']) !== count(self::FACTORS)) {
            throw new InvalidArgumentException('Kraepelin result block must contain all four factors.');
        }

        $factors = [];
        foreach (self::FACTORS as $code) {
            $row = $input['factors'][$code] ?? null;
            if (! is_array($row) || ! isset($row['value'], $row['level'], $row['band'])) {
                throw new InvalidArgumentException("Kraepelin result factor [{$code}] is invalid.");
            }

            if (! is_string($row['value']) || trim($row['value']) === '') {
                throw new InvalidArgumentException("Kraepelin result factor [{$code}] value is invalid.");
            }

            if (! is_int($row['level']) || $row['level'] < 1 || $row['level'] > 5) {
                throw new InvalidArgumentException("Kraepelin result factor [{$code}] level is invalid.");
            }

            if (! is_string($row['band']) || trim($row['band']) === '') {
                throw new InvalidArgumentException("Kraepelin result factor [{$code}] band is invalid.");
            }

            $factors[$code] = ['value' => $row['value'], 'level' => $row['level'], 'band' => $row['band']];
        }

        return new self($input['norm_group'], $factors);
    }

    /** @return array{norm_group: string, factors: array<string, array{value: string, level: int, band: string}>} */
    public function toArray(): array
    {
        return [
            'norm_group' => $this->normGroup,
            'factors' => $this->factors,
        ];
    }
}
