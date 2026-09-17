<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\AspectLevelEntry;
use App\Domain\Report\ReportAspectGrid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportAspectGridTest extends TestCase
{
    /** @return list<array{code: string, label_id: string, label_jp: string, level: int, standard: int|null}> */
    private static function rows(): array
    {
        $rows = [];
        foreach (ReportAspectGrid::ASPECTS as $code) {
            $rows[] = [
                'code' => $code,
                'label_id' => "Aspek {$code}",
                'label_jp' => 'アスペクト',
                'level' => 4,
                'standard' => $code === 'D1' ? null : 3,
            ];
        }

        return $rows;
    }

    public function test_grid_requires_all_eighteen_canonical_aspects_in_order(): void
    {
        $grid = ReportAspectGrid::fromArray(self::rows());

        $this->assertSame(
            ReportAspectGrid::ASPECTS,
            array_map(static fn (array $row): string => $row['code'], $grid->toArray()),
        );
    }

    public function test_missing_aspect_is_rejected(): void
    {
        $rows = self::rows();
        array_splice($rows, 7, 1);

        $this->expectException(InvalidArgumentException::class);

        ReportAspectGrid::fromArray($rows);
    }

    public function test_out_of_order_aspects_are_rejected(): void
    {
        $rows = self::rows();
        [$rows[0], $rows[1]] = [$rows[1], $rows[0]];

        $this->expectException(InvalidArgumentException::class);

        ReportAspectGrid::fromArray($rows);
    }

    public function test_zone_counts_ignore_standardless_aspects(): void
    {
        $rows = self::rows();
        $rows[1]['level'] = 1;

        $counts = ReportAspectGrid::fromArray($rows)->zoneCounts();

        $this->assertSame(16, $counts['OK']);
        $this->assertSame(0, $counts['GREY']);
        $this->assertSame(1, $counts['BELUM']);
    }

    public function test_critical_belum_aspects_are_collected(): void
    {
        $rows = self::rows();
        foreach ($rows as $index => $row) {
            if (in_array($row['code'], ['B2', 'C4', 'C6'], true)) {
                $rows[$index]['level'] = 1;
            }
        }

        $grid = ReportAspectGrid::fromArray($rows);

        $this->assertSame(['B2', 'C4'], $grid->criticalBelumAspects());
    }

    public function test_hpp_rows_never_expose_codes(): void
    {
        $grid = ReportAspectGrid::fromArray(self::rows());

        foreach ($grid->hppRows() as $row) {
            $this->assertSame(['label_id', 'label_jp', 'level', 'zone'], array_keys($row));
        }
    }

    public function test_entries_constructor_validates_the_same_contract(): void
    {
        $entries = array_map(
            static fn (array $row): AspectLevelEntry => AspectLevelEntry::create(
                $row['code'],
                $row['label_id'],
                $row['label_jp'],
                $row['level'],
                $row['standard'],
            ),
            self::rows(),
        );

        $this->assertCount(18, ReportAspectGrid::fromEntries($entries)->toArray());
    }
}
