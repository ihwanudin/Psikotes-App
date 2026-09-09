<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Domain\Review\ReviewedEligibilityDecision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReviewedEligibilityDecisionTest extends TestCase
{
    public function test_level_overrides_are_applied_to_a_real_recalculation_and_preserve_both_decisions(): void
    {
        $baseline = EligibilityDecisionSnapshot::create($this->validInput());
        $override = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'C4',
            'system_level' => 5,
            'final_level' => 1,
            'reason' => 'Observasi profesional menunjukkan stabilitas rendah.',
        ]);

        $result = ReviewedEligibilityDecision::create($baseline, [$override])->toArray();

        self::assertSame(5, $result['system_levels']['C4']);
        self::assertSame(1, $result['final_levels']['C4']);
        self::assertSame('DISARANKAN', $result['system_decision']['recommendation']['label']);
        self::assertSame('TIDAK_DISARANKAN', $result['recalculated_decision']['recommendation']['label']);
        self::assertSame('TIDAK_DISARANKAN', $result['final_decision']['label']);
        self::assertSame('BELUM', $result['recalculated_decision']['zone']['aspects']['C4']['zone']);
        self::assertSame($override, $result['level_overrides'][0]);
        self::assertNull($result['label_override']);
    }

    public function test_label_override_is_validated_against_the_recalculated_label(): void
    {
        $policy = new ProfessionalOverridePolicy;
        $baseline = EligibilityDecisionSnapshot::create($this->validInput());
        $levelOverride = $policy->levelOverride([
            'aspect' => 'A2',
            'system_level' => 5,
            'final_level' => 2,
            'reason' => 'Observasi profesional memerlukan penurunan level.',
        ]);
        $labelOverride = $policy->labelOverride([
            'system_label' => 'DIPERTIMBANGKAN',
            'final_label' => 'TIDAK_DISARANKAN',
            'reason' => 'Pertimbangan profesional menunjukkan risiko tambahan.',
        ]);

        $result = ReviewedEligibilityDecision::create($baseline, [$levelOverride], $labelOverride)->toArray();

        self::assertSame('DISARANKAN', $result['system_decision']['recommendation']['label']);
        self::assertSame('DIPERTIMBANGKAN', $result['recalculated_decision']['recommendation']['label']);
        self::assertSame('TIDAK_DISARANKAN', $result['final_decision']['label']);
        self::assertSame($labelOverride, $result['label_override']);
    }

    public function test_level_override_must_match_the_baseline_system_level(): void
    {
        $override = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'C4',
            'system_level' => 4,
            'final_level' => 1,
            'reason' => 'Observasi profesional menunjukkan stabilitas rendah.',
        ]);

        $this->expectException(InvalidArgumentException::class);
        ReviewedEligibilityDecision::create(EligibilityDecisionSnapshot::create($this->validInput()), [$override]);
    }

    public function test_each_aspect_may_be_overridden_only_once(): void
    {
        $override = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'A2',
            'system_level' => 5,
            'final_level' => 3,
            'reason' => 'Observasi profesional membutuhkan penyesuaian level.',
        ]);

        $this->expectException(InvalidArgumentException::class);
        ReviewedEligibilityDecision::create(EligibilityDecisionSnapshot::create($this->validInput()), [$override, $override]);
    }

    public function test_level_override_order_does_not_change_the_reviewed_decision_or_hash(): void
    {
        $policy = new ProfessionalOverridePolicy;
        $baseline = EligibilityDecisionSnapshot::create($this->validInput());
        $a2 = $policy->levelOverride([
            'aspect' => 'A2',
            'system_level' => 5,
            'final_level' => 4,
            'reason' => 'Observasi profesional mendukung level akhir empat.',
        ]);
        $c4 = $policy->levelOverride([
            'aspect' => 'C4',
            'system_level' => 5,
            'final_level' => 3,
            'reason' => 'Observasi profesional mendukung level akhir tiga.',
        ]);

        $canonical = ReviewedEligibilityDecision::create($baseline, [$a2, $c4])->toArray();
        $permuted = ReviewedEligibilityDecision::create($baseline, [$c4, $a2])->toArray();

        self::assertSame($canonical, $permuted);
        self::assertSame(
            hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($permuted, JSON_THROW_ON_ERROR)),
        );
        self::assertSame(['A2', 'C4'], array_map(
            static fn (array $override): string => $override['provenance']['aspect'],
            $permuted['level_overrides'],
        ));
    }

    public function test_no_op_level_override_is_rejected(): void
    {
        $override = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'C4',
            'system_level' => 5,
            'final_level' => 5,
            'reason' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        ReviewedEligibilityDecision::create(EligibilityDecisionSnapshot::create($this->validInput()), [$override]);
    }

    public function test_no_op_label_override_is_rejected(): void
    {
        $override = (new ProfessionalOverridePolicy)->labelOverride([
            'system_label' => 'DISARANKAN',
            'final_label' => 'DISARANKAN',
            'reason' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        ReviewedEligibilityDecision::create(EligibilityDecisionSnapshot::create($this->validInput()), [], $override);
    }

    public function test_forged_or_nonexact_override_outputs_fail_closed(): void
    {
        $override = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'A2',
            'system_level' => 5,
            'final_level' => 3,
            'reason' => 'Observasi profesional membutuhkan penyesuaian level.',
        ]);
        $override['recalculation_required'] = false;

        $this->expectException(InvalidArgumentException::class);
        ReviewedEligibilityDecision::create(EligibilityDecisionSnapshot::create($this->validInput()), [$override]);
    }

    public function test_label_override_must_match_the_recalculated_system_label(): void
    {
        $override = (new ProfessionalOverridePolicy)->labelOverride([
            'system_label' => 'DIPERTIMBANGKAN',
            'final_label' => 'TIDAK_DISARANKAN',
            'reason' => 'Pertimbangan profesional menunjukkan risiko tambahan.',
        ]);

        $this->expectException(InvalidArgumentException::class);
        ReviewedEligibilityDecision::create(EligibilityDecisionSnapshot::create($this->validInput()), [], $override);
    }

    public function test_v3_stays_blocked_and_never_exposes_a_label(): void
    {
        $input = $this->validInput();
        $input['validity'] = 'V3';

        $result = ReviewedEligibilityDecision::create(EligibilityDecisionSnapshot::create($input), [])->toArray();

        self::assertTrue($result['publication_blocked']);
        self::assertSame('publication_blocked', $result['final_decision']['type']);
        self::assertArrayNotHasKey('label', $result['system_decision']['recommendation']);
        self::assertArrayNotHasKey('label', $result['recalculated_decision']['recommendation']);
        self::assertArrayNotHasKey('label', $result['final_decision']);
    }

    public function test_v3_rejects_a_label_override(): void
    {
        $input = $this->validInput();
        $input['validity'] = 'V3';
        $override = (new ProfessionalOverridePolicy)->labelOverride([
            'system_label' => 'DISARANKAN',
            'final_label' => 'DIPERTIMBANGKAN',
            'reason' => 'Pertimbangan profesional membutuhkan pendampingan.',
        ]);

        $this->expectException(InvalidArgumentException::class);
        ReviewedEligibilityDecision::create(EligibilityDecisionSnapshot::create($input), [], $override);
    }

    /** @return array<mixed> */
    private function validInput(): array
    {
        $reporting = $this->canonicalReporting();

        return [
            'levels' => array_fill_keys($this->aspectCodes(), 5),
            'field_code' => 'UMUM',
            'iq' => 100,
            'validity' => 'V1',
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => 'F0-2026.08',
                'papi' => 'F0-2026.08',
                'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08',
                'reporting' => $reporting['standard_version'],
            ],
        ];
    }

    /** @return list<string> */
    private function aspectCodes(): array
    {
        return ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];
    }

    /** @return array{standard_version: string, base_standards: array<mixed>, fields: array<mixed>} */
    private function canonicalReporting(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/reporting.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical reporting data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)
            || ! is_string($data['standard_version'] ?? null)
            || ! is_array($data['base_standards'] ?? null)
            || ! is_array($data['fields'] ?? null)) {
            throw new RuntimeException('Canonical reporting data is incomplete.');
        }

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $data['base_standards'],
            'fields' => $data['fields'],
        ];
    }
}
