<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Security\RlsContextRunner;
use App\Services\ReportRendering\BladeReportRenderer;
use App\Services\ReportRendering\SignedReportDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Reports\Concerns\SeedsSignedReportCase;
use Tests\TestCase;

final class SignedReportDatasetTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSignedReportCase;

    public function test_unknown_case_or_unsigned_case_fails_closed(): void
    {
        $dataset = app(SignedReportDataset::class);

        $unknown = $dataset->hpp((string) Str::ulid());
        $this->assertFalse($unknown->isReady());
        $this->assertSame([SignedReportDataset::SNAPSHOT_NOT_FOUND], $unknown->missing);

        $case = $this->createReportCase();
        $unsigned = $dataset->hpp($case->public_id);
        $this->assertSame([SignedReportDataset::SNAPSHOT_NOT_FOUND], $unsigned->missing);
        $this->assertNull($unsigned->snapshotId);
    }

    public function test_default_adapter_blocks_only_on_the_five_unpersisted_inputs(): void
    {
        $case = $this->createReportCase();
        $snapshotId = $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $result = app(SignedReportDataset::class)->hpp($case->public_id);

        $this->assertFalse($result->isReady());
        $this->assertSame($snapshotId, $result->snapshotId);
        $this->assertSame([
            SignedReportDataset::REPORT_NUMBER_UNAVAILABLE,
            SignedReportDataset::PSYCHOLOGIST_SIPP_UNAVAILABLE,
            SignedReportDataset::RECOMMENDATION_RATIONALE_UNAVAILABLE,
            SignedReportDataset::ASPECT_LABELS_UNAVAILABLE,
            SignedReportDataset::DASS_TEXT_UNAVAILABLE,
        ], $result->missing);
    }

    public function test_complete_data_builds_the_hpp_from_the_signed_snapshot(): void
    {
        $case = $this->createReportCase();
        $psychologist = $this->reportPsychologist();
        $this->signCase($case, $psychologist, iq: 110);
        $this->seedSubmittedSession($case, '2026-09-13 04:20:00');
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $result = $this->completeDataset()->hpp($case->public_id);

        $this->assertTrue($result->isReady(), implode(',', $result->missing));
        $view = $result->draft()->toViewData();

        $this->assertSame('Peserta Sintetis Laporan', $view['identity']['participant_name']);
        $this->assertSame('T26-09-9001', $view['identity']['test_number']);
        $this->assertSame('2002-03-04', $view['identity']['birth_date']);
        $this->assertSame('Cabang Sintetis Laporan', $view['identity']['branch_name']);
        $this->assertSame('UMUM', $view['identity']['target_field']);
        $this->assertSame('2026-09-13', $view['identity']['test_date']);
        $this->assertSame(['iq' => 110, 'category' => 'Sedang'], $view['iq']);
        $this->assertSame('DISARANKAN', $view['recommendation']['label']);
        $this->assertSame('Narasi sintetis klaster A.', $view['cluster_narratives']['A']);
        $this->assertSame('Ringan', $view['dass']['general_category']);
        $this->assertSame('Psikolog Sintetis', $view['psychologist']['name']);
        $this->assertNotNull($view['psychologist']['signed_at']);
        $this->assertCount(18, $view['aspect_rows']);
        $this->assertSame(4, $view['aspect_rows'][0]['level']);
    }

    public function test_hpp_uses_psychologist_final_levels_not_system_levels(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist(), levelOverrides: [[
            'aspect' => 'A2',
            'system_level' => 4,
            'final_level' => 5,
            'reason' => 'Observasi wawancara mendukung penalaran lebih tinggi.',
        ]]);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Normal', now()->subDay()->toDateTimeString());

        $rows = $this->completeDataset()->hpp($case->public_id)->draft()->toViewData()['aspect_rows'];

        $this->assertSame('Label A2', $rows[1]['label_id']);
        $this->assertSame(5, $rows[1]['level']);
    }

    public function test_dass_uses_latest_completed_assessment_at_or_before_signing(): void
    {
        $case = $this->createReportCase();
        $this->seedDassResult($case, 'Normal', now()->subDays(20)->toDateTimeString());
        $this->seedDassResult($case, 'Sedang', now()->subDays(2)->toDateTimeString());
        $this->seedDassResult($case, 'Parah', now()->subDay()->toDateTimeString(), 'withdrawn');
        $this->signCase($case, $this->reportPsychologist());
        $this->seedDassResult($case, 'Sangat Parah', now()->addDay()->toDateTimeString());
        $this->seedSubmittedSession($case);

        $view = $this->completeDataset()->hpp($case->public_id)->draft()->toViewData();

        $this->assertSame('Sedang', $view['dass']['general_category']);
    }

    public function test_missing_or_unrecognized_dass_fails_closed(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);

        $this->assertSame(
            [SignedReportDataset::DASS_RESULT_NOT_FOUND],
            $this->completeDataset()->hpp($case->public_id)->missing,
        );

        $this->seedDassResult($case, 'PRIVATE-OVERALL', now()->subDay()->toDateTimeString());

        $this->assertSame(
            [SignedReportDataset::DASS_CATEGORY_UNRECOGNIZED],
            $this->completeDataset()->hpp($case->public_id)->missing,
        );
    }

    public function test_dass_subscales_are_never_read_or_rendered(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $draft = $this->completeDataset()->hpp($case->public_id)->draft();

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/depression|anxiety|stress/i', $sql);
        }

        $html = BladeReportRenderer::make()->renderHpp($draft);
        foreach (['SECRET-DEPRESSION', 'SECRET-ANXIETY', 'SECRET-STRESS'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $html);
        }
        $this->assertStringContainsString('Ringan', $html);
    }

    public function test_missing_test_number_and_test_date_fail_closed(): void
    {
        $case = $this->createReportCase(testNumber: null);
        $this->signCase($case, $this->reportPsychologist());
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $this->assertSame([
            SignedReportDataset::TEST_NUMBER_MISSING,
            SignedReportDataset::TEST_DATE_UNAVAILABLE,
        ], $this->completeDataset()->hpp($case->public_id)->missing);
    }

    public function test_unknown_ist_instrument_version_blocks_iq_category(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        DB::table('instrument_versions')->where('version', self::IST_VERSION)->update(['checksum' => str_repeat('0', 64)]);

        $this->assertSame(
            [SignedReportDataset::IQ_CATEGORY_UNAVAILABLE],
            $this->completeDataset()->hpp($case->public_id)->missing,
        );
    }

    public function test_nougyou_field_case_renders(): void
    {
        $case = $this->createReportCase(field: 'NOUGYOU');
        $this->signCase($case, $this->reportPsychologist(), field: 'NOUGYOU');
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $result = $this->completeDataset()->hpp($case->public_id);

        $this->assertTrue($result->isReady(), implode(',', $result->missing));
        $this->assertSame('NOUGYOU', $result->identity()->targetField());
    }

    private function completeDataset(): SignedReportDataset
    {
        return new SignedReportDataset(app(RlsContextRunner::class), $this->completeSupplementalData());
    }
}
