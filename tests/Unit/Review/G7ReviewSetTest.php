<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Review\G7AspectResolution;
use App\Domain\Review\G7ReviewSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class G7ReviewSetTest extends TestCase
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    public function test_complete_set_contains_exactly_the_eighteen_canonical_aspects(): void
    {
        $resolutions = $this->completeResolutions();
        $resolutions[7] = G7AspectResolution::resolved(
            $this->discrepancy('C2', 2, 4),
            3,
            4,
            'Bukti observasi mendukung level akhir empat.',
        );

        $set = G7ReviewSet::fromResolutions(array_reverse($resolutions));

        $this->assertSame(self::ASPECTS, array_map(
            static fn (G7AspectResolution $resolution): string => $resolution->aspect(),
            $set->resolutions(),
        ));
        $this->assertSame(4, $set->resolutionFor('C2')->finalLevel());
        $expectedLevels = array_fill_keys(self::ASPECTS, 3);
        $expectedLevels['C2'] = 4;
        $this->assertSame($expectedLevels, $set->finalLevels());
    }

    public function test_missing_aspect_is_rejected(): void
    {
        $resolutions = $this->completeResolutions();
        array_pop($resolutions);

        $this->expectException(InvalidArgumentException::class);

        G7ReviewSet::fromResolutions($resolutions);
    }

    public function test_duplicate_aspect_is_rejected(): void
    {
        $resolutions = $this->completeResolutions();
        $resolutions[17] = $resolutions[0];

        $this->expectException(InvalidArgumentException::class);

        G7ReviewSet::fromResolutions($resolutions);
    }

    public function test_unresolved_required_review_is_rejected_from_signing_ready_set(): void
    {
        $resolutions = $this->completeResolutions();
        $resolutions[0] = G7AspectResolution::unresolved($this->discrepancy('A1', 2, 4), 3);

        $this->expectException(InvalidArgumentException::class);

        G7ReviewSet::fromResolutions($resolutions);
    }

    public function test_raw_array_or_boolean_cannot_stand_in_for_a_typed_resolution(): void
    {
        $resolutions = $this->completeResolutions();
        $resolutions[0] = true;

        $this->expectException(InvalidArgumentException::class);

        G7ReviewSet::fromResolutions($resolutions);
    }

    public function test_lookup_rejects_noncanonical_aspect(): void
    {
        $set = G7ReviewSet::fromResolutions($this->completeResolutions());

        $this->expectException(InvalidArgumentException::class);

        $set->resolutionFor('D6');
    }

    /** @return list<G7AspectResolution> */
    private function completeResolutions(): array
    {
        return array_map(
            fn (string $aspect): G7AspectResolution => G7AspectResolution::notRequired(
                $this->discrepancy($aspect, 3, 3),
                3,
            ),
            self::ASPECTS,
        );
    }

    /** @return array<mixed> */
    private function discrepancy(string $aspect, int $minimum, int $maximum): array
    {
        return (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => $aspect,
            'sources' => [
                ['source' => 'SOURCE_LOW', 'level' => $minimum],
                ['source' => 'SOURCE_HIGH', 'level' => $maximum],
            ],
        ]);
    }
}
