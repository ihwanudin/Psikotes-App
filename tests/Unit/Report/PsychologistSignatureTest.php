<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\PsychologistSignature;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PsychologistSignatureTest extends TestCase
{
    public function test_null_input_yields_no_signature_block(): void
    {
        $this->assertNull(PsychologistSignature::fromNullable(null));
    }

    public function test_valid_block_round_trips(): void
    {
        $signature = PsychologistSignature::fromNullable([
            'name' => 'Dewi Kartika, S.Psi.',
            'sipp_number' => 'SIPP-00000000',
            'signature_note' => 'Catatan tinjauan.',
            'signed_at' => null,
        ]);

        $this->assertSame([
            'name' => 'Dewi Kartika, S.Psi.',
            'sipp_number' => 'SIPP-00000000',
            'signature_note' => 'Catatan tinjauan.',
            'signed_at' => null,
        ], $signature?->toArray());
    }

    public function test_missing_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PsychologistSignature::fromNullable([
            'name' => 'Dewi Kartika, S.Psi.',
            'signature_note' => null,
            'signed_at' => null,
        ]);
    }

    public function test_blank_licence_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PsychologistSignature::fromNullable([
            'name' => 'Dewi Kartika, S.Psi.',
            'sipp_number' => ' ',
            'signature_note' => null,
            'signed_at' => null,
        ]);
    }

    public function test_whitespace_note_is_rejected_but_null_is_allowed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PsychologistSignature::fromNullable([
            'name' => 'Dewi Kartika, S.Psi.',
            'sipp_number' => 'SIPP-00000000',
            'signature_note' => ' ',
            'signed_at' => null,
        ]);
    }
}
