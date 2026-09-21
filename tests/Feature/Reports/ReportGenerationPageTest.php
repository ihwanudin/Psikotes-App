<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\AdminRole;
use App\Filament\Pages\ReportGeneration;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use App\Services\ReportRendering\ReportDocumentIssuer;
use App\Services\ReportRendering\ReportSupplementalData;
use App\Services\ReportRendering\SignedReportDataset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Reports\Concerns\SeedsSignedReportCase;
use Tests\TestCase;

final class ReportGenerationPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSignedReportCase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Storage::fake('reports');
    }

    /**
     * GenerateReports = Psychologist + SuperAdmin (Lead's 2026-09-21
     * decision): SuperAdmin may download an already-signed report;
     * BranchAdmin/Staff may not, since opening that up needs a
     * branch-ownership restriction that doesn't exist yet.
     */
    public function test_psychologists_and_super_admins_can_open_the_page(): void
    {
        $case = $this->createReportCase();

        foreach ([$this->reportPsychologist(), $this->admin(AdminRole::SuperAdmin)] as $admin) {
            $this->actingAs($admin, 'admin');

            Livewire::test(ReportGeneration::class, ['case' => $case->public_id])->assertOk();
        }
    }

    public function test_branch_admins_and_staff_cannot_open_the_page(): void
    {
        $case = $this->createReportCase();

        foreach ([AdminRole::BranchAdmin, AdminRole::Staff] as $role) {
            $this->actingAs($this->admin($role, (int) $case->organization_id), 'admin');

            Livewire::test(ReportGeneration::class, ['case' => $case->public_id])->assertNotFound();
        }

        $this->assertFalse(ReportGeneration::shouldRegisterNavigation());
    }

    public function test_route_carries_the_case_identity(): void
    {
        $case = $this->createReportCase();

        $url = ReportGeneration::getUrl(['case' => $case->public_id], panel: 'admin');

        $this->assertStringEndsWith('/admin/report-generation/'.$case->public_id, $url);
    }

    public function test_real_http_request_renders_for_psychologist(): void
    {
        $psychologist = $this->reportPsychologist();
        $case = $this->createReportCase();

        $this->actingAs($psychologist, 'admin')
            ->get('/admin/report-generation/'.$case->public_id)
            ->assertOk()
            ->assertSee('PDF belum dapat dibuat')
            ->assertSee(SignedReportDataset::SNAPSHOT_NOT_FOUND);
    }

    public function test_real_http_request_renders_for_super_admin(): void
    {
        $superAdmin = $this->admin(AdminRole::SuperAdmin);
        $case = $this->createReportCase();

        $this->actingAs($superAdmin, 'admin')
            ->get('/admin/report-generation/'.$case->public_id)
            ->assertOk()
            ->assertSee('PDF belum dapat dibuat')
            ->assertSee(SignedReportDataset::SNAPSHOT_NOT_FOUND);
    }

    public function test_real_http_request_is_concealed_for_branch_scoped_roles(): void
    {
        $case = $this->createReportCase();
        $branchId = (int) $case->organization_id;

        foreach ([AdminRole::BranchAdmin, AdminRole::Staff] as $role) {
            $admin = $this->admin($role, $branchId);
            // Each role is a fresh browser session; AuthenticateSession would
            // otherwise log out on the previous role's password hash.
            $this->flushSession();
            $this->app['auth']->forgetGuards();

            $this->actingAs($admin, 'admin')
                ->get('/admin/report-generation/'.$case->public_id)
                ->assertNotFound();
        }
    }

    public function test_real_http_request_redirects_guests_to_login(): void
    {
        $case = $this->createReportCase();

        $this->get('/admin/report-generation/'.$case->public_id)
            ->assertRedirect('/admin/login');
    }

    public function test_real_http_request_rejects_malformed_case_ids(): void
    {
        $this->actingAs($this->reportPsychologist(), 'admin')
            ->get('/admin/report-generation/not-a-ulid')
            ->assertNotFound();
    }

    public function test_incomplete_data_lists_gaps_and_never_publishes(): void
    {
        $psychologist = $this->reportPsychologist();
        $case = $this->createReportCase();
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->actingAs($psychologist, 'admin');

        Livewire::test(ReportGeneration::class, ['case' => $case->public_id])
            ->assertSet('ready', false)
            ->assertSee('PDF belum dapat dibuat')
            ->assertSee(SignedReportDataset::REPORT_NUMBER_NOT_YET_ISSUED)
            ->assertDontSee('Buat PDF HPP</button>', false)
            ->call('generate')
            ->assertSet('published', null);

        $this->assertSame([], Storage::disk('reports')->allFiles());
    }

    public function test_unsigned_case_cannot_produce_a_pdf(): void
    {
        $psychologist = $this->reportPsychologist();
        $case = $this->createReportCase();
        $this->actingAs($psychologist, 'admin');
        $this->app->instance(SignedReportDataset::class, $this->completeDataset());

        Livewire::test(ReportGeneration::class, ['case' => $case->public_id])
            ->assertSee(SignedReportDataset::SNAPSHOT_NOT_FOUND)
            ->call('generate')
            ->assertSet('published', null);

        $this->assertSame([], Storage::disk('reports')->allFiles());
    }

    public function test_complete_signed_case_generates_a_private_pdf_and_short_lived_link(): void
    {
        $psychologist = $this->reportPsychologist();
        $case = $this->createReportCase();
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->actingAs($psychologist, 'admin');
        $this->app->instance(SignedReportDataset::class, $this->completeDataset());

        $component = Livewire::test(ReportGeneration::class, ['case' => $case->public_id])
            ->assertSet('ready', true)
            ->call('generate');

        $published = $component->get('published');
        $this->assertIsArray($published);
        $this->assertNotSame('', $published['url']);

        $files = Storage::disk('reports')->allFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^reports\/hpp\/[a-z0-9]{2}\/[a-z0-9]{62}\.pdf$/', $files[0]);
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk('reports')->get($files[0]));
        $this->assertStringNotContainsString('T26-09-9001', $files[0]);

        // The issuer's ledger row exists — this is what the old
        // ReportDocumentPublisher::publish() path never produced.
        $row = DB::table('report_documents')->where('object_key', $files[0])->sole();
        $this->assertSame('hpp', $row->document_type);
        $this->assertSame((int) $case->id, (int) $row->assessment_case_id);
        $this->assertSame((int) $psychologist->id, (int) $row->psychologist_admin_id);
        $this->assertSame('SILP-SYNTH-0001', $row->psychologist_silp_snapshot);
        $this->assertSame('STR-SYNTH-0001', $row->psychologist_str_snapshot);
        $this->assertMatchesRegularExpression('#^HPP/\d{4}/\d{2}/\d{4}$#', $row->report_number);
    }

    /**
     * The existing super_admin tests above only prove the page opens
     * (200/assertOk) - not that the `generate()` action itself succeeds for
     * that role. GenerateReports = Psychologist + SuperAdmin (Lead's
     * 2026-09-21 decision) covers the action, not just page access.
     */
    public function test_super_admin_can_generate_an_already_signed_report(): void
    {
        $psychologist = $this->reportPsychologist();
        $superAdmin = $this->admin(AdminRole::SuperAdmin);
        $case = $this->createReportCase();
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->actingAs($superAdmin, 'admin');
        $this->app->instance(SignedReportDataset::class, $this->completeDataset());

        $component = Livewire::test(ReportGeneration::class, ['case' => $case->public_id])
            ->assertSet('ready', true)
            ->call('generate');

        $published = $component->get('published');
        $this->assertIsArray($published);
        $this->assertNotSame('', $published['url']);

        $files = Storage::disk('reports')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk('reports')->get($files[0]));

        // report_documents has no "generated by" column - psychologist_*
        // always snapshots whoever SIGNED (their SILP/STR must appear on
        // the printed report), independent of who clicked generate. Proves
        // super_admin generating doesn't corrupt that attribution.
        $row = DB::table('report_documents')->where('object_key', $files[0])->sole();
        $this->assertSame('hpp', $row->document_type);
        $this->assertSame((int) $case->id, (int) $row->assessment_case_id);
        $this->assertSame((int) $psychologist->id, (int) $row->psychologist_admin_id);
    }

    public function test_second_click_reuses_the_existing_pdf_without_rendering_again(): void
    {
        $psychologist = $this->reportPsychologist();
        $case = $this->createReportCase();
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->actingAs($psychologist, 'admin');
        $this->app->instance(SignedReportDataset::class, $this->completeDataset());

        $component = Livewire::test(ReportGeneration::class, ['case' => $case->public_id])
            ->call('generate');
        $first = $component->get('published');
        $firstReportNumber = DB::table('report_documents')->value('report_number');

        $component->call('generate');
        $second = $component->get('published');

        $this->assertNotSame('', $first['url']);
        $this->assertNotSame('', $second['url']);
        $this->assertCount(1, Storage::disk('reports')->allFiles(), 'A second click must not render a new file.');
        $this->assertSame(1, DB::table('report_documents')->count(), 'A second click must not insert a new ledger row.');
        $this->assertSame($firstReportNumber, DB::table('report_documents')->value('report_number'));
    }

    public function test_missing_silp_fails_closed_with_a_clear_message_not_a_blank_print(): void
    {
        $psychologist = $this->reportPsychologist(silpNumber: null);
        $case = $this->createReportCase();
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->actingAs($psychologist, 'admin');
        $this->app->instance(SignedReportDataset::class, $this->completeDataset());

        Livewire::test(ReportGeneration::class, ['case' => $case->public_id])
            ->assertSet('ready', false)
            ->assertSee(SignedReportDataset::PSYCHOLOGIST_SILP_MISSING)
            ->call('generate')
            ->assertSet('published', null);

        $this->assertSame([], Storage::disk('reports')->allFiles());
        $this->assertSame(0, DB::table('report_documents')->count());
    }

    public function test_missing_str_fails_closed_with_a_clear_message_not_a_blank_print(): void
    {
        $psychologist = $this->reportPsychologist(strNumber: null);
        $case = $this->createReportCase();
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->actingAs($psychologist, 'admin');
        $this->app->instance(SignedReportDataset::class, $this->completeDataset());

        Livewire::test(ReportGeneration::class, ['case' => $case->public_id])
            ->assertSet('ready', false)
            ->assertSee(SignedReportDataset::PSYCHOLOGIST_STR_MISSING)
            ->call('generate')
            ->assertSet('published', null);

        $this->assertSame([], Storage::disk('reports')->allFiles());
        $this->assertSame(0, DB::table('report_documents')->count());
    }

    public function test_preview_shows_not_yet_issued_warning_for_a_first_time_case(): void
    {
        $psychologist = $this->reportPsychologist();
        $case = $this->createReportCase();
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->actingAs($psychologist, 'admin');
        $this->app->instance(SignedReportDataset::class, $this->datasetWithoutReportNumber());

        Livewire::test(ReportGeneration::class, ['case' => $case->public_id])
            ->assertSet('ready', true)
            ->assertSee(SignedReportDataset::REPORT_NUMBER_NOT_YET_ISSUED);

        $this->assertSame(0, DB::table('report_documents')->count());
    }

    public function test_preview_shows_the_real_existing_number_without_creating_a_row(): void
    {
        $psychologist = $this->reportPsychologist();
        $case = $this->createReportCase();
        $this->signCase($case, $psychologist);
        $this->seedSubmittedSession($case);
        $this->seedDassResult($case, 'Ringan', now()->subDay()->toDateTimeString());
        $this->actingAs($psychologist, 'admin');
        // Real DB-backed reportNumber() lookup (not the fixed test string),
        // so this actually proves the read-only lookup, not just that some
        // number was echoed back.
        $this->app->instance(SignedReportDataset::class, $this->datasetWithRealReportNumberLookup());
        Livewire::test(ReportGeneration::class, ['case' => $case->public_id])->call('generate');
        $issuedNumber = (string) DB::table('report_documents')->value('report_number');
        $countAfterFirstGenerate = DB::table('report_documents')->count();
        $this->assertNotSame('', $issuedNumber);

        $preview = Livewire::test(ReportGeneration::class, ['case' => $case->public_id]);

        $preview->assertSet('ready', true)->assertDontSee(SignedReportDataset::REPORT_NUMBER_NOT_YET_ISSUED);
        $this->assertSame($countAfterFirstGenerate, DB::table('report_documents')->count(), 'A preview must never insert a ledger row.');
    }

    private function datasetWithRealReportNumberLookup(): SignedReportDataset
    {
        $complete = $this->completeSupplementalData();
        $issuer = app(ReportDocumentIssuer::class);

        $supplemental = new class($complete, $issuer) implements ReportSupplementalData
        {
            public function __construct(
                private ReportSupplementalData $inner,
                private ReportDocumentIssuer $issuer,
            ) {}

            public function reportNumber(int $assessmentCaseId, string $snapshotId): ?string
            {
                return $this->issuer->existingReportNumberFor($assessmentCaseId);
            }

            public function recommendationRationale(string $snapshotId): string
            {
                return (string) $this->inner->recommendationRationale($snapshotId);
            }

            public function aspectLabels(string $standardVersion): array
            {
                return (array) $this->inner->aspectLabels($standardVersion);
            }

            public function dassScreeningText(string $generalCategory): array
            {
                return (array) $this->inner->dassScreeningText($generalCategory);
            }
        };

        return new SignedReportDataset(app(RlsContextRunner::class), $supplemental);
    }

    private function datasetWithoutReportNumber(): SignedReportDataset
    {
        $complete = $this->completeSupplementalData();

        $supplemental = new class($complete) implements ReportSupplementalData
        {
            public function __construct(private ReportSupplementalData $inner) {}

            public function reportNumber(int $assessmentCaseId, string $snapshotId): ?string
            {
                return null;
            }

            public function recommendationRationale(string $snapshotId): string
            {
                return (string) $this->inner->recommendationRationale($snapshotId);
            }

            public function aspectLabels(string $standardVersion): array
            {
                return (array) $this->inner->aspectLabels($standardVersion);
            }

            public function dassScreeningText(string $generalCategory): array
            {
                return (array) $this->inner->dassScreeningText($generalCategory);
            }
        };

        return new SignedReportDataset(app(RlsContextRunner::class), $supplemental);
    }

    private function admin(AdminRole $role, ?int $branchId = null): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branchId,
            'name' => 'Admin '.$role->value,
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => $role->value,
        ]);
    }

    private function completeDataset(): SignedReportDataset
    {
        return new SignedReportDataset(app(RlsContextRunner::class), $this->completeSupplementalData());
    }
}
