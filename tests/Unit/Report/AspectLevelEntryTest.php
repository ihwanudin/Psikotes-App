<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\AspectLevelEntry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AspectLevelEntryTest extends TestCase
{
    public function test_zone_matches_grey_area_model_against_standard(): void
    {
        $meeting = AspectLevelEntry::create('A1', 'Inteligensi Umum', '知的能力', 4, 3);
        $grey = AspectLevelEntry::create('C2', 'Komunikasi', '意思疎通', 3, 4);
        $below = AspectLevelEntry::create('B2', 'Kecepatan', '速度', 2, 4);

        $this->assertSame(AspectLevelEntry::ZONE_OK, $meeting->zone());
        $this->assertSame(AspectLevelEntry::ZONE_GREY, $grey->zone());
        $this->assertSame(AspectLevelEntry::ZONE_BELUM, $below->zone());
    }

    public function test_aspect_without_standard_carries_no_zone(): void
    {
        $interest = AspectLevelEntry::create('D1', 'Minat Luar Ruang', '屋外', 3, null);

        $this->assertNull($interest->zone());
    }

    public function test_critical_aspects_are_flagged(): void
    {
        $critical = AspectLevelEntry::create('C5', 'Ketaatan Aturan', '規則遵守', 4, 3);
        $nonCritical = AspectLevelEntry::create('C7', 'Pelayanan', '奉仕性', 4, 3);

        $this->assertTrue($critical->isCritical());
        $this->assertFalse($nonCritical->isCritical());
    }

    public function test_hpp_row_projection_hides_aspect_code_and_standard(): void
    {
        $entry = AspectLevelEntry::create('B2', 'Kecepatan & Ketelitian', '速度・正確性', 4, 3);

        $row = $entry->toHppRow();

        $this->assertSame(['label_id', 'label_jp', 'level', 'zone'], array_keys($row));
        $this->assertSame('Kecepatan & Ketelitian', $row['label_id']);
        $this->assertSame(4, $row['level']);
        $this->assertSame('OK', $row['zone']);
    }

    #[DataProvider('invalidEntries')]
    public function test_invalid_entry_is_rejected(
        string $code,
        int $level,
        ?int $standard,
        string $labelId,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        AspectLevelEntry::create($code, $labelId, 'ラベル', $level, $standard);
    }

    /**
     * @return iterable<string, array{string, int, int|null, string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'unknown aspect code' => ['X9', 3, 3, 'Label'];
        yield 'level below one' => ['A1', 0, 3, 'Label'];
        yield 'level above five' => ['A1', 6, 3, 'Label'];
        yield 'standard below one' => ['A1', 3, 0, 'Label'];
        yield 'standard above five' => ['A1', 3, 6, 'Label'];
        yield 'empty label' => ['A1', 3, 3, ' '];
    }
}
