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
        public ?string $followUp,
    ) {}

    /**
     * Follow-up text exists only for Sedang (pemantauan) and Parah/Sangat
     * Parah (rujukan); for Normal/Ringan it is null and the report omits
     * the line rather than inventing reassurance.
     *
     * @param  array<mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        if (count($input) !== 3 || ! array_key_exists('general_category', $input)
            || ! array_key_exists('narrative', $input) || ! array_key_exists('follow_up', $input)) {
            throw new InvalidArgumentException('DASS screening summary must contain exactly category, narrative, and follow-up.');
        }

        if (! is_string($input['general_category']) || ! in_array($input['general_category'], self::CATEGORIES, true)) {
            throw new InvalidArgumentException('DASS screening summary category is unknown.');
        }

        if (! is_string($input['narrative']) || trim($input['narrative']) === '') {
            throw new InvalidArgumentException('DASS screening summary [narrative] is invalid.');
        }

        $followUp = $input['follow_up'];
        if ($followUp !== null && (! is_string($followUp) || trim($followUp) === '')) {
            throw new InvalidArgumentException('DASS screening summary [follow_up] is invalid.');
        }

        return new self($input['general_category'], $input['narrative'], $followUp);
    }

    /** @return array{general_category: string, narrative: string, follow_up: string|null} */
    public function toArray(): array
    {
        return [
            'general_category' => $this->generalCategory,
            'narrative' => $this->narrative,
            'follow_up' => $this->followUp,
        ];
    }
}
