<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentSessions\AllocateAndStartAssessmentSession;
use App\Actions\AssessmentSessions\StartParticipantAssessmentSession;
use App\Contracts\AssessmentItemContentAuthority;
use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy;
use App\Domain\AssessmentSessions\AssessmentItemContent;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Http\Controllers\StartParticipantSessionController;
use App\Http\Requests\StartGenericAssessmentSessionRequest;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\AssessmentSessions\ParticipantAssessmentSessionCandidates;
use App\Services\AssessmentSessions\RegistryAssessmentItemContentAuthority;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F2 S5, Correction B (2026-09-21). Removing the controller's entitlement
 * gates (ADR-0030: the controller must not read entitlements at all) is a
 * regression unless something still proves an unentitled participant is
 * rejected -- and proves it against real PostgreSQL RLS/GRANT enforcement,
 * not just SQLite, since that is exactly the environment S4 found a
 * production-breaking gap in that SQLite could never have caught.
 */
final class StartParticipantSessionControllerSecurityTest extends TestCase
{
    /** @var list<array{branch:int,package:int,participant:int,case:int,order:int,paymentMethod:int}> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $this->cleanupFixture($fixture);
        }
        $this->fixtures = [];
        parent::tearDown();
    }

    public function test_participant_with_no_ready_ist_entitlement_is_rejected_with_403_through_the_controller(): void
    {
        $fixture = $this->participantGraphWithoutIstEntitlement();
        $request = StartGenericAssessmentSessionRequest::create('/api/sessions/ist/start', 'POST');
        $request->attributes->set('participant_principal', new ParticipantPrincipal($fixture['participant'], $fixture['branch']));

        $controller = new StartParticipantSessionController;
        $response = $controller($request, 'ist', $this->command());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $response->getData(true)['error']['code']);
        app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
            $this->assertSame(0, DB::table('test_sessions')->where('participant_id', $fixture['participant'])->count());
        });

        $this->fixtures[] = $fixture;
    }

    /**
     * F2 item-delivery Stage 1 (2026-09-21), Lead's explicit requirement:
     * the same production behaviour proven on SQLite
     * (StartParticipantSessionHttpTest::test_real_container_binding_rejects_start_for_an_instrument_with_no_registered_item_content_reader),
     * proven again here against real PostgreSQL RLS/transaction semantics.
     * Uses the actual RegistryAssessmentItemContentAuthority class with zero
     * registered readers -- not a throwing test double -- so this proves
     * the real production class fails closed under Postgres, not just that
     * some class can be made to throw.
     */
    public function test_participant_with_a_ready_entitlement_is_rejected_with_503_when_no_item_content_reader_is_registered(): void
    {
        $fixture = $this->participantGraphWithReadyIstEntitlement();
        $request = StartGenericAssessmentSessionRequest::create('/api/sessions/ist/start', 'POST');
        $request->attributes->set('participant_principal', new ParticipantPrincipal($fixture['participant'], $fixture['branch']));

        $controller = new StartParticipantSessionController;
        $response = $controller($request, 'ist', $this->commandWithRealItemContentGate());

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('ASSESSMENT_ITEM_CONTENT_UNAVAILABLE', $response->getData(true)['error']['code']);
        app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
            $this->assertSame(0, DB::table('test_sessions')->where('participant_id', $fixture['participant'])->count());
            $this->assertSame(0, DB::table('test_session_grants')->where('participant_id', $fixture['participant'])->count());
        });

        $this->fixtures[] = $fixture;
    }

    private function commandWithRealItemContentGate(): StartParticipantAssessmentSession
    {
        $contexts = app(RlsContextRunner::class);

        return new StartParticipantAssessmentSession(
            $contexts,
            app(ParticipantAssessmentSessionCandidates::class),
            new AssessmentSessionSelectionPolicy,
            app(CaseAuthorizationResolver::class),
            new AllocateAndStartAssessmentSession(
                $contexts,
                app(CaseAuthorizationResolver::class),
                new class implements AssessmentSessionDefinitionAuthority
                {
                    public function issueForNewSession(
                        GenericAssessmentInstrument $instrument,
                        CaseAuthorization $authorization,
                        string $sessionPublicId,
                    ): SessionDefinition {
                        $payload = [
                            'instrument' => $instrument->value, 'version' => 'synthetic-v1',
                            'provenance' => 's5-security-test', 'total_duration_seconds' => 60,
                            'subtests' => [['code' => 'all', 'duration_seconds' => 60, 'item_count' => 1]],
                            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
                        ];
                        $payload['checksum'] = SessionDefinition::checksumFor($payload);

                        return SessionDefinition::fromArray($payload);
                    }
                },
                new RegistryAssessmentItemContentAuthority([]),
                new AssessmentAttemptAllocationPolicy,
                new AssessmentSessionStateMachine,
                new AssessmentSessionDeadlinePolicy,
            ),
        );
    }

    /** @return array{branch:int,package:int,participant:int,case:int,order:int,paymentMethod:int} */
    private function participantGraphWithReadyIstEntitlement(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'S5 Synthetic',
                'organization_code' => $key, 'display_name' => 'S5 Synthetic',
            ]);
            $package = DB::table('packages')->insertGetId([
                'code' => 'PKG-'.$key, 'name' => 'S5 Synthetic', 'amount' => 99000,
                'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['dass21', 'ist'] as $sort => $type) {
                DB::table('package_items')->insert([
                    'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
                'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
                'full_name' => 'S5 Synthetic', 'phone' => '620000000000',
            ]);
            $publicId = (string) Str::ulid();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant,
                'organization_id' => $branch, 'package_id' => $package,
                'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $paymentMethod = DB::table('payment_methods')->insertGetId([
                'code' => 'METHOD-'.$key, 'display_name' => $key, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $order = DB::table('orders')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant,
                'assessment_case_id' => $case, 'payment_method_id' => $paymentMethod,
                'status' => 'paid', 'amount' => 99000, 'currency' => 'IDR', 'paid_at' => now()->subMinute(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['dass21', 'ist'] as $type) {
                DB::table('entitlements')->insert([
                    'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
                    'assessment_case_id' => $type === 'dass21' ? null : $case,
                    'status' => 'ready', 'ready_at' => now()->subMinute(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return compact('branch', 'package', 'participant', 'case', 'order', 'paymentMethod');
        });
    }

    private function command(): StartParticipantAssessmentSession
    {
        $contexts = app(RlsContextRunner::class);

        return new StartParticipantAssessmentSession(
            $contexts,
            app(ParticipantAssessmentSessionCandidates::class),
            new AssessmentSessionSelectionPolicy,
            app(CaseAuthorizationResolver::class),
            new AllocateAndStartAssessmentSession(
                $contexts,
                app(CaseAuthorizationResolver::class),
                new class implements AssessmentSessionDefinitionAuthority
                {
                    public function issueForNewSession(
                        GenericAssessmentInstrument $instrument,
                        CaseAuthorization $authorization,
                        string $sessionPublicId,
                    ): SessionDefinition {
                        throw new RuntimeException('Must never be reached: no ready entitlement exists.');
                    }
                },
                new class implements AssessmentItemContentAuthority
                {
                    public function contentFor(
                        GenericAssessmentInstrument $instrument,
                        SessionDefinition $definition,
                        ?string $currentSegmentCode = null,
                    ): AssessmentItemContent {
                        throw new RuntimeException('Must never be reached: no ready entitlement exists.');
                    }
                },
                new AssessmentAttemptAllocationPolicy,
                new AssessmentSessionStateMachine,
                new AssessmentSessionDeadlinePolicy,
            ),
        );
    }

    /** @return array{branch:int,package:int,participant:int,case:int,order:int,paymentMethod:int} */
    private function participantGraphWithoutIstEntitlement(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'S5 Synthetic',
                'organization_code' => $key, 'display_name' => 'S5 Synthetic',
            ]);
            $package = DB::table('packages')->insertGetId([
                'code' => 'PKG-'.$key, 'name' => 'S5 Synthetic', 'amount' => 99000,
                'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['dass21', 'ist'] as $sort => $type) {
                DB::table('package_items')->insert([
                    'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
                'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
                'full_name' => 'S5 Synthetic', 'phone' => '620000000000',
            ]);
            $publicId = (string) Str::ulid();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant,
                'organization_id' => $branch, 'package_id' => $package,
                'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $paymentMethod = DB::table('payment_methods')->insertGetId([
                'code' => 'METHOD-'.$key, 'display_name' => $key, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $order = DB::table('orders')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant,
                'assessment_case_id' => $case, 'payment_method_id' => $paymentMethod,
                'status' => 'paid', 'amount' => 99000, 'currency' => 'IDR', 'paid_at' => now()->subMinute(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Deliberately no 'ist' entitlement -- only dass21, which the
            // candidate projection requires the package to carry but which is
            // structurally never usable by the generic start command.
            DB::table('entitlements')->insert([
                'participant_id' => $participant, 'order_id' => $order, 'test_type' => 'dass21',
                'assessment_case_id' => null, 'status' => 'ready', 'ready_at' => now()->subMinute(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return compact('branch', 'package', 'participant', 'case', 'order', 'paymentMethod');
        });
    }

    /** @param array{branch:int,package:int,participant:int,case:int,order:int,paymentMethod:int} $fixture */
    private function cleanupFixture(array $fixture): void
    {
        $this->assertDisposableDatabase();
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.s5_security_cleanup', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('s5_security_cleanup');

        try {
            $owner->transaction(function () use ($owner, $fixture): void {
                $owner->statement("SET LOCAL session_replication_role = 'replica'");
                $owner->table('entitlements')->where('participant_id', $fixture['participant'])->delete();
                $owner->table('orders')->where('participant_id', $fixture['participant'])->delete();
                $owner->table('assessment_cases')->where('participant_id', $fixture['participant'])->delete();
                $owner->table('participants')->where('id', $fixture['participant'])->delete();
                $owner->table('package_items')->where('package_id', $fixture['package'])->delete();
                $owner->table('packages')->where('id', $fixture['package'])->delete();
                $owner->table('payment_methods')->where('id', $fixture['paymentMethod'])->delete();
                $owner->table('branches')->where('id', $fixture['branch'])->delete();
            });
        } finally {
            DB::purge('s5_security_cleanup');
            config()->set('database.connections.s5_security_cleanup', null);
        }
    }

    private function assertDisposableDatabase(): void
    {
        $runId = getenv('ORG_TEST_RUN_ID');
        $database = DB::selectOne(<<<'SQL'
            SELECT shobj_description(oid, 'pg_database') AS marker
            FROM pg_database
            WHERE datname = current_database()
            SQL);
        if (! is_string($runId) || $runId === '' || $database?->marker !== "ONCAM_ORG_TEST:{$runId}") {
            throw new RuntimeException('S5 controller security test requires the marked disposable database.');
        }
    }
}
