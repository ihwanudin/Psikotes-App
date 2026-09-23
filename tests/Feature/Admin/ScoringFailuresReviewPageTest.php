<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedRmibResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Enums\AdminRole;
use App\Filament\Pages\ScoringFailuresReview;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\PersistSealedRmibResult;
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

    /**
     * ADR-0032 PR3 follow-up: a reviewRequired RMIB result is a REAL
     * generic_instrument_results row (scoring succeeded), never an
     * assessment_scoring_attempts row -- so this section reads a different
     * table entirely from $attempts. Persists via the real
     * PersistSealedRmibResult service (not a hand-rolled raw insert) so
     * every checksum/format constraint on the row is genuinely satisfied,
     * not merely assumed.
     */
    public function test_psychologist_sees_review_required_rmib_results_with_excluded_groups(): void
    {
        $this->reviewRequiredRmibResult(excludedGroups: [3]);
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(ScoringFailuresReview::class);
        $component->assertSuccessful();
        $this->assertCount(1, $component->get('reviewRequiredResults'));
        $this->assertSame([3], $component->get('reviewRequiredResults')[0]['excluded_groups']);
        $component->assertSee('Synthetic Participant');
        $component->assertSee('3');
    }

    public function test_super_admin_and_central_admin_see_review_required_existence_but_never_excluded_groups(): void
    {
        $this->reviewRequiredRmibResult(excludedGroups: [7]);

        foreach ([AdminRole::SuperAdmin, AdminRole::CentralAdmin] as $role) {
            $this->actingAs($this->admin($role), 'admin');

            $component = Livewire::test(ScoringFailuresReview::class);
            $component->assertSuccessful();
            $this->assertCount(1, $component->get('reviewRequiredResults'));
            $this->assertNull($component->get('reviewRequiredResults')[0]['excluded_groups']);
            $component->assertSee('Synthetic Participant');

            // Same leak-check technique as the failed_to_score tier above:
            // the excluded group NUMBER on its own isn't identifying, so
            // assert against the actually-serialized page/component state
            // rather than a single bare digit that could coincidentally
            // appear elsewhere (a date, a count, ...).
            $payload = json_encode($component->instance(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('"excluded_groups":[7]', $payload);
        }
    }

    public function test_review_required_section_is_empty_when_filtered_to_another_instrument(): void
    {
        $this->reviewRequiredRmibResult(excludedGroups: [2]);
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(ScoringFailuresReview::class);
        $this->assertCount(1, $component->get('reviewRequiredResults'));

        $component->set('instrumentFilter', 'ist');
        $this->assertCount(0, $component->get('reviewRequiredResults'));

        $component->set('instrumentFilter', 'rmib');
        $this->assertCount(1, $component->get('reviewRequiredResults'));
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

    /**
     * Persists a REAL, successfully-scored RMIB result with
     * reviewRequired=true via the actual PersistSealedRmibResult service --
     * mirrors PersistSealedRmibResultTest::fixture()/sealedResult() rather
     * than a hand-rolled raw insert, so every checksum/format constraint on
     * generic_instrument_results is genuinely satisfied, not merely assumed.
     *
     * @param  list<int>  $excludedGroups
     */
    private function reviewRequiredRmibResult(array $excludedGroups): void
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => 'BR-'.uniqid(), 'ref_code' => 'REF-'.uniqid(),
            'organization_code' => 'ORG-'.uniqid(), 'name' => 'Synthetic branch', 'display_name' => 'Synthetic branch',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic Participant', 'phone' => '620000000000', 'test_number' => 'TN-'.uniqid(),
        ]);
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $subtests = [];
        for ($group = 1; $group <= 9; $group++) {
            $subtests[] = ['code' => 'G'.$group, 'duration_seconds' => 60, 'item_count' => 12];
        }
        $definitionSource = [
            'instrument' => 'rmib', 'version' => 'synthetic-definition-'.$key,
            'provenance' => 'synthetic-test-only', 'total_duration_seconds' => 540,
            'subtests' => $subtests,
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $submittedAt = '2026-09-20 03:20:00.654321+00:00';
        $sessionPublicId = (string) Str::ulid();
        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'rmib', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 540, 'status' => 'submitted', 'answers_revision' => 1,
            'started_at' => '2026-09-20 03:00:00.000000+00:00',
            'ends_at' => '2026-09-20 03:30:00.000000+00:00',
            'submitted_at' => $submittedAt,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-20 03:00:00.000000+00:00',
            'updated_at' => '2026-09-20 03:20:00.654321+00:00',
        ]);
        $instrumentPayload = json_encode(['version' => 'synthetic-'.$key], JSON_THROW_ON_ERROR);
        $instrumentVersion = DB::table('instrument_versions')->insertGetId([
            'code' => 'rmib', 'version' => 'synthetic-'.$key, 'source_file' => 'synthetic-rmib.json',
            'checksum' => hash('sha256', $instrumentPayload), 'payload' => $instrumentPayload,
            'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $answers = [];
        foreach (range(1, 108) as $item) {
            $answers[] = [
                'item_no' => $item, 'value' => '1', 'revision' => 1,
                'answered_at' => '2026-09-20T03:10:00.123456Z',
            ];
        }
        $source = SealedGenericAnswerSet::seal(
            assessmentCaseId: $case, sessionId: $sessionId, participantId: $participant,
            sessionPublicId: $sessionPublicId, instrument: GenericAssessmentInstrument::Rmib,
            attemptNo: 1, submittedAt: $submittedAt, answersRevision: 1,
            definition: $definition, answers: $answers,
        );
        $scoringSource = [
            'id' => $instrumentVersion, 'code' => 'rmib', 'version' => 'synthetic-'.$key,
            'sourceFile' => 'synthetic-rmib.json', 'checksum' => hash('sha256', $instrumentPayload),
        ];
        $codes = ['Out', 'Me', 'Comp', 'Sci', 'Prs', 'Aesth', 'Lit', 'Mus', 'S.Se', 'Cler', 'Prac', 'Med'];
        $categories = [];
        foreach ($codes as $offset => $code) {
            $rank = ($offset % 12) + 1;
            $categories[] = [
                'code' => $code, 'rawScore' => 9 + $offset, 'standardScore' => $rank,
                'sourceScore' => 5, 'level' => 3, 'category' => 'synthetic-'.$code,
                'band' => ['lo' => $rank, 'hi' => $rank],
            ];
        }
        $result = SealedRmibResult::seal($source, $scoringSource, $categories, reviewRequired: true, excludedGroups: $excludedGroups);

        app(RlsContextRunner::class)->runAsService(
            fn (): string => app(PersistSealedRmibResult::class)->execute($result),
        );
    }
}
