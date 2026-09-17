<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\DassScreeningSummary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DassScreeningSummaryTest extends TestCase
{
    public function test_summary_carries_only_general_category_material(): void
    {
        $summary = DassScreeningSummary::fromArray([
            'general_category' => 'Ringan',
            'narrative' => 'Kategori umum Ringan; tidak memengaruhi kelayakan.',
            'follow_up' => 'Tidak diperlukan tindak lanjut khusus.',
        ]);

        $this->assertSame([
            'general_category' => 'Ringan',
            'narrative' => 'Kategori umum Ringan; tidak memengaruhi kelayakan.',
            'follow_up' => 'Tidak diperlukan tindak lanjut khusus.',
        ], $summary->toArray());
    }

    public function test_subscale_data_is_structurally_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DassScreeningSummary::fromArray([
            'general_category' => 'Ringan',
            'narrative' => 'Narasi.',
            'follow_up' => 'Tindak lanjut.',
            'subscales' => [
                'd' => ['raw' => 5, 'doubled' => 10, 'category' => 'Ringan'],
            ],
        ]);
    }

    public function test_unknown_general_category_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DassScreeningSummary::fromArray([
            'general_category' => 'Ekstrem',
            'narrative' => 'Narasi.',
            'follow_up' => 'Tindak lanjut.',
        ]);
    }

    public function test_empty_narrative_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DassScreeningSummary::fromArray([
            'general_category' => 'Normal',
            'narrative' => ' ',
            'follow_up' => 'Tindak lanjut.',
        ]);
    }
}
