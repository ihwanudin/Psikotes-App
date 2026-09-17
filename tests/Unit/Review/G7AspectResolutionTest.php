<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Review\G7AspectResolution;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

final class G7AspectResolutionTest extends TestCase
{
    public function test_non_reviewed_aspect_keeps_the_authoritative_result_and_system_level(): void
    {
        $result = $this->discrepancy('A1', 3, 4);

        $resolution = G7AspectResolution::notRequired($result, 4);

        $this->assertSame('A1', $resolution->aspect());
        $this->assertSame(G7AspectResolution::STATE_NOT_REQUIRED, $resolution->state());
        $this->assertSame($result, $resolution->discrepancy());
        $this->assertSame(4, $resolution->systemLevel());
        $this->assertSame(4, $resolution->finalLevel());
        $this->assertNull($resolution->reason());
        $this->assertTrue($resolution->isResolved());
    }

    public function test_review_required_aspect_has_an_explicit_unresolved_state(): void
    {
        $result = $this->discrepancy('C4', 2, 4);

        $resolution = G7AspectResolution::unresolved($result, 3);

        $this->assertSame(G7AspectResolution::STATE_UNRESOLVED, $resolution->state());
        $this->assertSame($result, $resolution->discrepancy());
        $this->assertNull($resolution->finalLevel());
        $this->assertNull($resolution->reason());
        $this->assertFalse($resolution->isResolved());
    }

    public function test_changed_resolution_requires_and_canonicalizes_a_g6_reason(): void
    {
        $resolution = G7AspectResolution::resolved(
            $this->discrepancy('C4', 2, 4),
            3,
            4,
            '  Bukti observasi mendukung level akhir empat.  ',
        );

        $this->assertSame(G7AspectResolution::STATE_RESOLVED, $resolution->state());
        $this->assertSame(4, $resolution->finalLevel());
        $this->assertSame('Bukti observasi mendukung level akhir empat.', $resolution->reason());
        $this->assertTrue($resolution->isResolved());
    }

    public function test_unchanged_resolution_does_not_invent_a_reason_requirement(): void
    {
        $resolution = G7AspectResolution::resolved(
            $this->discrepancy('C4', 2, 4),
            3,
            3,
            null,
        );

        $this->assertSame(3, $resolution->finalLevel());
        $this->assertNull($resolution->reason());
    }

    #[DataProvider('invalidChangedReasons')]
    public function test_changed_resolution_rejects_missing_short_or_invalid_reason(?string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);

        G7AspectResolution::resolved($this->discrepancy('C4', 2, 4), 3, 4, $reason);
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidChangedReasons(): iterable
    {
        yield 'missing' => [null];
        yield 'short after trimming' => ['  terlalu singkat  '];
        yield 'invalid UTF-8' => ["Alasan cukup panjang \xB1 tetapi bukan UTF-8"];
    }

    public function test_unchanged_resolution_may_keep_an_optional_canonical_note_without_a_minimum_length(): void
    {
        $resolution = G7AspectResolution::resolved(
            $this->discrepancy('C4', 2, 4),
            3,
            3,
            '  Catatan reviewer.  ',
        );

        $this->assertSame('Catatan reviewer.', $resolution->reason());
    }

    public function test_review_resolution_is_rejected_when_spread_is_below_two(): void
    {
        $this->expectException(InvalidArgumentException::class);

        G7AspectResolution::resolved($this->discrepancy('A1', 3, 4), 4, 4, null);
    }

    public function test_unresolved_state_is_rejected_when_spread_is_below_two(): void
    {
        $this->expectException(InvalidArgumentException::class);

        G7AspectResolution::unresolved($this->discrepancy('A1', 3, 4), 4);
    }

    public function test_not_required_state_is_rejected_when_review_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        G7AspectResolution::notRequired($this->discrepancy('A1', 2, 4), 3);
    }

    public function test_tampered_or_raw_summary_is_not_an_authoritative_discrepancy_result(): void
    {
        $this->expectException(InvalidArgumentException::class);

        G7AspectResolution::notRequired([
            'aspect' => 'A1',
            'spread' => 1,
            'review_required' => false,
        ], 3);
    }

    public function test_tampered_authoritative_output_is_rejected(): void
    {
        $result = $this->discrepancy('A1', 3, 4);
        $result['automatic_narrative_allowed'] = false;

        $this->expectException(InvalidArgumentException::class);

        G7AspectResolution::notRequired($result, 4);
    }

    public function test_boolean_is_not_a_resolution_artifact(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore argument.type */
        G7AspectResolution::notRequired(true, 3);
    }

    #[DataProvider('invalidLevels')]
    public function test_levels_are_limited_to_one_through_five(int $systemLevel, int $finalLevel): void
    {
        $this->expectException(InvalidArgumentException::class);

        G7AspectResolution::resolved(
            $this->discrepancy('C4', 2, 4),
            $systemLevel,
            $finalLevel,
            'Bukti observasi mendukung perubahan level ini.',
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidLevels(): iterable
    {
        yield 'system below range' => [0, 4];
        yield 'system above range' => [6, 4];
        yield 'final below range' => [3, 0];
        yield 'final above range' => [3, 6];
    }

    /**
     * @return array<mixed>
     */
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
