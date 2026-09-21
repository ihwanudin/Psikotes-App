<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\OrganizationPaymentTestCase;

/**
 * F2 S5 (2026-09-21). HTTP-level coverage for the real, ADR-0030-compliant
 * POST /api/sessions/{testType}/start. Deliberately DatabaseTruncation, not
 * RefreshDatabase: StartParticipantAssessmentSession::assertCleanOuterBoundary()
 * requires DB::transactionLevel() === 0 before it runs any SQL, and
 * RefreshDatabase wraps every test method in its own outer transaction for
 * rollback-based isolation, which makes that boundary check structurally
 * unsatisfiable -- not a bug in the command, just incompatible with that
 * trait. Any future test that calls this route through the real command
 * belongs in a DatabaseTruncation-based file, the same convention already
 * established by AllocateAndStartAssessmentSessionTest.php and
 * StartParticipantAssessmentSessionTest.php for the command itself. Getting
 * this wrong surfaces as a LogicException mapped to 500 by the controller's
 * catch-all, which looks like a code bug, not a test-setup problem -- see
 * tasks/handoffs/f2/s5-http-cutover-classification.md.
 *
 * Two assertions here were moved out of existing files rather than newly
 * written, per explicit coordinator sign-off (2026-09-21):
 * - the "legacy/unrecognised-origin participant reaches the real route and is
 *   rejected" case, from tests/Feature/Auth/AssessmentSessionAuthorizationTest.php;
 * - the "freshly, fully legitimately activated participant with no instrument
 *   manifest approved yet gets a fail-closed 503, not the old placeholder"
 *   case, from tests/Feature/F1/ManualActivationFlowTest.php.
 */
final class StartParticipantSessionHttpTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private const NOW = '2026-09-21T02:00:00.123456+00:00';

    public function test_new_allocation_returns_200_with_the_assessment_session_shape(): void
    {
        $this->bindFakeAuthority();
        $fixture = $this->participantGraph(direct: true);

        $response = $this->withToken($this->issueToken($fixture))
            ->postJson('/api/sessions/ist/start')
            ->assertOk();

        $response->assertJsonPath('test_type', 'ist')
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('attempt_no', 1)
            ->assertJsonPath('answers_revision', 0)
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('submitted_at', null)
            ->assertJsonPath('config.total_duration_seconds', 600)
            ->assertJsonPath('seed', null)
            ->assertJsonStructure(['session_id', 'started_at', 'ends_at', 'write_deadline', 'server_time', 'remaining_seconds']);
        $this->assertTrue(Str::isUlid($response->json('session_id')));
    }

    public function test_second_request_replays_the_same_session(): void
    {
        $this->bindFakeAuthority();
        $fixture = $this->participantGraph(direct: true);
        $token = $this->issueToken($fixture);

        $first = $this->withToken($token)->postJson('/api/sessions/ist/start')->assertOk();
        $second = $this->withToken($token)->postJson('/api/sessions/ist/start')->assertOk();

        $second->assertJsonPath('replayed', true);
        $this->assertSame($first->json('session_id'), $second->json('session_id'));
        $this->assertSame($first->json('started_at'), $second->json('started_at'));
        $this->assertSame(1, DB::table('test_sessions')->count());
    }

    public function test_dass21_and_scope_selectors_are_rejected_with_422(): void
    {
        $this->bindFakeAuthority();
        $fixture = $this->participantGraph(direct: true);
        $token = $this->issueToken($fixture);

        $this->withToken($token)->postJson('/api/sessions/dass21/start')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_ASSESSMENT_START_REQUEST');
        $this->withToken($token)->postJson('/api/sessions/ist/start', ['assessment_participant_id' => 999])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_ASSESSMENT_START_REQUEST');
        $this->assertSame(0, DB::table('test_sessions')->count());
    }

    /**
     * Moved from AssessmentSessionAuthorizationTest.php (2026-09-21): a legacy
     * ParticipantJwt for a participant whose source_system the real command's
     * origin resolution does not recognise (only DIRECT_PUBLIC and
     * SELEKSI_BEASISWA_JEPANG) must fail closed, not silently pass through.
     */
    public function test_unrecognized_participant_origin_is_rejected_with_403(): void
    {
        $this->bindFakeAuthority();
        $fixture = $this->participantGraph(direct: true);
        DB::table('participants')->where('id', $fixture['participant'])->update(['source_system' => 'P6B_TEST']);
        $token = app(ParticipantJwt::class)->issue($fixture['participant'], $fixture['branch']);

        $this->withToken($token)->postJson('/api/sessions/ist/start')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ASSESSMENT_NOT_AVAILABLE');
        $this->assertSame(0, DB::table('test_sessions')->count());
    }

    /**
     * Moved from tests/Feature/Auth/ParticipantApiAuthorizationTest.php
     * (2026-09-21): a genuine contract change, not a relocated assertion --
     * that test asserted 403 ENTITLEMENT_LOCKED, produced by the old
     * controller's own ParticipantEntitlementGate::assertReady() check.
     * ADR-0030 removes that check entirely (the controller must not read
     * entitlements). The real command rejects a locked entitlement the same
     * way it rejects "no ready entitlement at all": the candidate's
     * eligibility check requires status==='ready', so a locked entitlement
     * produces the same Unavailable selection outcome and the same generic
     * 403 ASSESSMENT_NOT_AVAILABLE bucket ADR-0030 assigns to entitlement
     * state generally -- by design, not an oversight (see the handoff doc).
     * Kept as its own test rather than folded into the unrecognized-origin
     * case above: same status code, genuinely different cause: a combined
     * test would still pass if one of the two paths silently stopped being
     * rejected.
     */
    public function test_locked_entitlement_is_rejected_with_403(): void
    {
        $this->bindFakeAuthority();
        $fixture = $this->participantGraph(direct: true);
        DB::table('entitlements')->where('id', $fixture['entitlement'])->update(['status' => 'locked', 'ready_at' => null]);

        $this->withToken($this->issueToken($fixture))->postJson('/api/sessions/ist/start')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ASSESSMENT_NOT_AVAILABLE');
        $this->assertSame(0, DB::table('test_sessions')->count());
        $this->assertDatabaseHas('entitlements', ['id' => $fixture['entitlement'], 'status' => 'locked']);
    }

    public function test_multiple_ready_cases_are_rejected_as_ambiguous_with_409(): void
    {
        $this->bindFakeAuthority();
        $fixture = $this->participantGraph(direct: true);
        $this->addSecondReadyCase($fixture);
        $token = $this->issueToken($fixture);

        $this->withToken($token)->postJson('/api/sessions/ist/start')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ASSESSMENT_START_CONFLICT');
        $this->assertSame(0, DB::table('test_sessions')->count());
    }

    /**
     * Moved from ManualActivationFlowTest.php (2026-09-21): no instrument
     * manifest is approved in any environment yet (all 4 generic instruments
     * remain catalog-blocked per tasks/handoffs/f2-adr0030-start-flow-readiness.md),
     * so even a fully legitimate, freshly-activated participant must get a
     * fail-closed 503 distinct from a 500 -- this IS the production path
     * every real request hits today, not a hypothetical edge case.
     */
    public function test_freshly_activated_participant_with_no_catalog_entry_gets_503(): void
    {
        $fixture = $this->participantGraph(direct: true);

        $this->withToken($this->issueToken($fixture))->postJson('/api/sessions/ist/start')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ASSESSMENT_DEFINITION_UNAVAILABLE');
        $this->assertSame(0, DB::table('test_sessions')->count());
    }

    public function test_invalid_active_catalog_entry_also_fails_closed_with_503(): void
    {
        $fixture = $this->participantGraph(direct: true);
        // A half-formed catalog row: the payload is well-formed JSON, but its
        // stored checksum does not match it (assessment_session_definitions has
        // a single-row-per-instrument unique constraint, so this -- not a
        // second active row -- is how a corrupted/half-written catalog entry is
        // constructed; mirrors DatabaseAssessmentSessionDefinitionAuthorityTest
        // ::test_corrupt_active_template_fails_closed). This is the "half-
        // formed definition must fail closed" case distinct from the empty-
        // catalog test above -- both map to the same 503, but only running
        // both proves neither path was accidentally left open.
        $this->insertActiveCatalogRow('synthetic-v1', corruptChecksum: true);

        $this->withToken($this->issueToken($fixture))->postJson('/api/sessions/ist/start')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ASSESSMENT_DEFINITION_UNAVAILABLE');
        $this->assertSame(0, DB::table('test_sessions')->count());
    }

    public function test_retries_exhausted_on_the_real_route_returns_503(): void
    {
        $this->bindFakeAuthority();
        $fixture = $this->participantGraph(direct: true);
        $injector = new HttpFinalTransitionFailureInjector(['40001', '40001', '40001', '40001']);
        DB::listen($injector->handle(...));

        $this->withToken($this->issueToken($fixture))->postJson('/api/sessions/ist/start')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ASSESSMENT_START_TEMPORARILY_UNAVAILABLE');
        $this->assertSame(3, $injector->transactionAttempts);
        $this->assertSame(0, DB::table('test_sessions')->count());
    }

    private function bindFakeAuthority(): void
    {
        $this->app->instance(AssessmentSessionDefinitionAuthority::class, new class implements AssessmentSessionDefinitionAuthority
        {
            public function issueForNewSession(
                GenericAssessmentInstrument $instrument,
                CaseAuthorization $authorization,
                string $sessionPublicId,
            ): SessionDefinition {
                $payload = [
                    'instrument' => $instrument->value,
                    'version' => 'synthetic-v1',
                    'provenance' => 's5-http-test',
                    'total_duration_seconds' => 600,
                    'subtests' => [['code' => 'all', 'duration_seconds' => 600, 'item_count' => 10]],
                    'randomization' => 'fixed',
                    'seed' => null,
                    'generator' => null,
                ];
                $payload['checksum'] = SessionDefinition::checksumFor($payload);

                return SessionDefinition::fromArray($payload);
            }
        });
    }

    private function issueToken(array $fixture): string
    {
        return app(ParticipantJwt::class)->issue($fixture['participant'], $fixture['branch']);
    }

    /** @return array{branch:int,package:int,participant:int,case:int,order:int,entitlement:int} */
    private function participantGraph(bool $direct): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $key, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => 'Synthetic', 'amount' => 99000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        foreach (['dass21', 'ist'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'package_id' => $direct ? $package : null, 'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'manual', 'source_system' => $direct ? 'DIRECT_PUBLIC' : 'SELEKSI_BEASISWA_JEPANG',
            'full_name' => 'Synthetic', 'phone' => '620000000000', 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'organization_id' => $branch,
            'package_id' => $direct ? $package : null, 'origin' => $direct ? 'DIRECT_PUBLIC' : 'LEGACY_SELECTION',
            'intended_field_snapshot' => null, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'assessment_case_id' => $case,
            'payment_method_id' => null, 'status' => 'paid', 'amount' => 0, 'currency' => 'IDR',
            'paid_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $entitlementIds = [];
        foreach (['dass21', 'ist'] as $type) {
            $entitlementIds[$type] = DB::table('entitlements')->insertGetId([
                'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
                'assessment_case_id' => $type === 'dass21' ? null : $case,
                'status' => 'ready', 'ready_at' => now()->subSecond(), 'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
        }

        return compact('branch', 'package', 'participant', 'case', 'order') + ['entitlement' => $entitlementIds['ist']];
    }

    /** @param array{branch:int,package:int,participant:int,case:int,order:int,entitlement:int} $fixture */
    private function addSecondReadyCase(array $fixture): void
    {
        // Only a second case-scoped 'ist' entitlement, deliberately no second
        // 'dass21': dass21 is participant-global, not case-scoped
        // (entitlements_dass_participant_unique is a partial unique index on
        // participant_id WHERE test_type='dass21' AND assessment_case_id IS
        // NULL), so the first case's dass21 entitlement already covers this
        // participant and a second one would violate that index.
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $fixture['participant'], 'organization_id' => $fixture['branch'],
            'package_id' => $fixture['package'], 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $fixture['participant'], 'assessment_case_id' => $case,
            'payment_method_id' => null, 'status' => 'paid', 'amount' => 0, 'currency' => 'IDR',
            'paid_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        DB::table('entitlements')->insert([
            'participant_id' => $fixture['participant'], 'order_id' => $order, 'test_type' => 'ist',
            'assessment_case_id' => $case,
            'status' => 'ready', 'ready_at' => now()->subSecond(), 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
    }

    private function insertActiveCatalogRow(string $version, bool $corruptChecksum = false): void
    {
        $template = [
            'instrument' => 'ist', 'version' => $version, 'provenance' => 's5-http-test',
            'total_duration_seconds' => 60, 'subtests' => [['code' => 'SYNTHETIC', 'duration_seconds' => 60, 'item_count' => 1]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        DB::table('assessment_session_definitions')->insert([
            'instrument' => 'ist', 'version' => $version, 'provenance' => 's5-http-test',
            'template_checksum' => $corruptChecksum ? str_repeat('a', 64) : SessionDefinition::checksumFor($template),
            'template_payload' => json_encode($template, JSON_THROW_ON_ERROR),
            'is_active' => true, 'activated_at' => now(), 'deactivated_at' => null,
        ]);
    }
}

final class HttpFinalTransitionFailureInjector
{
    public int $transactionAttempts = 0;

    /** @param list<string> $failures */
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

        $previous = new PDOException("Synthetic SQLSTATE {$failure}");
        $previous->errorInfo = [$failure, 0, 'synthetic final-transition failure'];

        throw new QueryException('sqlite', $query->sql, $query->bindings, $previous);
    }
}
