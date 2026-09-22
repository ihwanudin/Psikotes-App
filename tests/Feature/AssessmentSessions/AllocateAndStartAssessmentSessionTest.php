<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\AllocateAndStartAssessmentSession;
use App\Actions\AssessmentSessions\StartParticipantAssessmentSession;
use App\Contracts\AssessmentItemContentAuthority;
use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy;
use App\Domain\AssessmentSessions\AssessmentItemContent;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStartRetriesExhausted;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\AssessmentSessions\ParticipantAssessmentSessionCandidates;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AlwaysAvailableAssessmentItemContentAuthority;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

/** SQLite contract only: first attempt/exact replay, with no HTTP binding, retest, or multi-case resolution. */
final class AllocateAndStartAssessmentSessionTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private const NOW = '2026-09-10T02:00:00.123456+00:00';

    public function test_direct_public_allocation_persists_the_full_snapshot_grant_and_exact_source_transition(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;

        $result = $this->action($authority)->execute(
            new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
            GenericAssessmentInstrument::Ist,
        );

        $session = DB::table('test_sessions')->where('public_id', $result->sessionId)->sole();
        $grant = DB::table('test_session_grants')->where('test_session_id', $session->id)->sole();
        $source = DB::table('entitlements')->where('id', $fixture['entitlement'])->sole();
        $this->assertSame('in_progress', $session->status);
        $this->assertSame(1, $session->attempt_no);
        $this->assertSame($fixture['case'], $session->assessment_case_id);
        $this->assertSame($result->definition->version, $session->session_definition_version);
        $this->assertSame($result->definition->provenance, $session->session_definition_provenance);
        $this->assertSame($result->definition->checksum, $session->session_definition_checksum);
        $this->assertSame($result->definition->toArray(), json_decode((string) $session->session_definition_payload, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('DIRECT_PUBLIC', $grant->origin);
        $this->assertSame($fixture['order'], $grant->order_id);
        $this->assertSame($fixture['entitlement'], $grant->entitlement_id);
        $this->assertSame('in_progress', $source->status);
        $this->assertNotNull($source->started_at);
        $this->assertSame(1, $authority->calls);
        $this->assertFalse($result->replayed);
        $this->assertSame(600, $result->durationSeconds);
        $this->assertSame(600, $result->remainingSeconds);
        $this->assertSame($result->endsAt, $result->writeDeadline);
    }

    /**
     * F2 timed-segments stage 3 (2026-09-22), revision 1 of
     * tasks/handoffs/f2/timed-segments-plan.md: test_sessions.duration_seconds
     * and the session's actual ends_at must cover reading_cap_seconds on top
     * of the timed duration. Every real catalog sets reading_cap_seconds=0
     * today (P8 still open), so this is the only place proving the
     * mechanism itself works before any real data exercises it - including
     * exercising AssessmentSessionAllocationResult's own strict endsAt
     * invariant with a non-zero reading cap for the first time.
     */
    public function test_a_definition_with_a_reading_cap_extends_ends_at_and_the_stored_duration(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthorityWithReadingCap;

        $result = $this->action($authority)->execute(
            new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
            GenericAssessmentInstrument::Ist,
        );

        $session = DB::table('test_sessions')->where('public_id', $result->sessionId)->sole();
        // 600s timed + 30s reading cap.
        $this->assertSame(630, (int) $session->duration_seconds);
        $this->assertEquals(
            $result->startedAt->modify('+630 seconds'),
            $result->endsAt,
        );
        // The client-facing definitional duration stays the timed-only figure.
        $this->assertSame(600, $result->durationSeconds);
        $this->assertSame(630, $result->remainingSeconds);
    }

    public function test_exact_replay_returns_stored_snapshot_and_timestamps_without_calling_authority_again(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $principal = new ParticipantPrincipal($fixture['participant'], $fixture['branch']);
        $action = $this->action($authority);
        $created = $action->execute($principal, GenericAssessmentInstrument::Ist);

        $replayed = $this->action($authority, '2026-09-10T02:01:00.123456+00:00')
            ->execute($principal, GenericAssessmentInstrument::Ist);

        $this->assertTrue($replayed->replayed);
        $this->assertSame($created->sessionId, $replayed->sessionId);
        $this->assertEquals($created->startedAt, $replayed->startedAt);
        $this->assertEquals($created->endsAt, $replayed->endsAt);
        $this->assertSame($created->definition->toArray(), $replayed->definition->toArray());
        $this->assertSame(540, $replayed->remainingSeconds);
        $this->assertSame(1, $authority->calls);
        $this->assertSame(1, DB::table('test_sessions')->count());
        $this->assertSame(1, DB::table('test_session_grants')->count());
    }

    public function test_integrated_allocation_transitions_the_entitlement_then_integrated_participant(): void
    {
        $fixture = AssessmentAccessFixture::create('ist');
        $authority = new FakeAssessmentSessionDefinitionAuthority;

        $result = $this->allocator($authority)->executeIntegrated(
            new AssessmentPrincipal($fixture['participant'], $fixture['organization'], $fixture['attempt']),
            GenericAssessmentInstrument::Ist,
        );

        $session = DB::table('test_sessions')->where('public_id', $result->sessionId)->sole();
        $grant = DB::table('test_session_grants')->where('test_session_id', $session->id)->sole();
        $this->assertSame('INTEGRATED', $grant->origin);
        $this->assertSame($fixture['attempt'], $grant->assessment_participant_id);
        $this->assertSame($fixture['entitlement'], $grant->assessment_entitlement_id);
        $this->assertSame('in_progress', DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])->value('status'));
        $this->assertSame('IN_PROGRESS', DB::table('assessment_participants')->where('id', $fixture['attempt'])->value('assessment_status'));
    }

    public function test_legacy_selection_allocation_persists_the_exact_selection_grant(): void
    {
        $fixture = $this->participantGraph(direct: false);

        $result = $this->action(new FakeAssessmentSessionDefinitionAuthority)->execute(
            new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
            GenericAssessmentInstrument::Ist,
        );

        $session = DB::table('test_sessions')->where('public_id', $result->sessionId)->sole();
        $grant = DB::table('test_session_grants')->where('test_session_id', $session->id)->sole();
        $this->assertSame('LEGACY_SELECTION', $grant->origin);
        $this->assertSame($fixture['selection'], $grant->selection_participant_id);
        $this->assertSame($fixture['entitlement'], $grant->entitlement_id);
        $this->assertNull($grant->order_id);
    }

    public function test_dass_is_rejected_before_sql_or_definition_authority(): void
    {
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            GenericAssessmentInstrument::fromExternal('dass21');
            $this->fail('DASS must never enter the generic allocator.');
        } catch (UnsupportedGenericAssessmentInstrument) {
            $this->assertSame([], $queries);
            $this->assertSame(0, $authority->calls);
        }
    }

    public function test_historical_unbound_session_fails_closed_without_issuing_a_definition(): void
    {
        $fixture = $this->participantGraph(direct: true);
        DB::table('test_sessions')->insert([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $fixture['participant'],
            'assessment_case_id' => $fixture['case'],
            'test_type' => 'ist',
            'attempt_no' => 1,
            'authorization_id' => 'historical-opaque',
            'allocation_intent_id' => 'historical-opaque',
            'duration_seconds' => 600,
            'status' => 'created',
            'answers_revision' => 0,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $authority = new FakeAssessmentSessionDefinitionAuthority;

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('Historical unbound sessions must block allocation.');
        } catch (InvalidAssessmentSessionState|CaseAuthorizationRejected) {
            $this->assertSame(0, $authority->calls);
            $this->assertSame(1, DB::table('test_sessions')->count());
            $this->assertSame(0, DB::table('test_session_grants')->count());
        }
    }

    public function test_terminal_attempt_does_not_replay_or_silently_authorize_a_retest(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $principal = new ParticipantPrincipal($fixture['participant'], $fixture['branch']);
        $this->action($authority)->execute($principal, GenericAssessmentInstrument::Ist);
        $session = DB::table('test_sessions')->sole();
        DB::table('test_sessions')->where('id', $session->id)->update([
            'status' => 'submitted',
            'submitted_at' => $session->started_at,
            'updated_at' => $session->started_at,
        ]);

        try {
            $this->action($authority)->execute($principal, GenericAssessmentInstrument::Ist);
            $this->fail('A terminal attempt must not be replayed or retested.');
        } catch (InvalidAssessmentSessionState) {
            $this->assertSame(1, $authority->calls);
            $this->assertSame(1, DB::table('test_sessions')->count());
        }
    }

    public function test_changed_origin_graph_fails_closed_instead_of_replaying_an_old_grant(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $principal = new ParticipantPrincipal($fixture['participant'], $fixture['branch']);
        $this->action($authority)->execute($principal, GenericAssessmentInstrument::Ist);
        DB::table('participants')->where('id', $fixture['participant'])->update([
            'source_system' => 'SELEKSI_BEASISWA_JEPANG',
        ]);

        try {
            $this->action($authority)->execute($principal, GenericAssessmentInstrument::Ist);
            $this->fail('A changed origin graph must not replay an old grant.');
        } catch (CaseAuthorizationRejected) {
            $this->assertSame(1, $authority->calls);
            $this->assertSame(1, DB::table('test_sessions')->count());
        }
    }

    public function test_participant_context_is_not_silently_elevated_to_the_internal_service_allocator(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $contexts = app(RlsContextRunner::class);

        try {
            $contexts->run(
                new RlsContext('participant', $fixture['branch'], $fixture['participant']),
                fn () => $this->action($authority)->execute(
                    new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                    GenericAssessmentInstrument::Ist,
                ),
            );
            $this->fail('Participant context must not elevate itself to service.');
        } catch (LogicException) {
            $this->assertSame(0, $authority->calls);
            $this->assertSame(0, DB::table('test_sessions')->count());
        }
    }

    public function test_source_transition_failure_rolls_back_session_grant_and_snapshot(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        DB::unprepared(<<<'SQL'
            CREATE TEMP TRIGGER allocator_source_failure BEFORE UPDATE OF status ON entitlements
            WHEN OLD.test_type = 'ist'
                AND OLD.status = 'ready'
                AND OLD.started_at IS NULL
                AND NEW.status = 'in_progress'
                AND NEW.started_at IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'injected source failure'); END
            SQL);

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('Injected source failure must escape the allocator.');
        } catch (QueryException) {
            DB::unprepared('DROP TRIGGER allocator_source_failure');
            $this->assertSame(0, DB::table('test_sessions')->count());
            $this->assertSame(0, DB::table('test_session_grants')->count());
            $this->assertSame('ready', DB::table('entitlements')->where('id', $fixture['entitlement'])->value('status'));
            $this->assertSame(1, $authority->calls);
        }
    }

    public function test_session_insert_failure_rolls_back_without_source_transition(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        DB::unprepared("CREATE TEMP TRIGGER allocator_session_insert_failure BEFORE INSERT ON test_sessions
            BEGIN SELECT RAISE(ABORT, 'injected session insert failure'); END");

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('Injected session insert failure must escape the allocator.');
        } catch (QueryException) {
            DB::unprepared('DROP TRIGGER allocator_session_insert_failure');
            $this->assertSame(1, $authority->calls);
            $this->assertAllocatorRolledBack($fixture);
        }
    }

    public function test_grant_insert_failure_rolls_back_the_session_and_source_transition(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        DB::unprepared("CREATE TEMP TRIGGER allocator_grant_insert_failure BEFORE INSERT ON test_session_grants
            BEGIN SELECT RAISE(ABORT, 'injected grant insert failure'); END");

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('Injected grant insert failure must escape the allocator.');
        } catch (QueryException) {
            DB::unprepared('DROP TRIGGER allocator_grant_insert_failure');
            $this->assertSame(1, $authority->calls);
            $this->assertAllocatorRolledBack($fixture);
        }
    }

    public function test_definition_failure_leaves_no_writes_or_context_state(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $failure = new RuntimeException('synthetic definition failure');

        try {
            $this->action(new ThrowingAssessmentSessionDefinitionAuthority($failure))->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('Definition failure must escape the command.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertAllocatorRolledBack($fixture);
        }
    }

    /**
     * F2 item-delivery Stage 1 (2026-09-21): a session must never start for
     * an instrument whose item content cannot be delivered -- otherwise the
     * participant's timer runs against a question screen with nothing to
     * show. Called after the definition authority succeeds but before any
     * row is written, so it rolls back exactly like a definition failure
     * does.
     */
    public function test_item_content_failure_leaves_no_writes_or_context_state(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $failure = new RuntimeException('synthetic item content failure');

        try {
            $this->action(
                new FakeAssessmentSessionDefinitionAuthority,
                itemContent: new ThrowingAssessmentItemContentAuthority($failure),
            )->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('Item content failure must escape the command.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertAllocatorRolledBack($fixture);
        }
    }

    public function test_final_session_transition_failure_rolls_back_every_prior_write(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        DB::unprepared("CREATE TEMP TRIGGER allocator_final_failure BEFORE UPDATE OF status ON test_sessions
            WHEN NEW.status = 'in_progress' BEGIN SELECT RAISE(ABORT, 'injected final failure'); END");

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('Injected final transition failure must escape the allocator.');
        } catch (QueryException) {
            DB::unprepared('DROP TRIGGER allocator_final_failure');
            $this->assertSame(0, DB::table('test_sessions')->count());
            $this->assertSame(0, DB::table('test_session_grants')->count());
            $this->assertSame('ready', DB::table('entitlements')->where('id', $fixture['entitlement'])->value('status'));
            $this->assertNull(DB::table('entitlements')->where('id', $fixture['entitlement'])->value('started_at'));
            $this->assertSame(1, $authority->calls);
        }
    }

    #[DataProvider('retryableSqlStates')]
    public function test_only_retryable_transaction_sqlstates_restart_the_whole_transaction_and_then_succeed(
        string $sqlState,
    ): void {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $injector = $this->injectFinalTransitionFailures([$sqlState]);

        $result = $this->action($authority)->execute(
            new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
            GenericAssessmentInstrument::Ist,
        );

        $this->assertFalse($result->replayed);
        $this->assertSame(2, $injector->transactionAttempts);
        $this->assertSame($injector->transactionAttempts, $authority->calls);
        $this->assertSame(1, DB::table('test_sessions')->count());
        $this->assertSame(1, DB::table('test_session_grants')->count());
        $this->assertSame('in_progress', DB::table('entitlements')->where('id', $fixture['entitlement'])->value('status'));
        $this->assertNotNull(DB::table('entitlements')->where('id', $fixture['entitlement'])->value('started_at'));
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_retryable_transaction_failure_is_attempted_at_most_three_times_then_a_typed_exhaustion_escapes(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $injector = $this->injectFinalTransitionFailures(['40001', '40001', '40001', '40001']);

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('A fourth transaction attempt must never be made.');
        } catch (AssessmentSessionStartRetriesExhausted $exception) {
            // F2 S5 (2026-09-21): the command wraps the exhausted-retry
            // QueryException in a typed exception so the HTTP boundary never has
            // to inspect a SQLSTATE itself (Correction A's leak, one layer up).
            // The raw QueryException is still reachable via getPrevious() for
            // logging, but nothing outside this command should catch it directly.
            $previous = $exception->getPrevious();
            $this->assertInstanceOf(QueryException::class, $previous);
            $this->assertSame('40001', $previous->errorInfo[0] ?? null);
            $this->assertSame($injector->lastException, $previous);
            $this->assertSame(3, $injector->transactionAttempts);
            $this->assertSame($injector->transactionAttempts, $authority->calls);
            $this->assertAllocatorRolledBack($fixture);
        }
    }

    #[DataProvider('nonRetryableSqlStates')]
    public function test_non_retryable_sqlstates_escape_after_one_transaction_attempt(string $sqlState): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $injector = $this->injectFinalTransitionFailures([$sqlState, $sqlState]);

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail("SQLSTATE {$sqlState} must not be retried.");
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);
            $this->assertSame(1, $injector->transactionAttempts);
            $this->assertSame(1, $authority->calls);
            $this->assertAllocatorRolledBack($fixture);
        }
    }

    public function test_generic_non_sql_failure_is_not_retried_and_leaves_no_transaction_state(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $failure = new RuntimeException('synthetic non-SQL failure');
        $injector = $this->injectFinalTransitionFailures([$failure, $failure]);

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            );
            $this->fail('Generic failures must not be retried.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertSame(1, $injector->transactionAttempts);
            $this->assertSame(1, $authority->calls);
            $this->assertAllocatorRolledBack($fixture);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function retryableSqlStates(): iterable
    {
        yield 'serialization failure' => ['40001'];
        yield 'deadlock detected' => ['40P01'];
    }

    /** @return iterable<string, array{string}> */
    public static function nonRetryableSqlStates(): iterable
    {
        yield 'unique violation' => ['23505'];
        yield 'check violation' => ['23514'];
        yield 'insufficient privilege' => ['42501'];
        yield 'generic driver error' => ['HY000'];
    }

    /** @return array{branch:int,participant:int,package:int,case:int,order:int|null,selection:int|null,entitlement:int} */
    private function participantGraph(bool $direct): array
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
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach (['dass21', 'ist'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package,
                'test_type' => $type,
                'sort_order' => $sort,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'package_id' => $direct ? $package : null,
            'branch_id' => $branch,
            'referral_branch_id' => $branch,
            'referral_source' => 'manual',
            'source_system' => $direct ? 'DIRECT_PUBLIC' : 'SELEKSI_BEASISWA_JEPANG',
            'full_name' => 'Synthetic',
            'intended_field' => 'KAIGO',
            'phone' => '620000000000',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId,
            'participant_id' => $participant,
            'organization_id' => $branch,
            'package_id' => $direct ? $package : null,
            'origin' => $direct ? 'DIRECT_PUBLIC' : 'LEGACY_SELECTION',
            'intended_field_snapshot' => 'KAIGO',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $order = null;
        $selection = null;
        if ($direct) {
            $order = DB::table('orders')->insertGetId([
                'public_id' => $publicId,
                'participant_id' => $participant,
                'assessment_case_id' => $case,
                'payment_method_id' => null,
                'status' => 'paid',
                'amount' => 0,
                'currency' => 'IDR',
                'paid_at' => self::NOW,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        } else {
            $selection = DB::table('selection_participants')->insertGetId([
                'client_id' => 'client-'.$key,
                'external_candidate_id' => 'candidate-'.$key,
                'selection_round_id' => 'round-'.$key,
                'registration_id' => 'registration-'.$key,
                'participant_id' => $participant,
                'assessment_case_id' => $case,
                'idempotency_key' => 'key-'.$key,
                'request_hash' => hash('sha256', $key),
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
        foreach (['dass21', 'ist'] as $type) {
            $id = DB::table('entitlements')->insertGetId([
                'participant_id' => $participant,
                'order_id' => $order,
                'assessment_case_id' => $type === 'dass21' ? null : $case,
                'test_type' => $type,
                'status' => 'ready',
                'ready_at' => now()->subSecond(),
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            if ($type === 'ist') {
                $entitlement = $id;
            }
        }

        return compact('branch', 'participant', 'package', 'case', 'order', 'selection', 'entitlement');
    }

    /**
     * @param  list<string|Throwable>  $failures
     */
    private function injectFinalTransitionFailures(array $failures): FinalTransitionFailureInjector
    {
        $injector = new FinalTransitionFailureInjector($failures);
        DB::listen($injector->handle(...));

        return $injector;
    }

    /** @param array{entitlement: int} $fixture */
    private function assertAllocatorRolledBack(array $fixture): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('test_sessions')->count());
        $this->assertSame(0, DB::table('test_session_grants')->count());
        $this->assertSame('ready', DB::table('entitlements')->where('id', $fixture['entitlement'])->value('status'));
        $this->assertNull(DB::table('entitlements')->where('id', $fixture['entitlement'])->value('started_at'));
    }

    private function action(
        AssessmentSessionDefinitionAuthority $authority,
        string $now = self::NOW,
        ?AssessmentItemContentAuthority $itemContent = null,
    ): StartParticipantAssessmentSession {
        return new StartParticipantAssessmentSession(
            app(RlsContextRunner::class),
            app(ParticipantAssessmentSessionCandidates::class),
            new AssessmentSessionSelectionPolicy,
            app(CaseAuthorizationResolver::class),
            $this->allocator($authority, $now, $itemContent),
        );
    }

    private function allocator(
        AssessmentSessionDefinitionAuthority $authority,
        string $now = self::NOW,
        ?AssessmentItemContentAuthority $itemContent = null,
    ): AllocateAndStartAssessmentSession {
        return new AllocateAndStartAssessmentSession(
            app(RlsContextRunner::class),
            app(CaseAuthorizationResolver::class),
            $authority,
            $itemContent ?? new AlwaysAvailableAssessmentItemContentAuthority,
            new AssessmentAttemptAllocationPolicy,
            new AssessmentSessionStateMachine,
            new AssessmentSessionDeadlinePolicy,
            static fn (): DateTimeImmutable => new DateTimeImmutable($now),
        );
    }
}

final readonly class ThrowingAssessmentSessionDefinitionAuthority implements AssessmentSessionDefinitionAuthority
{
    public function __construct(private RuntimeException $failure) {}

    public function issueForNewSession(
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
        string $sessionPublicId,
    ): SessionDefinition {
        throw $this->failure;
    }
}

final readonly class ThrowingAssessmentItemContentAuthority implements AssessmentItemContentAuthority
{
    public function __construct(private RuntimeException $failure) {}

    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
    ): AssessmentItemContent {
        throw $this->failure;
    }
}

final class FakeAssessmentSessionDefinitionAuthority implements AssessmentSessionDefinitionAuthority
{
    public int $calls = 0;

    public function issueForNewSession(
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
        string $sessionPublicId,
    ): SessionDefinition {
        $this->calls++;
        $payload = [
            'instrument' => $instrument->value,
            'version' => 'synthetic-v1',
            'provenance' => 'allocator-feature-test',
            'total_duration_seconds' => 600,
            'subtests' => [['code' => 'all', 'duration_seconds' => 600, 'item_count' => 10]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
        $payload['checksum'] = SessionDefinition::checksumFor($payload);

        return SessionDefinition::fromArray($payload);
    }
}

final class FakeAssessmentSessionDefinitionAuthorityWithReadingCap implements AssessmentSessionDefinitionAuthority
{
    public function issueForNewSession(
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
        string $sessionPublicId,
    ): SessionDefinition {
        $payload = [
            'instrument' => $instrument->value,
            'version' => 'synthetic-v1',
            'provenance' => 'allocator-feature-test-reading-cap',
            'total_duration_seconds' => 600,
            'subtests' => [[
                'code' => 'all',
                'duration_seconds' => 600,
                'item_count' => 10,
                'reading_cap_seconds' => 30,
                'allow_early_finish' => false,
            ]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
        $payload['checksum'] = SessionDefinition::checksumFor($payload);

        return SessionDefinition::fromArray($payload);
    }
}

final class FinalTransitionFailureInjector
{
    public int $transactionAttempts = 0;

    public ?QueryException $lastException = null;

    /** @param list<string|Throwable> $failures */
    public function __construct(private array $failures) {}

    public function handle(QueryExecuted $query): void
    {
        if (! str_contains(strtolower($query->sql), 'update "test_sessions"')
            || ! in_array('in_progress', $query->bindings, true)) {
            return;
        }

        $this->transactionAttempts++;
        $failure = array_shift($this->failures);
        if ($failure === null) {
            return;
        }
        if ($failure instanceof Throwable) {
            throw $failure;
        }

        $previous = new PDOException("Synthetic SQLSTATE {$failure}");
        $previous->errorInfo = [$failure, 0, 'synthetic final-transition failure'];
        $this->lastException = new QueryException('sqlite', $query->sql, $query->bindings, $previous);

        throw $this->lastException;
    }
}
