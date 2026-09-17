<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

/**
 * Optional psychologist block. When present the report carries the signing
 * psychologist's identity and SIPP licence; when absent the document stays
 * in the legacy unsigned draft format.
 */
final readonly class PsychologistSignature
{
    /** @param array{name: string, sipp_number: string, signature_note: string|null, signed_at: string|null} $values */
    private function __construct(
        private array $values,
    ) {}

    /** @param array<mixed>|null $input */
    public static function fromNullable(?array $input): ?self
    {
        if ($input === null) {
            return null;
        }

        if (count($input) !== 4
            || ! array_key_exists('name', $input)
            || ! array_key_exists('sipp_number', $input)
            || ! array_key_exists('signature_note', $input)
            || ! array_key_exists('signed_at', $input)) {
            throw new InvalidArgumentException('Psychologist signature block must contain exactly the expected fields.');
        }

        foreach (['name', 'sipp_number'] as $key) {
            if (! is_string($input[$key]) || trim($input[$key]) === '') {
                throw new InvalidArgumentException("Psychologist signature [{$key}] is invalid.");
            }
        }

        foreach (['signature_note', 'signed_at'] as $key) {
            $value = $input[$key];
            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                throw new InvalidArgumentException("Psychologist signature [{$key}] is invalid.");
            }
        }

        return new self([
            'name' => $input['name'],
            'sipp_number' => $input['sipp_number'],
            'signature_note' => $input['signature_note'],
            'signed_at' => $input['signed_at'],
        ]);
    }

    /** @return array{name: string, sipp_number: string, signature_note: string|null, signed_at: string|null} */
    public function toArray(): array
    {
        return $this->values;
    }
}
