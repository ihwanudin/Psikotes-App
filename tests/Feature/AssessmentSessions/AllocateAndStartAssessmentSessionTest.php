<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\AllocateAndStartAssessmentSession;
use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
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
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class AllocateAndStartAssessmentSessionTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-10T02:00:00.123456+00:00';

    public function test_direct_public_allocation_persists_the_full_snapshot_grant_and_exact_source_transition(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;

        $result = $this->action($authority)->execute(
            new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
            'ist',
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

    public function test_exact_replay_returns_stored_snapshot_and_timestamps_without_calling_authority_again(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        $principal = new ParticipantPrincipal($fixture['participant'], $fixture['branch']);
        $action = $this->action($authority);
        $created = $action->execute($principal, 'ist');

        $replayed = $this->action($authority, '2026-09-10T02:01:00.123456+00:00')->execute($principal, 'ist');

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

        $result = $this->action($authority)->execute(
            new AssessmentPrincipal($fixture['participant'], $fixture['organization'], $fixture['attempt']),
            'ist',
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
            'ist',
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
            $this->action($authority)->execute(new ParticipantPrincipal(999, 999), 'dass21');
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
                'ist',
            );
            $this->fail('Historical unbound sessions must block allocation.');
        } catch (InvalidAssessmentSessionState) {
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
        $this->action($authority)->execute($principal, 'ist');
        $session = DB::table('test_sessions')->sole();
        DB::table('test_sessions')->where('id', $session->id)->update([
            'status' => 'submitted',
            'submitted_at' => $session->started_at,
            'updated_at' => $session->started_at,
        ]);

        try {
            $this->action($authority)->execute($principal, 'ist');
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
        $this->action($authority)->execute($principal, 'ist');
        DB::table('participants')->where('id', $fixture['participant'])->update([
            'source_system' => 'SELEKSI_BEASISWA_JEPANG',
        ]);

        try {
            $this->action($authority)->execute($principal, 'ist');
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
                    'ist',
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
        DB::unprepared("CREATE TEMP TRIGGER allocator_source_failure BEFORE UPDATE OF status ON entitlements
            WHEN OLD.id = {$fixture['entitlement']} BEGIN SELECT RAISE(ABORT, 'injected source failure'); END");

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                'ist',
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

    public function test_final_session_transition_failure_rolls_back_every_prior_write(): void
    {
        $fixture = $this->participantGraph(direct: true);
        $authority = new FakeAssessmentSessionDefinitionAuthority;
        DB::unprepared("CREATE TEMP TRIGGER allocator_final_failure BEFORE UPDATE OF status ON test_sessions
            WHEN NEW.status = 'in_progress' BEGIN SELECT RAISE(ABORT, 'injected final failure'); END");

        try {
            $this->action($authority)->execute(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                'ist',
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
                'test_type' => $type,
                'status' => 'ready',
                'ready_at' => now(),
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            if ($type === 'ist') {
                $entitlement = $id;
            }
        }

        return compact('branch', 'participant', 'package', 'case', 'order', 'selection', 'entitlement');
    }

    private function action(
        FakeAssessmentSessionDefinitionAuthority $authority,
        string $now = self::NOW,
    ): AllocateAndStartAssessmentSession {
        return new AllocateAndStartAssessmentSession(
            app(RlsContextRunner::class),
            app(CaseAuthorizationResolver::class),
            $authority,
            new AssessmentAttemptAllocationPolicy,
            new AssessmentSessionStateMachine,
            new AssessmentSessionDeadlinePolicy,
            static fn (): DateTimeImmutable => new DateTimeImmutable($now),
        );
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
