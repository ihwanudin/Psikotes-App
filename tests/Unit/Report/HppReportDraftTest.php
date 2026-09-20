<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Domain\Report\DassScreeningSummary;
use App\Domain\Report\HppReportDraft;
use App\Domain\Report\ReportAspectGrid;
use App\Domain\Report\ReportIdentity;
use App\Services\ReportRendering\FixtureReportDataset;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HppReportDraftTest extends TestCase
{
    public function test_dipertimbangkan_requires_written_accompaniment_conditions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('G9');

        self::draft('DIPERTIMBANGKAN', null);
    }

    public function test_unknown_recommendation_label_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::draft('LANJUTKAN', 'Syarat.');
    }

    public function test_view_data_excludes_internal_only_material(): void
    {
        $viewData = self::draft('DISARANKAN', null)->toViewData();

        $this->assertSame(
            ['identity', 'iq', 'aspect_rows', 'cluster_narratives', 'dass', 'recommendation', 'psychologist'],
            array_keys($viewData),
        );
        $this->assertSame(['general_category', 'narrative', 'follow_up'], array_keys($viewData['dass']));
    }

    public function test_fixture_hpp_draft_is_deterministic_and_privacy_safe(): void
    {
        $first = FixtureReportDataset::hppDraft()->toViewData();
        $second = FixtureReportDataset::hppDraft()->toViewData();

        $this->assertSame($first, $second);
        $this->assertSame('DIPERTIMBANGKAN', $first['recommendation']['label']);
        $this->assertNotNull($first['recommendation']['accompaniment_conditions']);
        $this->assertNull($first['psychologist']);

        foreach ($first['aspect_rows'] as $row) {
            $this->assertArrayNotHasKey('code', $row);
            $this->assertArrayNotHasKey('standard', $row);
        }
    }

    public function test_fixture_hpp_draft_accepts_optional_psychologist_block(): void
    {
        $draft = FixtureReportDataset::hppDraft(FixtureReportDataset::psychologist());

        $this->assertSame('SILP-00000000', $draft->psychologist()?->toArray()['silp_number']);
        $this->assertSame('STR-00000000', $draft->psychologist()?->toArray()['str_number']);
    }

    private static function draft(string $label, ?string $accompaniment): HppReportDraft
    {
        $rows = [];
        foreach (ReportAspectGrid::ASPECTS as $code) {
            $rows[] = ['code' => $code, 'label_id' => "Aspek {$code}", 'label_jp' => 'ラベル', 'level' => 4, 'standard' => 3];
        }

        $narratives = [
            'A' => 'Narasi A.', 'B' => 'Narasi B.', 'C' => 'Narasi C.', 'D' => 'Narasi D.',
        ];

        return HppReportDraft::create(
            ReportIdentity::fromArray([
                'report_number' => 'HPP-TEST-0001',
                'participant_name' => 'Peserta Uji',
                'test_number' => 'T26-09-0002',
                'birth_date' => '2000-01-01',
                'education' => 'SMA/SMK',
                'branch_name' => 'Cabang Uji',
                'target_field' => 'UMUM',
                'standard_version' => 'GA-2026.08',
                'test_date' => '2026-09-14',
            ]),
            ReportAspectGrid::fromArray($rows),
            100,
            'Sedang',
            $narratives,
            DassScreeningSummary::fromArray([
                'general_category' => 'Normal',
                'narrative' => 'Narasi DASS.',
                'follow_up' => 'Tidak ada.',
            ]),
            $label,
            'Rationale uji.',
            $accompaniment,
            null,
        );
    }
}
