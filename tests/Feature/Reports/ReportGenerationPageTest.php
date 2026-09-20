<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\AdminRole;
use App\Filament\Pages\ReportGeneration;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use App\Services\ReportRendering\SignedReportDataset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_only_psychologists_can_open_the_page(): void
    {
        $case = $this->createReportCase();

        foreach ([AdminRole::SuperAdmin, AdminRole::Staff] as $role) {
            $this->actingAs($this->admin($role), 'admin');

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

    public function test_real_http_request_is_concealed_for_other_roles(): void
    {
        $case = $this->createReportCase();
        $branchId = (int) $case->organization_id;

        foreach ([AdminRole::SuperAdmin, AdminRole::BranchAdmin, AdminRole::Staff] as $role) {
            $admin = $this->admin($role, $role === AdminRole::SuperAdmin ? null : $branchId);
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
            ->assertSee(SignedReportDataset::REPORT_NUMBER_UNAVAILABLE)
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
