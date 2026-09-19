<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

final readonly class ReportIdentity
{
    /** @var list<string> */
    private const FIELD_KEYS = [
        'report_number',
        'participant_name',
        'test_number',
        'birth_date',
        'education',
        'branch_name',
        'target_field',
        'standard_version',
        'test_date',
    ];

    /** @var list<string> */
    private const TARGET_FIELDS = ['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'];

    /** @param array<string, string> $values */
    private function __construct(
        private array $values,
    ) {}

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        if (count($input) !== count(self::FIELD_KEYS)) {
            throw new InvalidArgumentException('Report identity input must contain exactly the expected fields.');
        }

        $validated = [];
        foreach (self::FIELD_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("Report identity field [{$key}] is invalid.");
            }

            $validated[$key] = $value;
        }

        if (! in_array($validated['target_field'], self::TARGET_FIELDS, true)) {
            throw new InvalidArgumentException('Report identity target field is unknown.');
        }

        return new self($validated);
    }

    public function targetField(): string
    {
        return $this->values['target_field'];
    }

    public function standardVersion(): string
    {
        return $this->values['standard_version'];
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->values;
    }
}
