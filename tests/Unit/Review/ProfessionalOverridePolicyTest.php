<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Review\ProfessionalOverridePolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfessionalOverridePolicyTest extends TestCase
{
    public function test_changed_level_preserves_system_and_final_values_and_requires_recalculation(): void
    {
        $result = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'C4',
            'system_level' => 2,
            'final_level' => 4,
            'reason' => '  Observasi profesional mendukung penyesuaian level.  ',
        ]);

        self::assertSame([
            'type' => 'professional_override',
            'changed' => true,
            'audit_required' => true,
            'recalculation_required' => true,
            'system_level' => 2,
            'final_level' => 4,
            'reason' => 'Observasi profesional mendukung penyesuaian level.',
            'provenance' => [
                'policy' => 'G6',
                'override_type' => 'level',
                'aspect' => 'C4',
                'reason_character_count' => 50,
            ],
        ], $result);
    }

    public function test_changed_label_preserves_system_and_final_values_without_level_recalculation(): void
    {
        $result = (new ProfessionalOverridePolicy)->labelOverride([
            'system_label' => 'DISARANKAN',
            'final_label' => 'DIPERTIMBANGKAN',
            'reason' => 'Pertimbangan profesional membutuhkan pendampingan.',
        ]);

        self::assertSame([
            'type' => 'professional_override',
            'changed' => true,
            'audit_required' => true,
            'recalculation_required' => false,
            'system_label' => 'DISARANKAN',
            'final_label' => 'DIPERTIMBANGKAN',
            'reason' => 'Pertimbangan profesional membutuhkan pendampingan.',
            'provenance' => [
                'policy' => 'G6',
                'override_type' => 'label',
                'aspect' => null,
                'reason_character_count' => 50,
            ],
        ], $result);
    }

    public function test_unchanged_values_canonicalize_null_or_blank_reason_as_a_no_op(): void
    {
        $policy = new ProfessionalOverridePolicy;

        $level = $policy->levelOverride([
            'aspect' => 'A1',
            'system_level' => 3,
            'final_level' => 3,
            'reason' => " \t\u{3000}\n ",
        ]);
        $label = $policy->labelOverride([
            'system_label' => 'TIDAK_DISARANKAN',
            'final_label' => 'TIDAK_DISARANKAN',
            'reason' => null,
        ]);

        foreach ([$level, $label] as $result) {
            self::assertFalse($result['changed']);
            self::assertFalse($result['audit_required']);
            self::assertFalse($result['recalculation_required']);
            self::assertNull($result['reason']);
            self::assertSame(0, $result['provenance']['reason_character_count']);
        }
    }

    #[DataProvider('reasonBoundaries')]
    public function test_changed_override_reason_uses_trimmed_unicode_character_boundary(
        string $reason,
        bool $accepted,
        ?string $canonicalReason,
        ?int $characterCount,
    ): void {
        $input = [
            'aspect' => 'D5',
            'system_level' => 2,
            'final_level' => 3,
            'reason' => $reason,
        ];

        if (! $accepted) {
            $this->expectException(InvalidArgumentException::class);
        }

        $result = (new ProfessionalOverridePolicy)->levelOverride($input);

        if ($accepted) {
            self::assertSame($canonicalReason, $result['reason']);
            self::assertSame($characterCount, $result['provenance']['reason_character_count']);
        }
    }

    /** @return iterable<string, array{string, bool, string|null, int|null}> */
    public static function reasonBoundaries(): iterable
    {
        yield '19 ASCII characters rejected' => ['1234567890123456789', false, null, null];
        yield '20 ASCII characters accepted after trim' => ['  12345678901234567890  ', true, '12345678901234567890', 20];
        yield '20 Unicode characters accepted' => ["\u{3000}Alasan変更を確認しました十分ですよ\u{3000}", true, 'Alasan変更を確認しました十分ですよ', 20];
    }

    #[DataProvider('aspects')]
    public function test_every_exact_aspect_is_accepted(string $aspect): void
    {
        $result = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => $aspect,
            'system_level' => 5,
            'final_level' => 5,
            'reason' => null,
        ]);

        self::assertSame($aspect, $result['provenance']['aspect']);
    }

    /** @return iterable<string, array{string}> */
    public static function aspects(): iterable
    {
        foreach (['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'] as $aspect) {
            yield $aspect => [$aspect];
        }
    }

    #[DataProvider('labels')]
    public function test_every_exact_label_is_accepted(string $label): void
    {
        $result = (new ProfessionalOverridePolicy)->labelOverride([
            'system_label' => $label,
            'final_label' => $label,
            'reason' => '',
        ]);

        self::assertSame($label, $result['system_label']);
        self::assertSame($label, $result['final_label']);
    }

    /** @return iterable<string, array{string}> */
    public static function labels(): iterable
    {
        yield 'recommended' => ['DISARANKAN'];
        yield 'considered' => ['DIPERTIMBANGKAN'];
        yield 'not recommended' => ['TIDAK_DISARANKAN'];
    }

    /** @param array<mixed> $input */
    #[DataProvider('invalidInputs')]
    public function test_invalid_payloads_fail_closed(string $method, array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProfessionalOverridePolicy)->{$method}($input);
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidInputs(): iterable
    {
        $level = self::validLevelInput();
        $label = self::validLabelInput();

        $missingLevel = $level;
        unset($missingLevel['final_level']);
        yield 'level missing key' => ['levelOverride', $missingLevel];
        yield 'level extra key' => ['levelOverride', [...$level, 'dass' => 1]];
        yield 'unknown aspect' => ['levelOverride', [...$level, 'aspect' => 'A3']];
        yield 'case-folded aspect' => ['levelOverride', [...$level, 'aspect' => 'a1']];
        yield 'non-string aspect' => ['levelOverride', [...$level, 'aspect' => 1]];
        yield 'system level wrong type' => ['levelOverride', [...$level, 'system_level' => '3']];
        yield 'final level wrong type' => ['levelOverride', [...$level, 'final_level' => 3.0]];
        yield 'system level below range' => ['levelOverride', [...$level, 'system_level' => 0]];
        yield 'final level above range' => ['levelOverride', [...$level, 'final_level' => 6]];
        yield 'changed level null reason' => ['levelOverride', [...$level, 'reason' => null]];
        yield 'changed level blank reason' => ['levelOverride', [...$level, 'reason' => '   ']];
        yield 'unchanged level nonblank reason' => ['levelOverride', [...$level, 'final_level' => 2, 'reason' => str_repeat('a', 20)]];
        yield 'level reason wrong type' => ['levelOverride', [...$level, 'reason' => 20]];

        $missingLabel = $label;
        unset($missingLabel['system_label']);
        yield 'label missing key' => ['labelOverride', $missingLabel];
        yield 'label extra key' => ['labelOverride', [...$label, 'aspect' => null]];
        yield 'unknown system label' => ['labelOverride', [...$label, 'system_label' => 'LAINNYA']];
        yield 'case-folded final label' => ['labelOverride', [...$label, 'final_label' => 'dipertimbangkan']];
        yield 'system label wrong type' => ['labelOverride', [...$label, 'system_label' => 1]];
        yield 'final label wrong type' => ['labelOverride', [...$label, 'final_label' => null]];
        yield 'changed label short reason' => ['labelOverride', [...$label, 'reason' => 'terlalu singkat']];
        yield 'unchanged label nonblank reason' => ['labelOverride', [
            'system_label' => 'DISARANKAN',
            'final_label' => 'DISARANKAN',
            'reason' => str_repeat('b', 20),
        ]];
        yield 'label reason wrong type' => ['labelOverride', [...$label, 'reason' => []]];
    }

    public function test_same_input_has_deterministic_exact_output_without_dass_fields(): void
    {
        $input = self::validLevelInput();
        $policy = new ProfessionalOverridePolicy;

        $first = $policy->levelOverride($input);
        $second = $policy->levelOverride($input);

        self::assertSame($first, $second);
        self::assertSame(
            ['type', 'changed', 'audit_required', 'recalculation_required', 'system_level', 'final_level', 'reason', 'provenance'],
            array_keys($first),
        );
        self::assertSame(['policy', 'override_type', 'aspect', 'reason_character_count'], array_keys($first['provenance']));
        self::assertArrayNotHasKey('dass', $first);
        self::assertArrayNotHasKey('dass', $first['provenance']);
    }

    /** @return array<string, mixed> */
    private static function validLevelInput(): array
    {
        return [
            'aspect' => 'A1',
            'system_level' => 2,
            'final_level' => 3,
            'reason' => 'Alasan profesional tercatat lengkap.',
        ];
    }

    /** @return array<string, mixed> */
    private static function validLabelInput(): array
    {
        return [
            'system_label' => 'DISARANKAN',
            'final_label' => 'DIPERTIMBANGKAN',
            'reason' => 'Alasan profesional tercatat lengkap.',
        ];
    }
}
