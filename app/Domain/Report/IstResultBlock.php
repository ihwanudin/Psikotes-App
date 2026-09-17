<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

final readonly class IstResultBlock
{
    /** @var list<string> */
    public const SUBTESTS = ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'];

    /** @param array<string, array{sw: int, level: int, label: string}> $subtests */
    private function __construct(
        public int $iq,
        public string $iqCategory,
        private array $subtests,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (! isset($input['iq'], $input['iq_category'], $input['subtests']) || ! is_array($input['subtests'])) {
            throw new InvalidArgumentException('IST result block input is invalid.');
        }

        if (! is_int($input['iq']) || $input['iq'] < 40 || $input['iq'] > 160) {
            throw new InvalidArgumentException('IST result block IQ is invalid.');
        }

        if (! is_string($input['iq_category']) || trim($input['iq_category']) === '') {
            throw new InvalidArgumentException('IST result block IQ category is invalid.');
        }

        if (count($input['subtests']) !== count(self::SUBTESTS)) {
            throw new InvalidArgumentException('IST result block must contain all nine subtests.');
        }

        $subtests = [];
        foreach (self::SUBTESTS as $code) {
            $row = $input['subtests'][$code] ?? null;
            if (! is_array($row) || ! isset($row['sw'], $row['level'], $row['label'])) {
                throw new InvalidArgumentException("IST result subtest [{$code}] is invalid.");
            }

            if (! is_int($row['sw']) || ! is_int($row['level']) || $row['level'] < 1 || $row['level'] > 5) {
                throw new InvalidArgumentException("IST result subtest [{$code}] score or level is invalid.");
            }

            if (! is_string($row['label']) || trim($row['label']) === '') {
                throw new InvalidArgumentException("IST result subtest [{$code}] label is invalid.");
            }

            $subtests[$code] = ['sw' => $row['sw'], 'level' => $row['level'], 'label' => $row['label']];
        }

        return new self($input['iq'], $input['iq_category'], $subtests);
    }

    /** @return array{iq: int, iq_category: string, subtests: array<string, array{sw: int, level: int, label: string}>} */
    public function toArray(): array
    {
        return [
            'iq' => $this->iq,
            'iq_category' => $this->iqCategory,
            'subtests' => $this->subtests,
        ];
    }
}
