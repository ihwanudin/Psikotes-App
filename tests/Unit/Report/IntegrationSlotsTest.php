<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\IntegrationSlots;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IntegrationSlotsTest extends TestCase
{
    /** @return array<string, array{text_id: string, text_jp: string|null}> */
    private static function slots(): array
    {
        $slots = [];
        foreach (IntegrationSlots::SLOTS as $slot) {
            $slots[$slot] = ['text_id' => "Narasi {$slot}.", 'text_jp' => "テキスト {$slot}。"];
        }

        return $slots;
    }

    public function test_all_seven_slots_are_projected_bilingually(): void
    {
        $rows = IntegrationSlots::fromArray(self::slots())->toArray();

        $this->assertSame(IntegrationSlots::SLOTS, array_column($rows, 'code'));
        $this->assertSame('Gambaran Umum', $rows[0]['title_id']);
        $this->assertSame('Saran Penempatan', $rows[6]['title_id']);
        $this->assertSame('Narasi S4.', $rows[3]['text_id']);
        $this->assertSame('テキスト S4。', $rows[3]['text_jp']);
    }

    public function test_missing_slot_is_rejected(): void
    {
        $slots = self::slots();
        unset($slots['S5']);

        $this->expectException(InvalidArgumentException::class);

        IntegrationSlots::fromArray($slots);
    }

    public function test_extra_slot_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IntegrationSlots::fromArray([...self::slots(), 'S8' => ['text_id' => 'Ekstra.', 'text_jp' => null]]);
    }

    public function test_empty_indonesian_text_is_rejected(): void
    {
        $slots = self::slots();
        $slots['S2']['text_id'] = '  ';

        $this->expectException(InvalidArgumentException::class);

        IntegrationSlots::fromArray($slots);
    }

    public function test_japanese_text_is_optional(): void
    {
        $slots = self::slots();
        $slots['S3']['text_jp'] = null;

        $rows = IntegrationSlots::fromArray($slots)->toArray();

        $this->assertNull($rows[2]['text_jp']);
    }
}
