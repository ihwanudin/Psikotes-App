<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

/**
 * Internal-sheet DASS-21 detail: raw, doubled (DASS-42 equivalent), and
 * category per subscale. Never projected into the participant HPP.
 */
final readonly class DassInternalDetail
{
    /** @var list<string> */
    public const SUBSCALES = ['d', 'a', 's'];

    /** @var list<string> */
    private const CATEGORIES = ['Normal', 'Ringan', 'Sedang', 'Parah', 'Sangat Parah'];

    /**
     * @param  array<string, array{raw: int, doubled: int, category: string}>  $subscales
     * @param  list<string>  $flags
     */
    private function __construct(
        private array $subscales,
        public string $generalCategory,
        public string $followUp,
        private array $flags,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (count($input) !== 4 || ! isset($input['subscales'], $input['general_category'], $input['follow_up'], $input['flags'])) {
            throw new InvalidArgumentException('DASS internal detail input is invalid.');
        }

        if (! is_array($input['subscales']) || count($input['subscales']) !== count(self::SUBSCALES)) {
            throw new InvalidArgumentException('DASS internal detail must contain all three subscales.');
        }

        $subscales = [];
        foreach (self::SUBSCALES as $code) {
            $row = $input['subscales'][$code] ?? null;
            if (! is_array($row) || ! isset($row['raw'], $row['doubled'], $row['category'])) {
                throw new InvalidArgumentException("DASS internal subscale [{$code}] is invalid.");
            }

            if (! is_int($row['raw']) || $row['raw'] < 0 || $row['raw'] > 21) {
                throw new InvalidArgumentException("DASS internal subscale [{$code}] raw score is invalid.");
            }

            if (! is_int($row['doubled']) || $row['doubled'] !== $row['raw'] * 2) {
                throw new InvalidArgumentException("DASS internal subscale [{$code}] doubled score must equal raw x 2.");
            }

            if (! is_string($row['category']) || ! in_array($row['category'], self::CATEGORIES, true)) {
                throw new InvalidArgumentException("DASS internal subscale [{$code}] category is unknown.");
            }

            $subscales[$code] = ['raw' => $row['raw'], 'doubled' => $row['doubled'], 'category' => $row['category']];
        }

        if (! is_string($input['general_category']) || ! in_array($input['general_category'], self::CATEGORIES, true)) {
            throw new InvalidArgumentException('DASS internal detail general category is unknown.');
        }

        if (! is_string($input['follow_up']) || trim($input['follow_up']) === '') {
            throw new InvalidArgumentException('DASS internal detail follow-up is invalid.');
        }

        $flags = [];
        foreach ($input['flags'] as $flag) {
            if (! is_string($flag) || trim($flag) === '') {
                throw new InvalidArgumentException('DASS internal detail flags are invalid.');
            }

            $flags[] = $flag;
        }

        return new self($subscales, $input['general_category'], $input['follow_up'], $flags);
    }

    /** @return array{subscales: array<string, array{raw: int, doubled: int, category: string}>, general_category: string, follow_up: string, flags: list<string>} */
    public function toArray(): array
    {
        return [
            'subscales' => $this->subscales,
            'general_category' => $this->generalCategory,
            'follow_up' => $this->followUp,
            'flags' => $this->flags,
        ];
    }
}
