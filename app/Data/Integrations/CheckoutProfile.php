<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use DomainException;
use JsonSerializable;

/** Immutable profile facts only, not a complete summary, form contract, or authorization proof. */
final readonly class CheckoutProfile implements JsonSerializable
{
    public function __construct(
        public ?string $fullName,
        public ?string $birthDate,
        public ?string $gender,
        public ?string $educationLevel,
        public ?string $intendedField,
        public ?string $email,
        public ?string $phone,
    ) {}

    /** @return array{profile: list<array{key: string, label: string, state: 'missing'|'locked', required: bool, displayValue?: string}>} */
    public function toArray(): array
    {
        $gender = match ($this->gender) {
            null => null, 'female' => 'Perempuan', 'male' => 'Laki-laki',
            default => throw new DomainException('CHECKOUT_PROFILE_UNAVAILABLE'),
        };
        $field = match ($this->intendedField) {
            null => null, 'KAIGO' => 'Kaigo / perawatan', 'KENSETSU' => 'Kensetsu / konstruksi',
            'NOUGYOU' => 'Nougyou / pertanian', 'SEIZOU' => 'Seizou / manufaktur',
            'GAISHOKU' => 'Gaishoku / layanan makanan', 'UMUM' => 'Umum',
            default => throw new DomainException('CHECKOUT_PROFILE_UNAVAILABLE'),
        };

        return ['profile' => [
            $this->field('fullName', 'Nama lengkap', $this->fullName),
            $this->field('birthDate', 'Tanggal lahir', $this->birthDate),
            $this->field('gender', 'Jenis kelamin', $gender),
            $this->field('educationLevel', 'Pendidikan terakhir', $this->educationLevel),
            $this->field('intendedField', 'Bidang tujuan', $field),
            $this->field('email', 'Email', $this->email, false),
            $this->field('phone', 'Nomor telepon', $this->phone),
        ]];
    }

    /** @return array{profile: list<array{key: string, label: string, state: 'missing'|'locked', required: bool, displayValue?: string}>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array{key: string, label: string, state: 'missing'|'locked', required: bool, displayValue?: string} */
    private function field(string $key, string $label, ?string $value, bool $required = true): array
    {
        $field = ['key' => $key, 'label' => $label, 'state' => $value === null ? 'missing' : 'locked', 'required' => $required];
        if ($value !== null) {
            $field['displayValue'] = $value;
        }

        return $field;
    }
}
