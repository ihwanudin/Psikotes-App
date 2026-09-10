<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionHistoryKey;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionCandidate;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionKind;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionMismatch;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionScope;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssessmentSessionSelectionPolicyTest extends TestCase
{
    public function test_one_live_in_progress_session_wins_replay_before_a_ready_case(): void
    {
        $live = $this->candidate(
            casePublicId: 'case-live',
            sourceGrantId: 'grant-live',
            sessionPublicId: 'session-live',
            sessionStatus: AssessmentSessionStatus::InProgress,
        );
        $ready = $this->candidate(casePublicId: 'case-ready', sourceGrantId: 'grant-ready', eligible: true);

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), [$ready, $live]);

        $this->assertSame(AssessmentSessionSelectionKind::Replay, $outcome->kind);
        $this->assertSame($live, $outcome->candidate);
    }

    public function test_multiple_live_in_progress_sessions_fail_with_history_ambiguity(): void
    {
        $first = $this->candidate(
            casePublicId: 'case-a',
            sourceGrantId: 'grant-a',
            sessionPublicId: 'session-a',
            sessionStatus: AssessmentSessionStatus::InProgress,
        );
        $second = $this->candidate(
            casePublicId: 'case-b',
            sourceGrantId: 'grant-b',
            sessionPublicId: 'session-b',
            sessionStatus: AssessmentSessionStatus::InProgress,
        );

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), [$first, $second]);

        $this->assertSame(AssessmentSessionSelectionKind::HistoryAmbiguous, $outcome->kind);
        $this->assertNull($outcome->candidate);
    }

    public function test_one_eligible_durable_source_grant_is_selected_without_fifo(): void
    {
        $selected = $this->candidate(casePublicId: 'case-b', sourceGrantId: 'grant-b', eligible: true);
        $ineligible = $this->candidate(casePublicId: 'case-a', sourceGrantId: 'grant-a');

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), [$ineligible, $selected]);

        $this->assertSame(AssessmentSessionSelectionKind::Selected, $outcome->kind);
        $this->assertSame($selected, $outcome->candidate);
    }

    /** @param list<string> $order */
    #[DataProvider('candidateOrders')]
    public function test_live_replay_precedence_is_independent_of_candidate_order(array $order): void
    {
        $candidates = [
            'live' => $this->candidate(
                casePublicId: 'case-live',
                sourceGrantId: 'grant-live',
                sessionPublicId: 'session-live',
                sessionStatus: AssessmentSessionStatus::InProgress,
            ),
            'ready' => $this->candidate(casePublicId: 'case-ready', sourceGrantId: 'grant-ready', eligible: true),
            'terminal' => $this->candidate(
                casePublicId: 'case-terminal',
                sourceGrantId: 'grant-terminal',
                eligible: true,
                sessionPublicId: 'session-terminal',
                sessionStatus: AssessmentSessionStatus::Scored,
            ),
        ];

        $outcome = (new AssessmentSessionSelectionPolicy)->select(
            $this->directScope(),
            array_map(static fn (string $key): AssessmentSessionSelectionCandidate => $candidates[$key], $order),
        );

        $this->assertSame(AssessmentSessionSelectionKind::Replay, $outcome->kind);
        $this->assertSame('session-live', $outcome->candidate?->sessionPublicId);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function candidateOrders(): iterable
    {
        yield 'live ready terminal' => [['live', 'ready', 'terminal']];
        yield 'live terminal ready' => [['live', 'terminal', 'ready']];
        yield 'ready live terminal' => [['ready', 'live', 'terminal']];
        yield 'ready terminal live' => [['ready', 'terminal', 'live']];
        yield 'terminal live ready' => [['terminal', 'live', 'ready']];
        yield 'terminal ready live' => [['terminal', 'ready', 'live']];
    }

    public function test_two_ready_cases_are_ambiguous_in_both_orders(): void
    {
        $first = $this->candidate(casePublicId: 'case-a', sourceGrantId: 'grant-a', eligible: true);
        $second = $this->candidate(casePublicId: 'case-b', sourceGrantId: 'grant-b', eligible: true);
        $policy = new AssessmentSessionSelectionPolicy;

        $forward = $policy->select($this->directScope(), [$first, $second]);
        $reverse = $policy->select($this->directScope(), [$second, $first]);

        $this->assertSame(AssessmentSessionSelectionKind::SelectionAmbiguous, $forward->kind);
        $this->assertSame($forward->kind, $reverse->kind);
        $this->assertNull($forward->candidate);
        $this->assertNull($reverse->candidate);
    }

    public function test_duplicate_ready_grants_for_one_case_are_also_ambiguous(): void
    {
        $first = $this->candidate(casePublicId: 'case-a', sourceGrantId: 'grant-a', eligible: true);
        $second = $this->candidate(casePublicId: 'case-a', sourceGrantId: 'grant-b', eligible: true);

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), [$first, $second]);

        $this->assertSame(AssessmentSessionSelectionKind::SelectionAmbiguous, $outcome->kind);
    }

    public function test_multiple_live_replays_are_ambiguous_in_both_orders(): void
    {
        $first = $this->candidate(
            casePublicId: 'case-a',
            sourceGrantId: 'grant-a',
            sessionPublicId: 'session-a',
            sessionStatus: AssessmentSessionStatus::InProgress,
        );
        $second = $this->candidate(
            casePublicId: 'case-b',
            sourceGrantId: 'grant-b',
            sessionPublicId: 'session-b',
            sessionStatus: AssessmentSessionStatus::InProgress,
        );
        $policy = new AssessmentSessionSelectionPolicy;

        $forward = $policy->select($this->directScope(), [$first, $second]);
        $reverse = $policy->select($this->directScope(), [$second, $first]);

        $this->assertSame(AssessmentSessionSelectionKind::HistoryAmbiguous, $forward->kind);
        $this->assertSame($forward->kind, $reverse->kind);
    }

    public function test_no_candidate_is_unavailable(): void
    {
        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), []);

        $this->assertSame(AssessmentSessionSelectionKind::Unavailable, $outcome->kind);
        $this->assertNull($outcome->candidate);
    }

    #[DataProvider('nonReplayStatuses')]
    public function test_existing_non_live_session_never_becomes_replay_or_retest_authority(
        AssessmentSessionStatus $status,
    ): void {
        $candidate = $this->candidate(
            casePublicId: 'case-history',
            sourceGrantId: 'consumed-grant',
            eligible: true,
            sessionPublicId: 'session-history',
            sessionStatus: $status,
            retestCandidate: true,
        );

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), [$candidate]);

        $this->assertSame(AssessmentSessionSelectionKind::Unavailable, $outcome->kind);
    }

    /** @return iterable<string, array{AssessmentSessionStatus}> */
    public static function nonReplayStatuses(): iterable
    {
        yield 'created' => [AssessmentSessionStatus::Created];
        yield 'submitted' => [AssessmentSessionStatus::Submitted];
        yield 'scored' => [AssessmentSessionStatus::Scored];
        yield 'expired' => [AssessmentSessionStatus::Expired];
        yield 'voided' => [AssessmentSessionStatus::Voided];
    }

    public function test_retest_candidate_without_a_durable_grant_is_unavailable(): void
    {
        $candidate = $this->candidate(
            casePublicId: 'case-retest',
            sourceGrantId: null,
            eligible: true,
            retestCandidate: true,
        );

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), [$candidate]);

        $this->assertSame(AssessmentSessionSelectionKind::Unavailable, $outcome->kind);
    }

    public function test_live_replay_candidate_requires_a_durable_source_binding(): void
    {
        $this->expectException(InvalidAssessmentSessionState::class);

        $this->candidate(
            casePublicId: 'case-live',
            sourceGrantId: null,
            sessionPublicId: 'session-live',
            sessionStatus: AssessmentSessionStatus::InProgress,
        );
    }

    public function test_retest_candidate_with_an_eligible_durable_grant_can_be_selected(): void
    {
        $candidate = $this->candidate(
            casePublicId: 'case-retest',
            sourceGrantId: 'retest-grant',
            eligible: true,
            retestCandidate: true,
        );

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), [$candidate]);

        $this->assertSame(AssessmentSessionSelectionKind::Selected, $outcome->kind);
        $this->assertTrue($outcome->candidate?->retestCandidate);
    }

    #[DataProvider('integratedScopeMismatches')]
    public function test_scope_mismatch_is_rejected(
        int $participantId,
        int $organizationId,
        CaseAuthorizationOrigin $origin,
        GenericAssessmentInstrument $instrument,
        string $casePublicId,
        ?int $assessmentParticipantId,
        AssessmentSessionSelectionMismatch $expected,
    ): void {
        $candidate = $this->candidate(
            casePublicId: $casePublicId,
            sourceGrantId: 'grant',
            eligible: true,
            participantId: $participantId,
            organizationId: $organizationId,
            origin: $origin,
            instrument: $instrument,
            assessmentParticipantId: $assessmentParticipantId,
        );

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->integratedScope(), [$candidate]);

        $this->assertSame(AssessmentSessionSelectionKind::ScopeRejected, $outcome->kind);
        $this->assertSame([$expected], $outcome->mismatches);
    }

    /**
     * @return iterable<string, array{
     *     int,
     *     int,
     *     CaseAuthorizationOrigin,
     *     GenericAssessmentInstrument,
     *     string,
     *     int|null,
     *     AssessmentSessionSelectionMismatch
     * }>
     */
    public static function integratedScopeMismatches(): iterable
    {
        yield 'participant' => [
            11, 20, CaseAuthorizationOrigin::Integrated, GenericAssessmentInstrument::Ist,
            'case-integrated', 30, AssessmentSessionSelectionMismatch::Participant,
        ];
        yield 'tenant' => [
            10, 21, CaseAuthorizationOrigin::Integrated, GenericAssessmentInstrument::Ist,
            'case-integrated', 30, AssessmentSessionSelectionMismatch::Tenant,
        ];
        yield 'origin' => [
            10, 20, CaseAuthorizationOrigin::DirectPublic, GenericAssessmentInstrument::Ist,
            'case-integrated', 30, AssessmentSessionSelectionMismatch::Origin,
        ];
        yield 'instrument' => [
            10, 20, CaseAuthorizationOrigin::Integrated, GenericAssessmentInstrument::Papi,
            'case-integrated', 30, AssessmentSessionSelectionMismatch::Instrument,
        ];
        yield 'case' => [
            10, 20, CaseAuthorizationOrigin::Integrated, GenericAssessmentInstrument::Ist,
            'case-other', 30, AssessmentSessionSelectionMismatch::CaseIdentity,
        ];
        yield 'assessment participant' => [
            10, 20, CaseAuthorizationOrigin::Integrated, GenericAssessmentInstrument::Ist,
            'case-integrated', 31, AssessmentSessionSelectionMismatch::AssessmentParticipant,
        ];
    }

    public function test_integrated_credential_selects_only_its_exact_assessment_participant_and_case(): void
    {
        $candidate = $this->candidate(
            casePublicId: 'case-integrated',
            sourceGrantId: 'grant-integrated',
            eligible: true,
            origin: CaseAuthorizationOrigin::Integrated,
            assessmentParticipantId: 30,
        );

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->integratedScope(), [$candidate]);

        $this->assertSame(AssessmentSessionSelectionKind::Selected, $outcome->kind);
        $this->assertSame($candidate, $outcome->candidate);
    }

    public function test_scope_rejection_precedes_replay_and_is_order_independent(): void
    {
        $tenantMismatch = $this->candidate(
            casePublicId: 'case-integrated',
            sourceGrantId: 'grant-a',
            eligible: true,
            organizationId: 21,
            origin: CaseAuthorizationOrigin::Integrated,
            assessmentParticipantId: 30,
        );
        $instrumentAndCaseMismatch = $this->candidate(
            casePublicId: 'case-other',
            sourceGrantId: 'grant-b',
            sessionPublicId: 'session-b',
            sessionStatus: AssessmentSessionStatus::InProgress,
            origin: CaseAuthorizationOrigin::Integrated,
            instrument: GenericAssessmentInstrument::Papi,
            assessmentParticipantId: 30,
        );
        $policy = new AssessmentSessionSelectionPolicy;

        $forward = $policy->select($this->integratedScope(), [$tenantMismatch, $instrumentAndCaseMismatch]);
        $reverse = $policy->select($this->integratedScope(), [$instrumentAndCaseMismatch, $tenantMismatch]);

        $expected = [
            AssessmentSessionSelectionMismatch::CaseIdentity,
            AssessmentSessionSelectionMismatch::Instrument,
            AssessmentSessionSelectionMismatch::Tenant,
        ];
        $this->assertSame(AssessmentSessionSelectionKind::ScopeRejected, $forward->kind);
        $this->assertSame($expected, $forward->mismatches);
        $this->assertSame($forward->mismatches, $reverse->mismatches);
    }

    public function test_direct_and_legacy_scopes_cannot_accept_a_caller_case_selector(): void
    {
        $direct = $this->directScope();
        $legacy = AssessmentSessionSelectionScope::forParticipantCredential(
            participantId: 10,
            organizationId: 20,
            origin: CaseAuthorizationOrigin::LegacySelection,
            instrument: GenericAssessmentInstrument::Ist,
        );

        $this->assertNull($direct->trustedCasePublicId);
        $this->assertNull($legacy->trustedCasePublicId);
        $this->assertNull($direct->assessmentParticipantId);
        $this->assertNull($legacy->assessmentParticipantId);
    }

    public function test_integrated_scope_requires_the_exact_credential_factory(): void
    {
        $this->expectException(InvalidAssessmentSessionState::class);

        AssessmentSessionSelectionScope::forParticipantCredential(
            participantId: 10,
            organizationId: 20,
            origin: CaseAuthorizationOrigin::Integrated,
            instrument: GenericAssessmentInstrument::Ist,
        );
    }

    public function test_non_integrated_candidate_rejects_an_assessment_participant_identity(): void
    {
        $candidate = $this->candidate(
            casePublicId: 'case-direct',
            sourceGrantId: 'grant-direct',
            eligible: true,
            assessmentParticipantId: 30,
        );

        $outcome = (new AssessmentSessionSelectionPolicy)->select($this->directScope(), [$candidate]);

        $this->assertSame(AssessmentSessionSelectionKind::ScopeRejected, $outcome->kind);
        $this->assertSame(
            [AssessmentSessionSelectionMismatch::AssessmentParticipant],
            $outcome->mismatches,
        );
    }

    public function test_history_identity_is_case_plus_instrument(): void
    {
        $key = new AssessmentSessionHistoryKey('case-a', GenericAssessmentInstrument::Ist);

        $this->assertTrue($key->matches(new AssessmentSessionHistoryKey(
            'case-a',
            GenericAssessmentInstrument::Ist,
        )));
        $this->assertFalse($key->matches(new AssessmentSessionHistoryKey(
            'case-b',
            GenericAssessmentInstrument::Ist,
        )));
        $this->assertFalse($key->matches(new AssessmentSessionHistoryKey(
            'case-a',
            GenericAssessmentInstrument::Papi,
        )));
    }

    public function test_dass_is_excluded_before_selection_candidates_can_be_built(): void
    {
        $this->expectException(UnsupportedGenericAssessmentInstrument::class);

        GenericAssessmentInstrument::fromExternal('dass21');
    }

    private function directScope(): AssessmentSessionSelectionScope
    {
        return AssessmentSessionSelectionScope::forParticipantCredential(
            participantId: 10,
            organizationId: 20,
            origin: CaseAuthorizationOrigin::DirectPublic,
            instrument: GenericAssessmentInstrument::Ist,
        );
    }

    private function integratedScope(): AssessmentSessionSelectionScope
    {
        return AssessmentSessionSelectionScope::forIntegratedCredential(
            participantId: 10,
            organizationId: 20,
            assessmentParticipantId: 30,
            trustedCasePublicId: 'case-integrated',
            instrument: GenericAssessmentInstrument::Ist,
        );
    }

    private function candidate(
        string $casePublicId,
        ?string $sourceGrantId,
        bool $eligible = false,
        ?string $sessionPublicId = null,
        ?AssessmentSessionStatus $sessionStatus = null,
        bool $retestCandidate = false,
        int $participantId = 10,
        int $organizationId = 20,
        CaseAuthorizationOrigin $origin = CaseAuthorizationOrigin::DirectPublic,
        GenericAssessmentInstrument $instrument = GenericAssessmentInstrument::Ist,
        ?int $assessmentParticipantId = null,
    ): AssessmentSessionSelectionCandidate {
        return new AssessmentSessionSelectionCandidate(
            historyKey: new AssessmentSessionHistoryKey($casePublicId, $instrument),
            participantId: $participantId,
            organizationId: $organizationId,
            origin: $origin,
            durableSourceGrantId: $sourceGrantId,
            eligibleForAllocation: $eligible,
            sessionPublicId: $sessionPublicId,
            sessionStatus: $sessionStatus,
            assessmentParticipantId: $assessmentParticipantId,
            retestCandidate: $retestCandidate,
        );
    }
}
