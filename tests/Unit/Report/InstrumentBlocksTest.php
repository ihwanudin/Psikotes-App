<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\DassInternalDetail;
use App\Domain\Report\IstResultBlock;
use App\Domain\Report\KraepelinResultBlock;
use App\Domain\Report\PapiResultBlock;
use App\Domain\Report\RmibResultBlock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InstrumentBlocksTest extends TestCase
{
    public function test_ist_block_keeps_nine_subtests_in_canonical_order(): void
    {
        $subtests = [];
        foreach (IstResultBlock::SUBTESTS as $code) {
            $subtests[$code] = ['sw' => 100, 'level' => 3, 'label' => 'Sedang'];
        }

        $block = IstResultBlock::fromArray(['iq' => 108, 'iq_category' => 'Cukup Baik', 'subtests' => $subtests]);

        $this->assertSame(IstResultBlock::SUBTESTS, array_keys($block->toArray()['subtests']));
        $this->assertSame(108, $block->toArray()['iq']);
    }

    public function test_ist_block_rejects_missing_subtest(): void
    {
        $subtests = [];
        foreach (IstResultBlock::SUBTESTS as $code) {
            if ($code !== 'ME') {
                $subtests[$code] = ['sw' => 100, 'level' => 3, 'label' => 'Sedang'];
            }
        }

        $this->expectException(InvalidArgumentException::class);

        IstResultBlock::fromArray(['iq' => 108, 'iq_category' => 'Cukup Baik', 'subtests' => $subtests]);
    }

    public function test_kraepelin_block_requires_all_four_factors(): void
    {
        $factors = [
            'panker' => ['value' => '13,120', 'level' => 4, 'band' => 'Baik'],
            'tianker' => ['value' => '5', 'level' => 4, 'band' => 'Baik'],
            'hanker' => ['value' => '5,032', 'level' => 5, 'band' => 'Baik Sekali'],
            'janker' => ['value' => '6', 'level' => 4, 'band' => 'Baik'],
        ];

        $block = KraepelinResultBlock::fromArray(['norm_group' => 'SMA/SMK', 'factors' => $factors]);

        $this->assertSame(['panker', 'tianker', 'hanker', 'janker'], array_keys($block->toArray()['factors']));

        unset($factors['janker']);

        $this->expectException(InvalidArgumentException::class);

        KraepelinResultBlock::fromArray(['norm_group' => 'SMA/SMK', 'factors' => $factors]);
    }

    public function test_papi_qualitative_scales_never_carry_a_level(): void
    {
        $scales = [];
        foreach (PapiResultBlock::SCALES as $code) {
            $qualitative = in_array($code, PapiResultBlock::QUALITATIVE_SCALES, true);
            $scales[$code] = ['raw' => 4, 'level' => $qualitative ? null : 4, 'qualitative' => $qualitative];
        }

        $block = PapiResultBlock::fromArray(['scales' => $scales]);

        foreach (PapiResultBlock::QUALITATIVE_SCALES as $code) {
            $this->assertNull($block->toArray()[$code]['level']);
        }
    }

    public function test_papi_scale_with_inconsistent_qualitative_flag_is_rejected(): void
    {
        $scales = [];
        foreach (PapiResultBlock::SCALES as $code) {
            $qualitative = in_array($code, PapiResultBlock::QUALITATIVE_SCALES, true);
            $scales[$code] = ['raw' => 4, 'level' => $qualitative ? null : 4, 'qualitative' => $qualitative];
        }
        $scales['G']['qualitative'] = false;

        $this->expectException(InvalidArgumentException::class);

        PapiResultBlock::fromArray(['scales' => $scales]);
    }

    public function test_rmib_ranks_must_form_a_permutation_and_are_sorted_by_preference(): void
    {
        $categories = [
            'out' => ['label' => 'Luas', 'rank' => 9],
            'mech' => ['label' => 'Mekanik', 'rank' => 6],
            'prac' => ['label' => 'Praktik', 'rank' => 4],
            'med' => ['label' => 'Medis', 'rank' => 2],
            'socsvc' => ['label' => 'Layanan Sosial', 'rank' => 3],
            'aesth' => ['label' => 'Estetik', 'rank' => 11],
            'sci' => ['label' => 'Sains', 'rank' => 7],
            'bus' => ['label' => 'Bisnis', 'rank' => 8],
            'cler' => ['label' => 'Klerikal', 'rank' => 5],
            'comm' => ['label' => 'Komunikasi', 'rank' => 10],
            'lit' => ['label' => 'Sastra', 'rank' => 12],
            'mus' => ['label' => 'Musik', 'rank' => 1],
        ];

        $block = RmibResultBlock::fromArray(['categories' => $categories]);
        $ranked = $block->rankedCategories();

        $this->assertSame('mus', $ranked[0]['code']);
        $this->assertSame('lit', $ranked[11]['code']);
        $this->assertSame('D4', $block->toArray()['med']['d_aspect']);
        $this->assertNull($block->toArray()['sci']['d_aspect']);

        $categories['med']['rank'] = 1;

        $this->expectException(InvalidArgumentException::class);

        RmibResultBlock::fromArray(['categories' => $categories]);
    }

    public function test_dass_internal_detail_requires_doubled_scores(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DassInternalDetail::fromArray([
            'subscales' => [
                'd' => ['raw' => 5, 'doubled' => 11, 'category' => 'Ringan'],
                'a' => ['raw' => 3, 'doubled' => 6, 'category' => 'Normal'],
                's' => ['raw' => 8, 'doubled' => 16, 'category' => 'Ringan'],
            ],
            'general_category' => 'Ringan',
            'follow_up' => 'Tidak diperlukan rujukan.',
            'flags' => [],
        ]);
    }
}
