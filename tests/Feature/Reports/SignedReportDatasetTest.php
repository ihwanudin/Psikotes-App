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

    public function test_default_adapter_blocks_only_on_the_two_unpersisted_blocking_inputs(): void
    {
        $case = $this->createReportCase();
        $snapshotId = $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $result = app(SignedReportDataset::class)->hpp($case->public_id);

        $this->assertFalse($result->isReady());
        $this->assertSame($snapshotId, $result->snapshotId);
        $this->assertSame([
            SignedReportDataset::RECOMMENDATION_RATIONALE_UNAVAILABLE,
            SignedReportDataset::ASPECT_LABELS_UNAVAILABLE,
        ], $result->missing);
        // Report number and DASS text are warnings, not blockers.
        $this->assertSame([
            SignedReportDataset::REPORT_NUMBER_NOT_YET_ISSUED,
            SignedReportDataset::DASS_TEXT_UNAVAILABLE,
        ], $result->warnings);
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
        $this->assertSame('SILP-SYNTH-0001', $view['psychologist']['silp_number']);
        $this->assertSame('STR-SYNTH-0001', $view['psychologist']['str_number']);
        $this->assertSame(config('report.facility_name'), $view['psychologist']['facility_name']);
        $this->assertSame(config('report.facility_address'), $view['psychologist']['facility_address']);
        $this->assertNotNull($view['psychologist']['signed_at']);
        $this->assertCount(18, $view['aspect_rows']);
        $this->assertSame(4, $view['aspect_rows'][0]['level']);
    }

    /**
     * Lead's 2026-09-21 requirement: prove the five Template HPP v2.3
     * Bagian I.C fields actually appear in the rendered document, not
     * just that the draft object carries them.
     */
    public function test_render_prints_all_five_psychologist_identity_fields(): void
    {
        $case = $this->createReportCase();
        $psychologist = $this->reportPsychologist(silpNumber: 'SILP-RENDER-CHECK', strNumber: 'STR-RENDER-CHECK');
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $html = BladeReportRenderer::make()->renderHpp($this->completeDataset()->hpp($case->public_id)->draft());

        $this->assertStringContainsString('Psikolog Sintetis', $html);
        $this->assertStringContainsString('SILP-RENDER-CHECK', $html);
        $this->assertStringContainsString('STR-RENDER-CHECK', $html);
        $this->assertStringContainsString(e((string) config('report.facility_name')), $html);
        $this->assertStringContainsString(e((string) config('report.facility_address')), $html);
        $this->assertStringContainsString('No. SILP', $html);
        $this->assertStringContainsString('No. STR', $html);
        $this->assertStringContainsString('Fasilitas Layanan Psikologi', $html);
        $this->assertStringContainsString('Alamat Fasilitas', $html);
        $this->assertStringNotContainsString('No. SIPP', $html);
    }

    /**
     * Lead verified this as a real PR #39 defect: facility_name previously
     * read the case's branch, not the publisher's own fixed identity.
     * Deliberately makes the two different so a regression back to
     * branch_name would fail this test, not just look plausible.
     */
    public function test_facility_identity_comes_from_config_not_the_case_branch(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->assertNotSame('Cabang Sintetis Laporan', config('report.facility_name'));

        $view = $this->completeDataset()->hpp($case->public_id)->draft()->toViewData();

        $this->assertSame(config('report.facility_name'), $view['psychologist']['facility_name']);
        $this->assertNotSame('Cabang Sintetis Laporan', $view['psychologist']['facility_name']);
    }

    public function test_missing_facility_name_config_fails_closed(): void
    {
        config(['report.facility_name' => '']);
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $this->assertContains(
            SignedReportDataset::FACILITY_NAME_MISSING,
            $this->completeDataset()->hpp($case->public_id)->missing,
        );
    }

    public function test_missing_facility_address_config_fails_closed(): void
    {
        config(['report.facility_address' => '   ']);
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $this->assertContains(
            SignedReportDataset::FACILITY_ADDRESS_MISSING,
            $this->completeDataset()->hpp($case->public_id)->missing,
        );
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

    public function test_missing_or_unrecognized_dass_warns_but_never_blocks_publication(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);

        $noResult = $this->completeDataset()->hpp($case->public_id);
        $this->assertTrue($noResult->isReady(), implode(',', $noResult->missing));
        $this->assertSame([SignedReportDataset::DASS_RESULT_NOT_FOUND], $noResult->warnings);
        $this->assertNull($noResult->draft()->toViewData()['dass']);

        $this->seedDassResult($case, 'PRIVATE-OVERALL', now()->subDay()->toDateTimeString());

        $unrecognized = $this->completeDataset()->hpp($case->public_id);
        $this->assertTrue($unrecognized->isReady());
        $this->assertSame([SignedReportDataset::DASS_CATEGORY_UNRECOGNIZED], $unrecognized->warnings);
        $this->assertNull($unrecognized->draft()->toViewData()['dass']);
    }

    public function test_report_without_dass_shows_a_dash_rather_than_implying_no_findings(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);

        $html = BladeReportRenderer::make()->renderHpp($this->completeDataset()->hpp($case->public_id)->draft());

        $this->assertStringContainsString('Kategori umum: <strong>&ndash;</strong>', $html);
        $this->assertStringContainsString('Hasil skrining tidak tersedia.', $html);
        foreach (['Normal', 'Ringan', 'Sedang', 'Parah'] as $category) {
            $this->assertStringNotContainsString('Kategori umum: <strong>'.$category.'</strong>', $html);
        }
    }

    public function test_dass_text_without_follow_up_omits_the_follow_up_line(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Normal', now()->subDay()->toDateTimeString());

        $dataset = new SignedReportDataset(app(RlsContextRunner::class), $this->supplementalWithoutDassFollowUp());
        $view = $dataset->hpp($case->public_id)->draft()->toViewData();

        $this->assertSame('Normal', $view['dass']['general_category']);
        $this->assertNull($view['dass']['follow_up']);
    }

    public function test_report_carries_the_official_limitations_and_legal_basis_clauses(): void
    {
        $case = $this->createReportCase();
        $this->signCase($case, $this->reportPsychologist());
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());

        $html = BladeReportRenderer::make()->renderHpp($this->completeDataset()->hpp($case->public_id)->draft());

        // Bagian V of the v2.3 template: three clauses plus the legal basis.
        $this->assertStringContainsString('Sifat hasil pemeriksaan', $html);
        $this->assertStringContainsString('prediktif-probabilistik', $html);
        $this->assertStringContainsString('Kerahasiaan &amp; pelindungan data', $html);
        $this->assertStringContainsString('Larangan penggunaan', $html);
        foreach (['No. 23 Tahun 2022', 'No. 18 Tahun 2017', 'No. 27 Tahun 2022'] as $law) {
            $this->assertStringContainsString($law, $html);
        }
        $this->assertStringContainsString('tidak digunakan sebagai dasar penetapan rekomendasi', $html);
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
