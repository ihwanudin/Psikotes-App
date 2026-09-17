<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionCandidateProjection;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionKind;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\ParticipantAssessmentSessionCandidates;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\OrganizationPaymentTestCase;

final class ParticipantAssessmentSessionCandidatesTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // The projection is intentionally built against the accepted case-scoped
        // entitlement contract while the follow-up uniqueness contraction is
        // still a separate migration slice.
        DB::statement('DROP INDEX IF EXISTS entitlements_participant_id_test_type_unique');
    }

    public function test_projection_requires_an_existing_service_transaction(): void
    {
        $this->expectException(LogicException::class);
        app(ParticipantAssessmentSessionCandidates::class)->project(
            new ParticipantPrincipal(1, 1),
            GenericAssessmentInstrument::Ist,
        );
    }

    public function test_a_participant_without_cases_projects_zero_candidates(): void
    {
        $identity = $this->participant('DIRECT_PUBLIC');

        $projection = $this->project($identity);
        $outcome = (new AssessmentSessionSelectionPolicy)->select(
            $projection->scope,
            $projection->candidates,
        );

        $this->assertSame([], $projection->candidates);
        $this->assertSame(AssessmentSessionSelectionKind::Unavailable, $outcome->kind);
    }

    public function test_two_ready_direct_cases_are_projected_without_silent_fifo_selection(): void
    {
        $identity = $this->participant('DIRECT_PUBLIC');
        $first = $this->directCase($identity);
        $second = $this->directCase($identity);

        $projection = $this->project($identity);
        $outcome = (new AssessmentSessionSelectionPolicy)->select(
            $projection->scope,
            $projection->candidates,
        );

        $this->assertSame([$first['public_id'], $second['public_id']], array_map(
            static fn ($candidate): string => $candidate->historyKey->casePublicId,
            $projection->candidates,
        ));
        $this->assertSame(AssessmentSessionSelectionKind::SelectionAmbiguous, $outcome->kind);
        $this->assertNull($outcome->candidate);
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    public function test_durable_live_replay_precedes_another_ready_case(): void
    {
        $identity = $this->participant('DIRECT_PUBLIC');
        $live = $this->directCase($identity);
        $session = $this->sessionGrant($identity, $live, 'in_progress');
        $this->directCase($identity);

        $projection = $this->project($identity);
        $outcome = (new AssessmentSessionSelectionPolicy)->select(
            $projection->scope,
            $projection->candidates,
        );

        $this->assertSame(AssessmentSessionSelectionKind::Replay, $outcome->kind);
        $this->assertSame($session, $outcome->candidate->sessionPublicId);
        $this->assertSame('entitlement:'.$live['entitlement'], $outcome->candidate->durableSourceGrantId);
    }

    public function test_terminal_history_is_projected_but_cannot_authorize_replay_or_retest(): void
    {
        $identity = $this->participant('DIRECT_PUBLIC');
        $case = $this->directCase($identity);
        $this->sessionGrant($identity, $case, 'submitted');

        $projection = $this->project($identity);
        $outcome = (new AssessmentSessionSelectionPolicy)->select(
            $projection->scope,
            $projection->candidates,
        );

        $this->assertSame(AssessmentSessionSelectionKind::Unavailable, $outcome->kind);
        $this->assertSame('submitted', $projection->candidates[0]->sessionStatus?->value);
        $this->assertFalse($projection->candidates[0]->retestCandidate);
    }

    public function test_legacy_selection_projects_its_exact_case_and_unbound_entitlement(): void
    {
        $identity = $this->participant('SELEKSI_BEASISWA_JEPANG');
        $case = $this->legacyCase($identity);

        $projection = $this->project($identity);
        $outcome = (new AssessmentSessionSelectionPolicy)->select(
            $projection->scope,
            $projection->candidates,
        );

        $this->assertSame(AssessmentSessionSelectionKind::Selected, $outcome->kind);
        $this->assertSame($case['public_id'], $outcome->candidate->historyKey->casePublicId);
        $this->assertSame('entitlement:'.$case['entitlement'], $outcome->candidate->durableSourceGrantId);
    }

    public function test_a_foreign_tenant_fails_closed_before_candidate_projection(): void
    {
        $identity = $this->participant('DIRECT_PUBLIC');
        $other = $this->participant('DIRECT_PUBLIC');

        $this->expectException(CaseAuthorizationRejected::class);
        $this->project([...$identity, 'branch' => $other['branch']]);
    }

    public function test_an_incomplete_source_graph_is_not_filtered_into_a_false_unique_candidate(): void
    {
        $identity = $this->participant('DIRECT_PUBLIC');
        $this->directCase($identity);

        DB::table('assessment_cases')->insert([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $identity['participant'],
            'organization_id' => $identity['branch'],
            'package_id' => $identity['package'],
            'origin' => 'DIRECT_PUBLIC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(CaseAuthorizationRejected::class);
        $this->project($identity);
    }

    public function test_a_missing_direct_instrument_grant_is_not_filtered_into_a_false_unique_candidate(): void
    {
        $identity = $this->participant('DIRECT_PUBLIC');
        $this->directCase($identity);
        $incomplete = $this->directCase($identity);
        DB::table('entitlements')->where('id', $incomplete['entitlement'])->delete();

        $this->expectException(CaseAuthorizationRejected::class);
        $this->project($identity);
    }

    public function test_an_integrated_case_cannot_be_filtered_out_of_a_direct_credential_scope(): void
    {
        $identity = $this->participant('DIRECT_PUBLIC');
        $this->directCase($identity);

        DB::table('assessment_cases')->insert([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $identity['participant'],
            'organization_id' => $identity['branch'],
            'package_id' => null,
            'origin' => 'INTEGRATED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(CaseAuthorizationRejected::class);
        $this->project($identity);
    }

    public function test_dass_cannot_be_constructed_as_a_generic_projection_instrument(): void
    {
        $this->expectException(UnsupportedGenericAssessmentInstrument::class);
        GenericAssessmentInstrument::fromExternal('dass21');
    }

    /** @return array{branch:int,participant:int,package:int} */
    private function participant(string $source): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key,
            'name' => $key,
            'ref_code' => $key,
            'organization_code' => $key,
            'display_name' => $key,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key,
            'name' => 'Synthetic',
            'amount' => 99000,
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['dass21', 'ist'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package,
                'test_type' => $type,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'package_id' => $source === 'DIRECT_PUBLIC' ? $package : null,
            'branch_id' => $branch,
            'referral_branch_id' => $branch,
            'referral_source' => 'manual',
            'source_system' => $source,
            'full_name' => 'Synthetic',
            'intended_field' => 'KAIGO',
            'phone' => '620000000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('branch', 'participant', 'package');
    }

    /** @param array{branch:int,participant:int,package:int} $identity
     * @return array{case:int,public_id:string,order:int,entitlement:int}
     */
    private function directCase(array $identity): array
    {
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId,
            'participant_id' => $identity['participant'],
            'organization_id' => $identity['branch'],
            'package_id' => $identity['package'],
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => 'KAIGO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId,
            'participant_id' => $identity['participant'],
            'assessment_case_id' => $case,
            'status' => 'paid',
            'amount' => 99000,
            'currency' => 'IDR',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entitlement = DB::table('entitlements')->insertGetId([
            'participant_id' => $identity['participant'],
            'order_id' => $order,
            'assessment_case_id' => $case,
            'test_type' => 'ist',
            'status' => 'ready',
            'ready_at' => now()->subSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! DB::table('entitlements')
            ->where('participant_id', $identity['participant'])
            ->where('test_type', 'dass21')
            ->exists()) {
            DB::table('entitlements')->insert([
                'participant_id' => $identity['participant'],
                'order_id' => $order,
                'assessment_case_id' => null,
                'test_type' => 'dass21',
                'status' => 'ready',
                'ready_at' => now()->subSecond(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['case' => $case, 'public_id' => $publicId, 'order' => $order, 'entitlement' => $entitlement];
    }

    /** @param array{branch:int,participant:int,package:int} $identity
     * @return array{case:int,public_id:string,selection:int,entitlement:int}
     */
    private function legacyCase(array $identity): array
    {
        $key = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $key,
            'participant_id' => $identity['participant'],
            'organization_id' => $identity['branch'],
            'package_id' => null,
            'origin' => 'LEGACY_SELECTION',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $selection = DB::table('selection_participants')->insertGetId([
            'client_id' => 'client-'.$key,
            'external_candidate_id' => 'candidate-'.$key,
            'selection_round_id' => 'round-'.$key,
            'registration_id' => 'registration-'.$key,
            'participant_id' => $identity['participant'],
            'assessment_case_id' => $case,
            'idempotency_key' => 'key-'.$key,
            'request_hash' => hash('sha256', $key),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entitlement = DB::table('entitlements')->insertGetId([
            'participant_id' => $identity['participant'],
            'order_id' => null,
            'assessment_case_id' => $case,
            'test_type' => 'ist',
            'status' => 'ready',
            'ready_at' => now()->subSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['case' => $case, 'public_id' => $key, 'selection' => $selection, 'entitlement' => $entitlement];
    }

    /** @param array{branch:int,participant:int,package:int} $identity
     * @param  array{case:int,public_id:string,order:int,entitlement:int}  $case
     */
    private function sessionGrant(array $identity, array $case, string $status): string
    {
        $sessionPublicId = (string) Str::ulid();
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId,
            'participant_id' => $identity['participant'],
            'assessment_case_id' => $case['case'],
            'test_type' => 'ist',
            'attempt_no' => 1,
            'authorization_id' => 'grant:v1:DIRECT_PUBLIC:entitlement:'.$case['entitlement'],
            'allocation_intent_id' => 'allocation:v1:DIRECT_PUBLIC:entitlement:'.$case['entitlement'].':ist',
            'duration_seconds' => 600,
            'status' => $status,
            'started_at' => now()->subMinute(),
            'ends_at' => now()->addMinutes(9),
            'submitted_at' => $status === 'submitted' ? now() : null,
            'answers_revision' => 0,
            'created_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);
        DB::table('test_session_grants')->insert([
            'test_session_id' => $session,
            'assessment_case_id' => $case['case'],
            'participant_id' => $identity['participant'],
            'organization_id' => $identity['branch'],
            'test_type' => 'ist',
            'origin' => 'DIRECT_PUBLIC',
            'grant_kind' => 'entitlement',
            'order_id' => $case['order'],
            'entitlement_id' => $case['entitlement'],
            'created_at' => now(),
        ]);
        DB::table('entitlements')->where('id', $case['entitlement'])->update([
            'status' => $status === 'in_progress' ? 'in_progress' : 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => $status === 'submitted' ? now() : null,
            'updated_at' => now(),
        ]);

        return $sessionPublicId;
    }

    /** @param array{branch:int,participant:int} $identity */
    private function project(array $identity): AssessmentSessionCandidateProjection
    {
        $runner = app(RlsContextRunner::class);

        return $runner->runAsService(fn () => app(ParticipantAssessmentSessionCandidates::class)->project(
            new ParticipantPrincipal($identity['participant'], $identity['branch']),
            GenericAssessmentInstrument::Ist,
        ));
    }
}
