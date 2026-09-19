<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Enums\AdminRole;
use App\Filament\Pages\ReportSigning;
use App\Models\Admin;
use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\TestPackage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ReportSigningPageTest extends TestCase
{
    use RefreshDatabase;

    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    // ─── Helpers ───

    private function createCase(): AssessmentCase
    {
        static $counter = 0;
        $suffix = ++$counter;
        $branch = Branch::query()->create([
            'code' => 'BR-RS-'.$suffix,
            'name' => 'Cabang ReportSigning Page Test',
            'ref_code' => 'REF-RS-'.$suffix,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'PKG-RS-'.$suffix,
            'name' => 'Paket ReportSigning Page Test',
            'amount' => 250_000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $package->items()->create(['test_type' => 'ist']);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'package_id' => $package->id,
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Peserta ReportSigning Page',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'UMUM',
            'phone' => '+6281234567890',
        ]);

        return AssessmentCase::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $package->id,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => 'UMUM',
        ]);
    }

    private function psychologist(): Admin
    {
        return Admin::query()->create([
            'name' => 'Psychologist Page Test',
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::Psychologist->value,
        ]);
    }

    private function superAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Super Admin Page Test',
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::SuperAdmin->value,
        ]);
    }

    private function branchAdmin(): Admin
    {
        $branch = Branch::query()->create([
            'code' => 'BR-RS-BA-'.uniqid(),
            'name' => 'Branch Admin Branch',
            'ref_code' => 'REF-RS-BA-'.uniqid(),
        ]);

        return Admin::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Branch Admin Page Test',
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::BranchAdmin->value,
        ]);
    }

    private function staff(): Admin
    {
        $branch = Branch::query()->create([
            'code' => 'BR-RS-ST-'.uniqid(),
            'name' => 'Staff Branch',
            'ref_code' => 'REF-RS-ST-'.uniqid(),
        ]);

        return Admin::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Staff Page Test',
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::Staff->value,
        ]);
    }

    /**
     * @return array{eligibilityId: string, narrativeId: string}
     */
    private function seedBaseline(AssessmentCase $case): array
    {
        $reporting = $this->canonicalReporting();
        $canonicalInput = [
            'levels' => array_fill_keys(self::ASPECTS, 5),
            'field_code' => 'UMUM',
            'iq' => 100,
            'validity' => 'V1',
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => 'F0-2026.08', 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
            ],
        ];
        $snapshot = EligibilityDecisionSnapshot::create($canonicalInput);
        $snapshotArray = $snapshot->toArray();

        $eligibilityId = (string) Str::ulid();
        DB::table('eligibility_decision_versions')->insert([
            'id' => $eligibilityId,
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'standard_version' => $snapshotArray['provenance']['eligibility_standard_version'],
            'field_code' => $snapshotArray['zone']['field_code'],
            'publication_blocked' => $snapshotArray['publication_blocked'],
            'recommendation_label' => $snapshotArray['recommendation']['label'] ?? null,
            'iq' => $canonicalInput['iq'],
            'validity' => $canonicalInput['validity'],
            'snapshot_json' => json_encode($snapshotArray, JSON_THROW_ON_ERROR),
            'canonical_input_json' => json_encode($canonicalInput, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $narrativeId = (string) Str::ulid();
        DB::table('bilingual_narrative_versions')->insert([
            'id' => $narrativeId,
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'eligibility_version_id' => $eligibilityId,
            'review_required' => false,
            'cluster_a_id' => 'Teks A ID',
            'cluster_a_jp' => 'Teks A JP',
            'cluster_b_id' => 'Teks B ID',
            'cluster_b_jp' => 'Teks B JP',
            'cluster_c_id' => null,
            'cluster_c_jp' => null,
            'cluster_d_id' => 'Teks D ID',
            'cluster_d_jp' => 'Teks D JP',
            'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return ['eligibilityId' => $eligibilityId, 'narrativeId' => $narrativeId];
    }

    /**
     * @return array{eligibilityId: string, narrativeId: string}
     */
    private function seedV3Baseline(AssessmentCase $case): array
    {
        $reporting = $this->canonicalReporting();
        $canonicalInput = [
            'levels' => array_fill_keys(self::ASPECTS, 1),
            'field_code' => 'UMUM',
            'iq' => 100,
            'validity' => 'V3',
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => 'F0-2026.08', 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
            ],
        ];
        $snapshot = EligibilityDecisionSnapshot::create($canonicalInput);
        $snapshotArray = $snapshot->toArray();

        $eligibilityId = (string) Str::ulid();
        DB::table('eligibility_decision_versions')->insert([
            'id' => $eligibilityId,
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'standard_version' => $snapshotArray['provenance']['eligibility_standard_version'],
            'field_code' => $snapshotArray['zone']['field_code'],
            'publication_blocked' => $snapshotArray['publication_blocked'],
            'recommendation_label' => $snapshotArray['recommendation']['label'] ?? null,
            'iq' => $canonicalInput['iq'],
            'validity' => $canonicalInput['validity'],
            'snapshot_json' => json_encode($snapshotArray, JSON_THROW_ON_ERROR),
            'canonical_input_json' => json_encode($canonicalInput, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $narrativeId = (string) Str::ulid();
        DB::table('bilingual_narrative_versions')->insert([
            'id' => $narrativeId,
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'eligibility_version_id' => $eligibilityId,
            'review_required' => false,
            'cluster_a_id' => 'Teks A ID',
            'cluster_a_jp' => 'Teks A JP',
            'cluster_b_id' => 'Teks B ID',
            'cluster_b_jp' => 'Teks B JP',
            'cluster_c_id' => null,
            'cluster_c_jp' => null,
            'cluster_d_id' => 'Teks D ID',
            'cluster_d_jp' => 'Teks D JP',
            'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return ['eligibilityId' => $eligibilityId, 'narrativeId' => $narrativeId];
    }

    private function canonicalReporting(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/reporting.json');
        if (! is_string($contents)) {
            throw new \RuntimeException('Canonical reporting data could not be read.');
        }
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $data['base_standards'],
            'fields' => $data['fields'],
        ];
    }

    // ─── Authorization ───

    public function test_non_psychologist_roles_cannot_access_page(): void
    {
        $case = $this->createCase();
        $this->seedBaseline($case);

        // SuperAdmin → 404
        $this->actingAs($this->superAdmin(), 'admin');
        self::assertFalse(ReportSigning::canAccess());
        Livewire::test(ReportSigning::class, ['case' => $case->public_id])
            ->assertNotFound();

        // BranchAdmin → 404
        $this->flushSession();
        $this->actingAs($this->branchAdmin(), 'admin');
        self::assertFalse(ReportSigning::canAccess());
        Livewire::test(ReportSigning::class, ['case' => $case->public_id])
            ->assertNotFound();

        // Staff → 404
        $this->flushSession();
        $this->actingAs($this->staff(), 'admin');
        self::assertFalse(ReportSigning::canAccess());
        Livewire::test(ReportSigning::class, ['case' => $case->public_id])
            ->assertNotFound();
    }

    public function test_guest_cannot_open_page_directly(): void
    {
        $case = $this->createCase();
        $this->seedBaseline($case);

        $this->get(ReportSigning::getUrl(['case' => $case->public_id]))
            ->assertRedirect('/admin/login');
    }

    public function test_page_does_not_register_navigation_for_non_psychologist(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        self::assertFalse(ReportSigning::shouldRegisterNavigation());

        $this->flushSession();
        $this->actingAs($this->branchAdmin(), 'admin');
        self::assertFalse(ReportSigning::shouldRegisterNavigation());

        $this->flushSession();
        $this->actingAs($this->staff(), 'admin');
        self::assertFalse(ReportSigning::shouldRegisterNavigation());
    }

    public function test_page_registers_navigation_for_psychologist(): void
    {
        $this->actingAs($this->psychologist(), 'admin');
        self::assertTrue(ReportSigning::shouldRegisterNavigation());
    }

    public function test_can_access_returns_true_for_psychologist(): void
    {
        $this->actingAs($this->psychologist(), 'admin');
        self::assertTrue(ReportSigning::canAccess());
    }

    // ─── Read-only when SIGNED ───

    public function test_page_is_read_only_when_snapshot_already_signed(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $psychologist = $this->psychologist();
        $this->actingAs($psychologist, 'admin');

        // Insert a SIGNED snapshot directly
        $snapshotJson = json_encode([
            'prerequisite_input' => [
                'label' => 'DISARANKAN',
                'validity' => 'V1',
                'target_field' => 'UMUM',
                'procedure_note' => null,
                'accompaniment_conditions' => null,
                'narrative_clusters' => ['A' => 'Narasi A.', 'B' => 'Narasi B.', 'C' => 'Narasi C.', 'D' => 'Narasi D.'],
                'overrides' => [],
            ],
            'provenance' => ['signed' => true],
        ], JSON_THROW_ON_ERROR);

        DB::table('report_signing_snapshots')->insert([
            'id' => (string) Str::ulid(),
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'state' => 'SIGNED',
            'eligibility_version_id' => $baseline['eligibilityId'],
            'narrative_version_id' => $baseline['narrativeId'],
            'snapshot_json' => $snapshotJson,
            'signed_by_admin_id' => $psychologist->id,
            'signed_at' => now(),
            'created_at' => now(),
        ]);

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);
        $component->assertSuccessful();
        $component->assertSet('isReadOnly', true);
        $component->assertSee('Laporan sudah ditandatangani');
        $component->assertSee('Snapshot berikut bersifat append-only dan tidak dapat diubah.');
        $component->assertSee('Label final');
        $component->assertSee('DISARANKAN');
        $component->assertSee('Narasi klaster');
        $component->assertSee('Narasi A.');
        $component->assertSee('Tanda tangan sudah dibuat');
        // No form elements
        $component->assertDontSee('Validasi kesiapan');
        $component->assertDontSee('Tandatangani Laporan');
    }

    public function test_read_only_page_shows_existing_snapshot_data(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $psychologist = $this->psychologist();
        $this->actingAs($psychologist, 'admin');

        $snapshotJson = json_encode([
            'prerequisite_input' => [
                'label' => 'DIPERTIMBANGKAN',
                'validity' => 'V1',
                'target_field' => 'TEKNIK',
                'procedure_note' => 'Catatan prosedur dari psikolog.',
                'accompaniment_conditions' => 'Pendampingan adaptasi kerja selama masa awal.',
                'narrative_clusters' => [
                    'A' => 'Narasi klaster A final.',
                    'B' => 'Narasi klaster B final.',
                    'C' => 'Narasi klaster C final.',
                    'D' => 'Narasi klaster D final.',
                ],
                'overrides' => [
                    ['type' => 'level', 'aspect' => 'C4', 'reason' => 'Observasi profesional mendukung penurunan level.'],
                ],
            ],
            'provenance' => ['signed' => true],
        ], JSON_THROW_ON_ERROR);

        DB::table('report_signing_snapshots')->insert([
            'id' => (string) Str::ulid(),
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'state' => 'SIGNED',
            'eligibility_version_id' => $baseline['eligibilityId'],
            'narrative_version_id' => $baseline['narrativeId'],
            'snapshot_json' => $snapshotJson,
            'signed_by_admin_id' => $psychologist->id,
            'signed_at' => now(),
            'created_at' => now(),
        ]);

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);
        $component->assertSuccessful();
        $component->assertSet('isReadOnly', true);

        // All snapshot data visible
        $component->assertSee('DIPERTIMBANGKAN');
        $component->assertSee('TEKNIK');
        $component->assertSee('Catatan prosedur dari psikolog.');
        $component->assertSee('Pendampingan adaptasi kerja selama masa awal.');
        $component->assertSee('Narasi klaster A final.');
        $component->assertSee('Narasi klaster B final.');
        $component->assertSee('Narasi klaster C final.');
        $component->assertSee('Narasi klaster D final.');

        // Overrides section visible
        $component->assertSee('Override diterapkan');
        $component->assertSee('C4');
        $component->assertSee('Observasi profesional mendukung penurunan level.');
    }

    // ─── Successful submit ───

    public function test_psychologist_can_load_page_with_case_data(): void
    {
        $case = $this->createCase();
        $this->seedBaseline($case);
        $this->actingAs($this->psychologist(), 'admin');

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);
        $component->assertSuccessful();

        // Header data
        $component->assertSee('Peserta ReportSigning Page');
        $component->assertSee($case->public_id);
        $component->assertSee('UMUM');
        $component->assertSee('V1');
        $component->assertSee('IQ 100');

        // Aspect table visible
        $component->assertSee('Level sistem');
        $component->assertSee('A1');
        $component->assertSee('D5');

        // Form elements present
        $component->assertSee('Narasi empat klaster');
        $component->assertSee('Validitas dan prosedur');
        $component->assertSee('Syarat pendampingan');
        $component->assertSee('Validasi kesiapan');

        // Not read-only
        $component->assertSet('isReadOnly', false);
    }

    public function test_psychologist_can_submit_signing_successfully(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $this->actingAs($this->psychologist(), 'admin');

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);
        $component->assertSuccessful();

        // Fill narrative clusters
        $component->set('narrativeClusters.A', 'Narasi klaster A untuk penandatanganan.');
        $component->set('narrativeClusters.B', 'Narasi klaster B untuk penandatanganan.');
        $component->set('narrativeClusters.C', 'Narasi klaster C untuk penandatanganan.');
        $component->set('narrativeClusters.D', 'Narasi klaster D untuk penandatanganan.');

        // Validate draft
        $component->call('validateDraft');
        $component->assertSet('blockingCodes', []);

        // Submit
        $component->call('submit');
        $component->assertSet('isReadOnly', true);
        $component->assertSee('Laporan sudah ditandatangani');

        // Verify snapshot persisted
        $snapshots = DB::table('report_signing_snapshots')
            ->where('assessment_case_id', $case->id)
            ->get();
        $this->assertCount(1, $snapshots);
        $this->assertSame('SIGNED', $snapshots[0]->state);
        $this->assertSame(1, (int) $snapshots[0]->version);
    }

    public function test_submit_button_is_disabled_when_blockers_exist(): void
    {
        $case = $this->createCase();
        $this->seedBaseline($case);
        $this->actingAs($this->psychologist(), 'admin');

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);

        // All narrative clusters empty → blockers
        $component->call('validateDraft');
        $this->assertNotEmpty($component->get('blockingCodes'));
        $component->assertSee('Belum dapat ditandatangani');
        $component->assertSee('Tanda tangan belum tersedia');
        $component->assertDontSee('Tandatangani Laporan');
    }

    public function test_v3_baseline_has_blocking_codes_and_submit_disabled(): void
    {
        $case = $this->createCase();
        $this->seedV3Baseline($case);
        $this->actingAs($this->psychologist(), 'admin');

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);

        $component->call('validateDraft');
        $this->assertNotEmpty($component->get('blockingCodes'));
        $this->assertContains('VALIDITY_V3', $component->get('blockingCodes'));
        $component->assertSee('Belum dapat ditandatangani');
        $component->assertSee('Tanda tangan belum tersedia');
        $component->assertDontSee('Tandatangani Laporan');
    }

    public function test_focus_blocker_dispatches_event(): void
    {
        $case = $this->createCase();
        $this->seedBaseline($case);
        $this->actingAs($this->psychologist(), 'admin');

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);

        $component->call('focusBlocker', 'ACCOMPANIMENT_CONDITIONS_REQUIRED')
            ->assertDispatched('review-focus', target: 'accompaniment-conditions');

        $component->call('focusBlocker', 'NARRATIVE_CLUSTER_A_REQUIRED')
            ->assertDispatched('review-focus', target: 'cluster-A');

        $component->call('focusBlocker', 'UNKNOWN_CODE')
            ->assertDispatched('review-focus', target: 'readiness-heading');
    }

    public function test_revoked_access_detected_on_hydrate(): void
    {
        $psychologist = $this->psychologist();
        $case = $this->createCase();
        $this->seedBaseline($case);
        $this->actingAs($psychologist, 'admin');

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);
        $component->assertSuccessful();

        // Revoke access by changing role
        Admin::query()->whereKey($psychologist->id)->update(['role' => AdminRole::Staff->value]);

        $component->call('validateDraft')->assertNotFound();
    }

    public function test_page_without_eligibility_data_shows_default_aspects(): void
    {
        $case = $this->createCase();
        // No seedBaseline — no eligibility data
        $this->actingAs($this->psychologist(), 'admin');

        $component = Livewire::test(ReportSigning::class, ['case' => $case->public_id]);
        $component->assertSuccessful();

        // Still renders header
        $component->assertSee('Peserta ReportSigning Page');
        $component->assertSee($case->public_id);

        // Aspect table still renders (default level 3 for all 18 aspects)
        $component->assertSee('Level sistem');
        $component->assertSee('A1');
        $component->assertSee('D5');

        // System label is empty (no eligibility data)
        $component->assertSee('Rekomendasi sistem');
        // IQ is 0 (default)
        $component->assertDontSee('IQ 100');
    }

    public function test_nonexistent_case_returns_404(): void
    {
        $this->actingAs($this->psychologist(), 'admin');

        $nonexistentId = (string) Str::ulid();

        Livewire::test(ReportSigning::class, ['case' => $nonexistentId])
            ->assertNotFound();
    }
}
