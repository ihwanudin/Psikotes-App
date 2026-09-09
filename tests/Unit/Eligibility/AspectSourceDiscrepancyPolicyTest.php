<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AspectSourceDiscrepancyPolicyTest extends TestCase
{
    public function test_spread_one_does_not_require_review_or_block_automatic_narrative(): void
    {
        $result = (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => 'A2',
            'sources' => [
                ['source' => 'PAPI_R', 'level' => 4],
                ['source' => 'IST_AN', 'level' => 3],
                ['source' => 'IST_RA', 'level' => 4],
            ],
        ]);

        $this->assertSame([
            'type' => 'aspect_source_discrepancy',
            'review_required' => false,
            'automatic_narrative_allowed' => true,
            'reason_code' => null,
            'provenance' => [
                'aspect' => 'A2',
                'sources' => [
                    ['source' => 'IST_AN', 'level' => 3],
                    ['source' => 'IST_RA', 'level' => 4],
                    ['source' => 'PAPI_R', 'level' => 4],
                ],
                'minimum_level' => 3,
                'maximum_level' => 4,
                'spread' => 1,
            ],
        ], $result);
    }

    public function test_spread_two_requires_review_and_blocks_automatic_narrative(): void
    {
        $result = (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => 'C4',
            'sources' => [
                ['source' => 'PAPI_E', 'level' => 2],
                ['source' => 'KRAEPELIN_HANKER', 'level' => 4],
                ['source' => 'PAPI_K', 'level' => 3],
            ],
        ]);

        $this->assertTrue($result['review_required']);
        $this->assertFalse($result['automatic_narrative_allowed']);
        $this->assertSame('SOURCE_LEVEL_SPREAD', $result['reason_code']);
        $this->assertSame(2, $result['provenance']['spread']);
    }

    public function test_equal_levels_have_zero_spread_and_do_not_require_review(): void
    {
        $result = (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => 'B2',
            'sources' => [
                ['source' => 'KRAEPELIN_PANKER', 'level' => 5],
                ['source' => 'KRAEPELIN_TIANKER', 'level' => 5],
            ],
        ]);

        $this->assertFalse($result['review_required']);
        $this->assertTrue($result['automatic_narrative_allowed']);
        $this->assertSame(0, $result['provenance']['spread']);
    }

    #[DataProvider('canonicalSingleSources')]
    public function test_canonical_single_source_aspect_has_zero_spread(
        string $aspect,
        string $source,
        int $level,
    ): void {
        $result = (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => $aspect,
            'sources' => [['source' => $source, 'level' => $level]],
        ]);

        $this->assertSame([
            'type' => 'aspect_source_discrepancy',
            'review_required' => false,
            'automatic_narrative_allowed' => true,
            'reason_code' => null,
            'provenance' => [
                'aspect' => $aspect,
                'sources' => [['source' => $source, 'level' => $level]],
                'minimum_level' => $level,
                'maximum_level' => $level,
                'spread' => 0,
            ],
        ], $result);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function canonicalSingleSources(): iterable
    {
        yield 'A1 from total IST IQ' => ['A1', 'IST_IQ', 4];
        yield 'D1 from RMIB Outdoor' => ['D1', 'RMIB_Out', 5];
        yield 'D2 from RMIB Mechanical' => ['D2', 'RMIB_Me', 4];
        yield 'D3 from RMIB Practical' => ['D3', 'RMIB_Prac', 3];
        yield 'D4 from RMIB Medical' => ['D4', 'RMIB_Med', 2];
        yield 'D5 from RMIB Social Service' => ['D5', 'RMIB_S.Se', 1];
    }

    #[DataProvider('minimalMultiSourceBoundaries')]
    public function test_minimal_multi_source_spread_boundary_is_unchanged(
        int $maximumLevel,
        bool $reviewRequired,
        ?string $reasonCode,
    ): void {
        $result = (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => 'C4',
            'sources' => [
                ['source' => 'SOURCE_LOW', 'level' => 2],
                ['source' => 'SOURCE_HIGH', 'level' => $maximumLevel],
            ],
        ]);

        $this->assertSame($reviewRequired, $result['review_required']);
        $this->assertSame(! $reviewRequired, $result['automatic_narrative_allowed']);
        $this->assertSame($reasonCode, $result['reason_code']);
        $this->assertSame(2, $result['provenance']['minimum_level']);
        $this->assertSame($maximumLevel, $result['provenance']['maximum_level']);
        $this->assertSame($maximumLevel - 2, $result['provenance']['spread']);
    }

    /** @return iterable<string, array{int, bool, string|null}> */
    public static function minimalMultiSourceBoundaries(): iterable
    {
        yield 'spread one remains below G7 threshold' => [3, false, null];
        yield 'spread two remains at G7 threshold' => [4, true, 'SOURCE_LEVEL_SPREAD'];
    }

    public function test_source_order_does_not_change_output(): void
    {
        $policy = new AspectSourceDiscrepancyPolicy;
        $forward = [
            ['source' => 'IST_ME', 'level' => 2],
            ['source' => 'KRAEPELIN_JANKER', 'level' => 4],
            ['source' => 'KRAEPELIN_PANKER', 'level' => 3],
        ];

        $this->assertSame(
            $policy->evaluate(['aspect' => 'B1', 'sources' => $forward]),
            $policy->evaluate(['aspect' => 'B1', 'sources' => array_reverse($forward)]),
        );
    }

    #[DataProvider('aspectCodes')]
    public function test_every_canonical_aspect_code_is_accepted(string $aspect): void
    {
        $result = (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => $aspect,
            'sources' => [
                ['source' => 'SOURCE_A', 'level' => 3],
                ['source' => 'SOURCE_B', 'level' => 3],
            ],
        ]);

        $this->assertSame($aspect, $result['provenance']['aspect']);
    }

    /** @return iterable<string, array{string}> */
    public static function aspectCodes(): iterable
    {
        foreach (['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'] as $aspect) {
            yield $aspect => [$aspect];
        }
    }

    /** @param array<mixed> $payload */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_or_extra_payload_data_fails_closed(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AspectSourceDiscrepancyPolicy)->evaluate($payload);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $valid = [
            'aspect' => 'A1',
            'sources' => [
                ['source' => 'SOURCE_A', 'level' => 3],
                ['source' => 'SOURCE_B', 'level' => 4],
            ],
        ];

        yield 'missing aspect' => [[
            'sources' => $valid['sources'],
        ]];
        yield 'extra top-level key' => [[
            ...$valid,
            'narrative' => 'text',
        ]];
        yield 'unknown aspect' => [[
            ...$valid,
            'aspect' => 'A3',
        ]];
        yield 'non-string aspect' => [[
            ...$valid,
            'aspect' => 1,
        ]];
        yield 'sources must be a list' => [[
            ...$valid,
            'sources' => ['first' => ['source' => 'SOURCE_A', 'level' => 3], 'second' => ['source' => 'SOURCE_B', 'level' => 4]],
        ]];
        yield 'at least one source' => [[
            ...$valid,
            'sources' => [],
        ]];
        yield 'duplicate source' => [[
            ...$valid,
            'sources' => [
                ['source' => 'SOURCE_A', 'level' => 3],
                ['source' => 'SOURCE_A', 'level' => 4],
            ],
        ]];
        yield 'source must be non-empty' => [[
            ...$valid,
            'sources' => [
                ['source' => '', 'level' => 3],
                ['source' => 'SOURCE_B', 'level' => 4],
            ],
        ]];
        yield 'source must be canonical whitespace' => [[
            ...$valid,
            'sources' => [
                ['source' => ' SOURCE_A', 'level' => 3],
                ['source' => 'SOURCE_B', 'level' => 4],
            ],
        ]];
        yield 'source entry has extra key' => [[
            ...$valid,
            'sources' => [
                ['source' => 'SOURCE_A', 'level' => 3, 'weight' => 1],
                ['source' => 'SOURCE_B', 'level' => 4],
            ],
        ]];
        yield 'source entry is not an array' => [[
            ...$valid,
            'sources' => ['SOURCE_A', ['source' => 'SOURCE_B', 'level' => 4]],
        ]];
        yield 'level must be integer' => [[
            ...$valid,
            'sources' => [
                ['source' => 'SOURCE_A', 'level' => '3'],
                ['source' => 'SOURCE_B', 'level' => 4],
            ],
        ]];
        yield 'level below domain' => [[
            ...$valid,
            'sources' => [
                ['source' => 'SOURCE_A', 'level' => 0],
                ['source' => 'SOURCE_B', 'level' => 4],
            ],
        ]];
        yield 'level above domain' => [[
            ...$valid,
            'sources' => [
                ['source' => 'SOURCE_A', 'level' => 3],
                ['source' => 'SOURCE_B', 'level' => 6],
            ],
        ]];
    }
}
