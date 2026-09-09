<?php

declare(strict_types=1);

namespace Tests\Unit\Narrative;

use App\Domain\Narrative\IntegrationTargetInterestRiskEvidence;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegrationTargetInterestRiskEvidenceTest extends TestCase
{
    #[DataProvider('specificFields')]
    public function test_it_identifies_the_canonical_target_interest_for_each_specific_field(
        string $field,
        string $targetAspect,
    ): void {
        $result = (new IntegrationTargetInterestRiskEvidence)->derive(
            $field,
            $this->aspects($targetAspect, 4, 'OK'),
        );

        $this->assertSame($this->expectedRows($targetAspect, 'OK'), $result);
    }

    /** @return iterable<string, array{string, string}> */
    public static function specificFields(): iterable
    {
        yield 'kaigo' => ['KAIGO', 'D4'];
        yield 'kensetsu' => ['KENSETSU', 'D3'];
        yield 'nougyou' => ['NOUGYOU', 'D1'];
        yield 'seizou' => ['SEIZOU', 'D2'];
        yield 'gaishoku' => ['GAISHOKU', 'D5'];
    }

    #[DataProvider('targetZones')]
    public function test_risk_note_is_required_if_and_only_if_the_target_interest_is_grey_or_belum(
        int $level,
        string $zone,
        bool $riskNoteRequired,
    ): void {
        $result = (new IntegrationTargetInterestRiskEvidence)->derive(
            'KAIGO',
            $this->aspects('D4', $level, $zone),
        );

        $this->assertSame([
            'aspect' => 'D4',
            'zone' => $zone,
            'risk_note_required' => $riskNoteRequired,
        ], $result[3]);
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function targetZones(): iterable
    {
        yield 'above standard is OK' => [5, 'OK', false];
        yield 'at standard is OK' => [3, 'OK', false];
        yield 'one below standard is grey' => [2, 'GREY', true];
        yield 'two below standard is belum' => [1, 'BELUM', true];
    }

    public function test_umum_requires_every_interest_to_be_unassessed_and_returns_no_target_or_risk(): void
    {
        $result = (new IntegrationTargetInterestRiskEvidence)->derive('UMUM', $this->aspects());

        $this->assertSame($this->expectedRows(), $result);
    }

    public function test_review_required_target_fails_closed(): void
    {
        $aspects = $this->aspects('D5', 2, 'GREY');
        $aspects[4]['review_required'] = true;

        $this->expectException(InvalidArgumentException::class);

        (new IntegrationTargetInterestRiskEvidence)->derive('GAISHOKU', $aspects);
    }

    public function test_review_required_non_target_interest_fails_closed(): void
    {
        $aspects = $this->aspects('D4', 3, 'OK');
        $aspects[1]['review_required'] = true;

        $this->expectException(InvalidArgumentException::class);

        (new IntegrationTargetInterestRiskEvidence)->derive('KAIGO', $aspects);
    }

    public function test_umum_review_omission_fails_closed(): void
    {
        $aspects = $this->aspects();
        $aspects[0]['review_required'] = true;

        $this->expectException(InvalidArgumentException::class);

        (new IntegrationTargetInterestRiskEvidence)->derive('UMUM', $aspects);
    }

    /** @param array<mixed> $aspects */
    #[DataProvider('invalidInputs')]
    public function test_it_fails_closed_on_invalid_or_noncanonical_inputs(string $field, array $aspects): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new IntegrationTargetInterestRiskEvidence)->derive($field, $aspects);
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidInputs(): iterable
    {
        $valid = self::makeAspects('D4', 3, 'OK');

        yield 'unknown field' => ['OTHER', $valid];
        yield 'noncanonical field whitespace' => [' KAIGO', $valid];
        yield 'missing record' => ['KAIGO', array_slice($valid, 0, 4)];
        yield 'extra record' => ['KAIGO', [...$valid, $valid[4]]];
        yield 'reordered records' => ['KAIGO', [$valid[1], $valid[0], ...array_slice($valid, 2)]];
        yield 'duplicate aspect' => ['KAIGO', array_replace($valid, [1 => [...$valid[1], 'aspect' => 'D1']])];
        yield 'unknown aspect' => ['KAIGO', array_replace($valid, [0 => [...$valid[0], 'aspect' => 'D6']])];
        yield 'record missing key' => ['KAIGO', array_replace($valid, [0 => array_diff_key($valid[0], ['zone' => true])])];
        yield 'record extra key' => ['KAIGO', array_replace($valid, [0 => [...$valid[0], 'text' => 'forbidden']])];
        yield 'level is not an integer' => ['KAIGO', array_replace($valid, [0 => [...$valid[0], 'level' => '3']])];
        yield 'level below domain' => ['KAIGO', array_replace($valid, [0 => [...$valid[0], 'level' => 0]])];
        yield 'level above domain' => ['KAIGO', array_replace($valid, [0 => [...$valid[0], 'level' => 6]])];
        yield 'review flag is not boolean' => ['KAIGO', array_replace($valid, [0 => [...$valid[0], 'review_required' => 0]])];
        yield 'target standard is not three' => ['KAIGO', array_replace($valid, [3 => [...$valid[3], 'standard' => 4]])];
        yield 'target is unassessed' => ['KAIGO', array_replace($valid, [3 => [...$valid[3], 'standard' => null, 'zone' => null]])];
        yield 'wrong D aspect is assessed' => ['KAIGO', self::makeAspects('D3', 3, 'OK')];
        yield 'two D aspects are assessed' => ['KAIGO', array_replace($valid, [0 => [...$valid[0], 'standard' => 3, 'zone' => 'OK']])];
        yield 'unassessed aspect has a zone' => ['KAIGO', array_replace($valid, [0 => [...$valid[0], 'zone' => 'OK']])];
        yield 'assessed target has null zone' => ['KAIGO', array_replace($valid, [3 => [...$valid[3], 'zone' => null]])];
        yield 'zone contradicts target level' => ['KAIGO', array_replace($valid, [3 => [...$valid[3], 'level' => 2, 'zone' => 'OK']])];
        yield 'unknown zone' => ['KAIGO', array_replace($valid, [3 => [...$valid[3], 'zone' => 'MAYBE']])];
        yield 'umum assesses one D aspect' => ['UMUM', $valid];
    }

    /**
     * @return list<array{aspect: string, level: int, standard: int|null, zone: string|null, review_required: bool}>
     */
    private function aspects(?string $targetAspect = null, int $level = 3, ?string $zone = null): array
    {
        return self::makeAspects($targetAspect, $level, $zone);
    }

    /**
     * @return list<array{aspect: string, level: int, standard: int|null, zone: string|null, review_required: bool}>
     */
    private static function makeAspects(?string $targetAspect = null, int $level = 3, ?string $zone = null): array
    {
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

    /** @return list<array{aspect: string, zone: string|null, risk_note_required: bool}> */
    private function expectedRows(?string $targetAspect = null, ?string $zone = null): array
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
}
