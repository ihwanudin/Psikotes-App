<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Pages\ScoringFailuresReview;
use App\Models\Admin;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lead's explicit decision, 2026-09-23 (see ScoringFailuresReview's own
 * docblock): two-tier access on the SAME page. ReviewReports (Psychologist)
 * sees the psychometric reason_code; ReviewScoringFailures (SuperAdmin/
 * CentralAdmin, new ability) sees existence only, never reason_code --
 * verified here at the PHP-object level (json_encode of the live Livewire
 * instance), not just "the Blade view doesn't render it", since a
 * component's public properties serialize into the page for client-side
 * hydration regardless of what the view chooses to show (same leak-check
 * technique as AssessmentBillReviewerFilamentTest's sensitiveValues()
 * check). BranchAdmin/Staff get neither tier.
 */
final class ScoringFailuresReviewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_psychologist_sees_reason_code(): void
    {
        $this->scoringAttempt('ist', 'failed_to_score', 'MALFORMED_PAYLOAD', '2026-09-20 03:00:00.000000+07:00');
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(ScoringFailuresReview::class);
        $component->assertSuccessful();
        $component->assertSet('canSeeReason', true);
        $this->assertSame('MALFORMED_PAYLOAD', $component->get('attempts')[0]['reason_code']);
        $component->assertSee('MALFORMED_PAYLOAD');
    }

    public function test_super_admin_and_central_admin_see_existence_but_never_the_reason_code(): void
    {
        $this->scoringAttempt('papi', 'failed_to_score', 'INCOMPLETE_ANSWERS', '2026-09-20 03:00:00.000000+07:00');

        foreach ([AdminRole::SuperAdmin, AdminRole::CentralAdmin] as $role) {
            $this->actingAs($this->admin($role), 'admin');

            $component = Livewire::test(ScoringFailuresReview::class);
            $component->assertSuccessful();
            $component->assertSet('canSeeReason', false);
            $this->assertCount(1, $component->get('attempts'));
            $this->assertNull($component->get('attempts')[0]['reason_code']);
            $component->assertSee('Synthetic Participant');
            $component->assertDontSee('INCOMPLETE_ANSWERS');

            $payload = json_encode($component->instance(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('INCOMPLETE_ANSWERS', $payload);
        }
    }

    public function test_branch_admin_and_staff_cannot_access_either_tier(): void
    {
        $this->scoringAttempt('ist', 'failed_to_score', 'MALFORMED_PAYLOAD', '2026-09-20 03:00:00.000000+07:00');

        foreach ([AdminRole::BranchAdmin, AdminRole::Staff] as $role) {
            $this->actingAs($this->admin($role), 'admin');

            self::assertFalse(ScoringFailuresReview::canAccess());
            self::assertFalse(ScoringFailuresReview::shouldRegisterNavigation());
            Livewire::test(ScoringFailuresReview::class)->assertNotFound();
        }
    }

    public function test_scored_outcome_rows_are_never_listed(): void
    {
        $this->scoringAttempt('ist', 'scored', null, '2026-09-20 03:00:00.000000+07:00', resultPublicId: (string) Str::ulid());
        $this->scoringAttempt('papi', 'failed_to_score', 'INCOMPLETE_ANSWERS', '2026-09-20 03:00:00.000000+07:00');
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(ScoringFailuresReview::class);
        $attempts = $component->get('attempts');
        $this->assertCount(1, $attempts);
        $this->assertSame('papi', $attempts[0]['instrument_code']);
    }

    public function test_filters_by_instrument_and_date_range(): void
    {
        $this->scoringAttempt('ist', 'failed_to_score', 'MALFORMED_PAYLOAD', '2026-09-10 03:00:00.000000+07:00');
        $this->scoringAttempt('papi', 'failed_to_score', 'INCOMPLETE_ANSWERS', '2026-09-20 03:00:00.000000+07:00');
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(ScoringFailuresReview::class);
        $this->assertCount(2, $component->get('attempts'));

        $component->set('instrumentFilter', 'papi');
        $this->assertCount(1, $component->get('attempts'));
        $this->assertSame('papi', $component->get('attempts')[0]['instrument_code']);

        $component->set('instrumentFilter', '')
            ->set('dateFrom', '2026-09-15')
            ->set('dateTo', '2026-09-25');
        $this->assertCount(1, $component->get('attempts'));
        $this->assertSame('papi', $component->get('attempts')[0]['instrument_code']);
    }

    public function test_reason_code_filter_is_ignored_for_a_viewer_who_cannot_see_reasons(): void
    {
        $this->scoringAttempt('ist', 'failed_to_score', 'MALFORMED_PAYLOAD', '2026-09-20 03:00:00.000000+07:00');
        $this->actingAs($this->admin(AdminRole::SuperAdmin), 'admin');

        $component = Livewire::test(ScoringFailuresReview::class);
        $this->assertCount(1, $component->get('attempts'));

        // Simulates a tampered client value (the input isn't even rendered
        // for this tier) -- the query must still ignore it server-side,
        // gated on the backend-derived canSeeReason, not client input.
        $component->set('reasonCodeFilter', 'SOME_OTHER_CODE');
        $this->assertCount(1, $component->get('attempts'));
    }

    public function test_access_is_rechecked_on_every_hydrate_after_a_mid_session_role_change(): void
    {
        $this->scoringAttempt('ist', 'failed_to_score', 'MALFORMED_PAYLOAD', '2026-09-20 03:00:00.000000+07:00');
        $psychologist = $this->admin(AdminRole::Psychologist);
        $this->actingAs($psychologist, 'admin');

        $component = Livewire::test(ScoringFailuresReview::class);
        $component->assertSuccessful();
        $component->assertSet('canSeeReason', true);

        Admin::query()->whereKey($psychologist->id)->update(['role' => AdminRole::BranchAdmin->value]);

        $component->call('updatedInstrumentFilter')->assertNotFound();
    }

    private function admin(AdminRole $role): Admin
    {
        $branchId = null;
        if (in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)) {
            $branchId = DB::table('branches')->insertGetId([
                'code' => 'BR-'.strtoupper(substr($role->value, 0, 3)).'-'.uniqid(),
                'name' => 'Synthetic branch',
                'ref_code' => 'REF-'.strtoupper(substr($role->value, 0, 3)).'-'.uniqid(),
                'organization_code' => 'ORG-'.uniqid(), 'display_name' => 'Synthetic branch',
            ]);
        }

        return Admin::query()->create([
            'branch_id' => $branchId,
            'name' => 'Synthetic '.str_replace('_', ' ', $role->value),
            'email' => uniqid($role->value.'-', true).'@example.test',
            'password' => 'not-a-real-password',
            'role' => $role,
            'can_verify_payments' => false,
            'has_email_authentication' => true,
        ]);
    }

    private function scoringAttempt(
        string $instrumentCode,
        string $outcome,
        ?string $reasonCode,
        string $attemptedAt,
        ?string $resultPublicId = null,
    ): void {
        $branch = DB::table('branches')->insertGetId([
            'code' => 'BR-'.uniqid(), 'ref_code' => 'REF-'.uniqid(),
            'organization_code' => 'ORG-'.uniqid(), 'name' => 'Synthetic branch', 'display_name' => 'Synthetic branch',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic Participant', 'phone' => '620000000000', 'test_number' => 'TN-'.uniqid(),
        ]);
        $sessionPublicId = (string) Str::ulid();
        $submittedAt = Carbon::parse($attemptedAt);
        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant, 'test_type' => $instrumentCode,
            'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => 'submitted', 'answers_revision' => 0,
            'started_at' => $submittedAt->copy()->subHour(), 'ends_at' => $submittedAt->copy()->addHour(),
            'submitted_at' => $submittedAt,
        ]);

        DB::table('assessment_scoring_attempts')->insert([
            'public_id' => (string) Str::ulid(),
            'session_id' => $sessionId,
            'session_public_id' => $sessionPublicId,
            'instrument_code' => $instrumentCode,
            'attempted_at' => $attemptedAt,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'result_public_id' => $resultPublicId,
            'created_at' => $attemptedAt,
        ]);
    }
}
