<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

/**
 * Participant-facing DASS-21 projection. The HPP may only carry the general
 * category, its narrative, and the follow-up offer — subscale scores are
 * internal-only material and this class structurally refuses them.
 */
final readonly class DassScreeningSummary
{
    /** @var list<string> */
    public const CATEGORIES = ['Normal', 'Ringan', 'Sedang', 'Parah', 'Sangat Parah'];

    private function __construct(
        public string $generalCategory,
        public string $narrative,
        public string $followUp,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (count($input) !== 3 || ! isset($input['general_category'], $input['narrative'], $input['follow_up'])) {
            throw new InvalidArgumentException('DASS screening summary must contain exactly category, narrative, and follow-up.');
        }

        if (! is_string($input['general_category']) || ! in_array($input['general_category'], self::CATEGORIES, true)) {
            throw new InvalidArgumentException('DASS screening summary category is unknown.');
        }

        foreach (['narrative', 'follow_up'] as $key) {
            if (! is_string($input[$key]) || trim($input[$key]) === '') {
                throw new InvalidArgumentException("DASS screening summary [{$key}] is invalid.");
            }
        }

        return new self($input['general_category'], $input['narrative'], $input['follow_up']);
    }

    /** @return array{general_category: string, narrative: string, follow_up: string} */
    public function toArray(): array
    {
        return [
            'general_category' => $this->generalCategory,
            'narrative' => $this->narrative,
            'follow_up' => $this->followUp,
        ];
    }
}
