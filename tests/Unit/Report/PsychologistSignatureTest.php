<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\PsychologistSignature;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PsychologistSignatureTest extends TestCase
{
    /** @return array{name: string, silp_number: string, str_number: string, facility_name: string, facility_address: string, signature_note: string|null, signed_at: string|null} */
    private function validInput(array $overrides = []): array
    {
        return [...[
            'name' => 'Dewi Kartika, S.Psi.',
            'silp_number' => 'SILP-00000000',
            'str_number' => 'STR-00000000',
            'facility_name' => 'Unit Layanan Psikologi — PT Online Career Mentor',
            'facility_address' => 'Salatiga, Jawa Tengah, Indonesia',
            'signature_note' => 'Catatan tinjauan.',
            'signed_at' => null,
        ], ...$overrides];
    }

    public function test_null_input_yields_no_signature_block(): void
    {
        $this->assertNull(PsychologistSignature::fromNullable(null));
    }

    public function test_valid_block_round_trips(): void
    {
        $input = $this->validInput();
        $signature = PsychologistSignature::fromNullable($input);

        $this->assertSame($input, $signature?->toArray());
    }

    public function test_missing_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $input = $this->validInput();
        unset($input['silp_number']);
        PsychologistSignature::fromNullable($input);
    }

    public function test_blank_silp_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PsychologistSignature::fromNullable($this->validInput(['silp_number' => ' ']));
    }

    public function test_blank_str_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PsychologistSignature::fromNullable($this->validInput(['str_number' => ' ']));
    }

    public function test_blank_facility_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PsychologistSignature::fromNullable($this->validInput(['facility_name' => ' ']));
    }

    public function test_blank_facility_address_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PsychologistSignature::fromNullable($this->validInput(['facility_address' => ' ']));
    }

    public function test_whitespace_note_is_rejected_but_null_is_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PsychologistSignature::fromNullable($this->validInput(['signature_note' => ' ']));
    }
}
