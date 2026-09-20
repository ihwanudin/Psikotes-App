<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Services\ReportRendering\ReportDocumentIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\Feature\Reports\Concerns\SeedsSignedReportCase;
use Tests\TestCase;

final class ReportDocumentIssuerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSignedReportCase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');
    }

    public function test_first_issue_renders_stores_and_records_the_document(): void
    {
        $case = $this->createReportCase();
        $psychologist = $this->reportPsychologist();
        $snapshotId = $this->signCase($case, $psychologist);

        $calls = 0;
        $result = app(ReportDocumentIssuer::class)->issue(
            $case->id, $snapshotId, 'hpp', 1,
            (int) $psychologist->id, $psychologist->name, 'SILP-TEST-0001', 'STR-TEST-0001', 'Unit Layanan Psikologi',
            function (string $reportNumber) use (&$calls): string {
                $calls++;

                return '%PDF-1.4 rendered-for-'.$reportNumber;
            },
        );

        $this->assertSame(1, $calls);
        $this->assertFalse($result['reused']);
        $this->assertMatchesRegularExpression('#^HPP/\d{4}/\d{2}/\d{4}$#', $result['report_number']);
        $this->assertNotSame('', $result['url']);
        Storage::disk('reports')->assertExists($result['object_key']);

        $row = DB::table('report_documents')->where('signing_snapshot_id', $snapshotId)->sole();
        $this->assertSame($case->id, (int) $row->assessment_case_id);
        $this->assertSame('hpp', $row->document_type);
        $this->assertSame($result['report_number'], $row->report_number);
        $this->assertSame(1, (int) $row->report_version);
        $this->assertSame(1, (int) $row->render_seq);
        $this->assertSame($result['object_key'], $row->object_key);
        $this->assertSame('SILP-TEST-0001', $row->psychologist_silp_snapshot);
        $this->assertSame('STR-TEST-0001', $row->psychologist_str_snapshot);
    }

    /**
     * The DoD's core requirement (tasks/handoffs/f6/report-documents-schema-proposal.md
     * §9 point 1): reusing an existing render must not call the renderer
     * again, and must return the identical object_key.
     */
    public function test_second_issue_reuses_the_existing_object_without_rendering_again(): void
    {
        $case = $this->createReportCase();
        $psychologist = $this->reportPsychologist();
        $snapshotId = $this->signCase($case, $psychologist);
        $issuer = app(ReportDocumentIssuer::class);

        $first = $issuer->issue(
            $case->id, $snapshotId, 'hpp', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', 'STR-1', 'Facility',
            static fn (string $n): string => '%PDF-1.4 x-'.$n,
        );

        $calls = 0;
        $second = $issuer->issue(
            $case->id, $snapshotId, 'hpp', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', 'STR-1', 'Facility',
            function (string $reportNumber) use (&$calls): string {
                $calls++;

                throw new RuntimeException('renderPdf must not be called when a reusable object exists.');
            },
        );

        $this->assertSame(0, $calls);
        $this->assertTrue($second['reused']);
        $this->assertSame($first['object_key'], $second['object_key']);
        $this->assertSame($first['report_number'], $second['report_number']);
        $this->assertCount(
            1,
            DB::table('report_documents')->where('signing_snapshot_id', $snapshotId)->where('document_type', 'hpp')->get(),
        );
    }

    public function test_re_renders_with_a_new_render_seq_when_the_object_is_missing_from_storage(): void
    {
        $case = $this->createReportCase();
        $psychologist = $this->reportPsychologist();
        $snapshotId = $this->signCase($case, $psychologist);
        $issuer = app(ReportDocumentIssuer::class);

        $first = $issuer->issue(
            $case->id, $snapshotId, 'hpp', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', null, 'Facility',
            static fn (string $n): string => '%PDF-1.4 x-'.$n,
        );
        Storage::disk('reports')->delete($first['object_key']);

        $calls = 0;
        $second = $issuer->issue(
            $case->id, $snapshotId, 'hpp', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', null, 'Facility',
            function (string $reportNumber) use (&$calls): string {
                $calls++;

                return '%PDF-1.4 rerendered';
            },
        );

        $this->assertSame(1, $calls);
        $this->assertFalse($second['reused']);
        $this->assertNotSame($first['object_key'], $second['object_key']);
        $this->assertSame($first['report_number'], $second['report_number']);

        $rows = DB::table('report_documents')
            ->where('signing_snapshot_id', $snapshotId)->where('document_type', 'hpp')
            ->orderBy('render_seq')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows[0]->render_seq);
        $this->assertSame(2, (int) $rows[1]->render_seq);
    }

    public function test_internal_and_hpp_documents_for_the_same_snapshot_share_the_report_number(): void
    {
        $case = $this->createReportCase();
        $psychologist = $this->reportPsychologist();
        $snapshotId = $this->signCase($case, $psychologist);
        $issuer = app(ReportDocumentIssuer::class);
        $render = static fn (string $n): string => '%PDF-1.4 x-'.$n;

        $hpp = $issuer->issue($case->id, $snapshotId, 'hpp', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', null, 'Facility', $render);
        $internal = $issuer->issue($case->id, $snapshotId, 'internal', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', null, 'Facility', $render);

        $this->assertSame($hpp['report_number'], $internal['report_number']);
        $this->assertNotSame($hpp['object_key'], $internal['object_key']);
    }

    /**
     * Lead's requirement 2026-09-20: a report number must never be burned
     * for a render that failed. issue() runs entirely inside one
     * RlsContextRunner::runAsService() transaction, so a renderPdf()
     * failure rolls back the ReportNumberIssuer increment along with
     * everything else — verified here, not just reasoned about.
     */
    public function test_a_failed_render_does_not_advance_the_report_number_sequence(): void
    {
        $case = $this->createReportCase();
        $psychologist = $this->reportPsychologist();
        $snapshotId = $this->signCase($case, $psychologist);
        $period = now()->format('Ym');

        try {
            app(ReportDocumentIssuer::class)->issue(
                $case->id, $snapshotId, 'hpp', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', null, 'Facility',
                function (): string {
                    throw new RuntimeException('rendering failed');
                },
            );
            $this->fail('Expected the render failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('rendering failed', $e->getMessage());
        }

        $this->assertSame(0, DB::table('report_documents')->count());
        $sequence = DB::table('report_number_sequences')->where('period', $period)->first();
        $this->assertTrue($sequence === null || (int) $sequence->last_value === 0);

        // The next successful issue still starts at 0001, proving nothing
        // was silently burned by the failed attempt above.
        $result = app(ReportDocumentIssuer::class)->issue(
            $case->id, $snapshotId, 'hpp', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', null, 'Facility',
            static fn (string $n): string => '%PDF-1.4 ok-'.$n,
        );
        $this->assertSame('HPP/'.substr($period, 0, 4).'/'.substr($period, 4, 2).'/0001', $result['report_number']);
    }

    public function test_unknown_document_type_is_rejected_before_rendering(): void
    {
        $case = $this->createReportCase();
        $psychologist = $this->reportPsychologist();
        $snapshotId = $this->signCase($case, $psychologist);

        $this->expectException(InvalidArgumentException::class);

        app(ReportDocumentIssuer::class)->issue(
            $case->id, $snapshotId, 'summary', 1, (int) $psychologist->id, $psychologist->name, 'SILP-1', null, 'Facility',
            function (): string {
                throw new RuntimeException('must not be called');
            },
        );
    }
}
