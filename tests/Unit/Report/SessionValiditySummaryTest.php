<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\SessionValiditySummary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SessionValiditySummaryTest extends TestCase
{
    /** @return array<string, bool> */
    private static function passingItems(): array
    {
        $items = [];
        foreach (SessionValiditySummary::ITEM_KEYS as $key) {
            $items[$key] = true;
        }

        return $items;
    }

    public function test_v1_summary_round_trips_without_procedure_note(): void
    {
        $summary = SessionValiditySummary::fromArray([
            'status' => 'V1',
            'items' => self::passingItems(),
            'procedure_note' => null,
        ]);

        $this->assertSame('V1', $summary->status);
        $this->assertFalse($summary->isPublicationBlocked());
        $this->assertCount(10, $summary->toArray()['items']);
    }

    public function test_v2_requires_a_procedure_note(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SessionValiditySummary::fromArray([
            'status' => 'V2',
            'items' => self::passingItems(),
            'procedure_note' => null,
        ]);
    }

    public function test_v2_with_procedure_note_is_accepted(): void
    {
        $summary = SessionValiditySummary::fromArray([
            'status' => 'V2',
            'items' => self::passingItems(),
            'procedure_note' => 'Kamera sempat mati 12 detik; peserta hadir sepanjang sesi.',
        ]);

        $this->assertSame('V2', $summary->status);
        $this->assertFalse($summary->isPublicationBlocked());
    }

    public function test_v3_blocks_publication(): void
    {
        $summary = SessionValiditySummary::fromArray([
            'status' => 'V3',
            'items' => self::passingItems(),
            'procedure_note' => null,
        ]);

        $this->assertTrue($summary->isPublicationBlocked());
    }

    public function test_missing_check_is_rejected(): void
    {
        $items = self::passingItems();
        unset($items['no_tab_switch']);

        $this->expectException(InvalidArgumentException::class);

        SessionValiditySummary::fromArray([
            'status' => 'V1',
            'items' => $items,
            'procedure_note' => null,
        ]);
    }
}
