<?php

declare(strict_types=1);

namespace App\Domain\Report;

use InvalidArgumentException;

/**
 * Optional psychologist block. When present the report carries the signing
 * psychologist's identity and the five Template HPP v2.3 Bagian I.C fields
 * (Nama, SILP, STR, Fasilitas Layanan Psikologi, Alamat Fasilitas); when
 * absent the document stays in the legacy unsigned draft format.
 */
final readonly class PsychologistSignature
{
    /**
     * @param  array{
     *     name: string,
     *     silp_number: string,
     *     str_number: string,
     *     facility_name: string,
     *     facility_address: string,
     *     signature_note: string|null,
     *     signed_at: string|null,
     * }  $values
     */
    private function __construct(
        private array $values,
    ) {}

    /** @param array<mixed>|null $input */
    public static function fromNullable(?array $input): ?self
    {
        if ($input === null) {
            return null;
        }

        $requiredKeys = ['name', 'silp_number', 'str_number', 'facility_name', 'facility_address', 'signature_note', 'signed_at'];
        if (count($input) !== count($requiredKeys)) {
            throw new InvalidArgumentException('Psychologist signature block must contain exactly the expected fields.');
        }

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $input)) {
                throw new InvalidArgumentException('Psychologist signature block must contain exactly the expected fields.');
            }
        }

        foreach (['name', 'silp_number', 'str_number', 'facility_name', 'facility_address'] as $key) {
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
            'silp_number' => $input['silp_number'],
            'str_number' => $input['str_number'],
            'facility_name' => $input['facility_name'],
            'facility_address' => $input['facility_address'],
            'signature_note' => $input['signature_note'],
            'signed_at' => $input['signed_at'],
        ]);
    }

    /**
     * @return array{
     *     name: string,
     *     silp_number: string,
     *     str_number: string,
     *     facility_name: string,
     *     facility_address: string,
     *     signature_note: string|null,
     *     signed_at: string|null,
     * }
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
