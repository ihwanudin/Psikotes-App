<?php

declare(strict_types=1);

namespace Tests\Integration\Narrative;

use App\Domain\Narrative\IntegrationDevelopmentAreaOrder;
use App\Domain\Narrative\IntegrationTargetInterestRiskEvidence;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class S5S6AuthorityBoundaryAcceptanceTest extends TestCase
{
    #[DataProvider('specificFields')]
    public function test_s5_and_s6_evidence_remain_separate_across_every_specific_field(
        string $field,
        string $targetAspect,
    ): void {
        $developmentInput = $this->developmentInput();
        $developmentInput[0]['zone'] = 'BELUM';
        $developmentInput[6]['zone'] = 'GREY';

        $expectedDevelopment = [
            'type' => 'integration_development_area_order',
            'ordered_aspects' => [
                ['aspect' => 'A1', 'zone' => 'BELUM', 'source_position' => 1],
                ['aspect' => 'C1', 'zone' => 'GREY', 'source_position' => 7],
            ],
            'review_required' => false,
            'omitted_aspects' => [],
        ];

        foreach ($this->zones() as $zone => $level) {
            $development = $this->developmentOrder()->order($developmentInput);
            $targetInterest = (new IntegrationTargetInterestRiskEvidence)->derive(
                $field,
                $this->targetInterestInput($targetAspect, $level, $zone),
            );

            $this->assertSame($expectedDevelopment, $development);
            $this->assertSame(
                $this->expectedTargetInterestRows($targetAspect, $zone),
                $targetInterest,
            );
            $this->assertTargetInterestRowsExposeOnlyStructuralEvidence($targetInterest);
            $this->assertNoUnsupportedSlotInference([$development, $targetInterest]);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function specificFields(): iterable
    {
        yield 'kaigo maps only to D4' => ['KAIGO', 'D4'];
        yield 'kensetsu maps only to D3' => ['KENSETSU', 'D3'];
        yield 'nougyou maps only to D1' => ['NOUGYOU', 'D1'];
        yield 'seizou maps only to D2' => ['SEIZOU', 'D2'];
        yield 'gaishoku maps only to D5 social service' => ['GAISHOKU', 'D5'];
    }

    public function test_umum_keeps_all_five_target_interest_rows_unassessed_without_affecting_s5(): void
    {
        $developmentInput = $this->developmentInput();
        $developmentInput[6]['zone'] = 'BELUM';

        $development = $this->developmentOrder()->order($developmentInput);
        $targetInterest = (new IntegrationTargetInterestRiskEvidence)->derive(
            'UMUM',
            $this->targetInterestInput(),
        );

        $this->assertSame([
            'type' => 'integration_development_area_order',
            'ordered_aspects' => [
                ['aspect' => 'C1', 'zone' => 'BELUM', 'source_position' => 7],
            ],
            'review_required' => false,
            'omitted_aspects' => [],
        ], $development);
        $this->assertSame($this->expectedTargetInterestRows(), $targetInterest);
        $this->assertTargetInterestRowsExposeOnlyStructuralEvidence($targetInterest);
        $this->assertNoUnsupportedSlotInference([$development, $targetInterest]);
    }

    #[DataProvider('reviewRequiredCases')]
    public function test_unresolved_review_evidence_fails_closed_before_any_s6_inference(
        string $field,
        ?string $targetAspect,
        string $reviewAspect,
    ): void {
        $input = $this->targetInterestInput($targetAspect, 2, $targetAspect === null ? null : 'GREY');
        $input[((int) substr($reviewAspect, 1)) - 1]['review_required'] = true;

        $this->expectException(InvalidArgumentException::class);

        (new IntegrationTargetInterestRiskEvidence)->derive($field, $input);
    }

    /** @return iterable<string, array{string, string|null, string}> */
    public static function reviewRequiredCases(): iterable
    {
        yield 'specific target unresolved' => ['KAIGO', 'D4', 'D4'];
        yield 'specific non-target unresolved' => ['KAIGO', 'D4', 'D1'];
        yield 'umum unresolved' => ['UMUM', null, 'D3'];
    }

    /** @return array{'OK': 3, 'GREY': 2, 'BELUM': 1} */
    private function zones(): array
    {
        return ['OK' => 3, 'GREY' => 2, 'BELUM' => 1];
    }

    private function developmentOrder(): IntegrationDevelopmentAreaOrder
    {
        return new IntegrationDevelopmentAreaOrder(['A' => 0.3, 'B' => 0.3, 'C' => 0.4]);
    }

    /** @return list<array{aspect: string, zone: 'OK'|'GREY'|'BELUM', review_required: bool}> */
    private function developmentInput(): array
    {
        return array_map(
            static fn (string $aspect): array => [
                'aspect' => $aspect,
                'zone' => 'OK',
                'review_required' => false,
            ],
            ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7'],
        );
    }

    /**
     * @param  'OK'|'GREY'|'BELUM'|null  $zone
     * @return list<array{aspect: string, level: int, standard: 3|null, zone: 'OK'|'GREY'|'BELUM'|null, review_required: bool}>
     */
    private function targetInterestInput(
        ?string $targetAspect = null,
        int $level = 3,
        ?string $zone = null,
    ): array {
        return array_map(
            static fn (string $aspect): array => [
                'aspect' => $aspect,
                'level' => $level,
                'standard' => $aspect === $targetAspect ? 3 : null,
                'zone' => $aspect === $targetAspect ? $zone : null,
                'review_required' => false,
            ],
            ['D1', 'D2', 'D3', 'D4', 'D5'],
        );
    }

    /**
     * @param  'OK'|'GREY'|'BELUM'|null  $zone
     * @return list<array{aspect: string, zone: 'OK'|'GREY'|'BELUM'|null, risk_note_required: bool}>
     */
    private function expectedTargetInterestRows(?string $targetAspect = null, ?string $zone = null): array
    {
        return array_map(
            static fn (string $aspect): array => [
                'aspect' => $aspect,
                'zone' => $aspect === $targetAspect ? $zone : null,
                'risk_note_required' => $aspect === $targetAspect
                    && in_array($zone, ['GREY', 'BELUM'], true),
            ],
            ['D1', 'D2', 'D3', 'D4', 'D5'],
        );
    }

    /** @param list<array<mixed>> $rows */
    private function assertTargetInterestRowsExposeOnlyStructuralEvidence(array $rows): void
    {
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['aspect', 'zone', 'risk_note_required'], array_keys($row));
        }
    }

    /** @param array<mixed> $evidence */
    private function assertNoUnsupportedSlotInference(array $evidence): void
    {
        $forbiddenKeys = [
            'support',
            'support_key',
            'support_phrase',
            'highest_interest',
            'bidang_minat_tertinggi',
            'suitability',
            'label_kesesuaian',
            'narrative',
            'text',
        ];

        $this->assertSame([], array_intersect($forbiddenKeys, $this->recursiveKeys($evidence)));
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private function recursiveKeys(array $value): array
    {
        $keys = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($item)) {
                array_push($keys, ...$this->recursiveKeys($item));
            }
        }

        return $keys;
    }
}
