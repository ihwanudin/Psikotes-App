<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionHistoryKey;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionCandidate;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationGrantKind;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\AssessmentSessions\ParticipantAssessmentSessionCandidates;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class CaseAuthorizationResolverTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_exact_integrated_grant_without_using_opaque_session_ids(): void
    {
        $fixture = AssessmentAccessFixture::create();

        $authorization = $this->asService(fn (): CaseAuthorization => $this->resolver()->resolveIntegratedForUpdate(
            new AssessmentPrincipal($fixture['participant'], $fixture['organization'], $fixture['attempt']),
            GenericAssessmentInstrument::Ist,
        ));

        $case = DB::table('assessment_cases')->where('id', $authorization->caseId)->sole();
        $this->assertSame(CaseAuthorizationOrigin::Integrated, $authorization->origin);
        $this->assertSame(CaseAuthorizationGrantKind::AssessmentEntitlement, $authorization->grantKind);
        $this->assertSame($fixture['entitlement'], $authorization->grantId);
        $this->assertSame($case->public_id, $authorization->casePublicId);
        $this->assertSame($fixture['participant'], $authorization->participantId);
        $this->assertSame($fixture['organization'], $authorization->organizationId);
        $this->assertSame($fixture['package'], $authorization->packageId);
        $this->assertSame(GenericAssessmentInstrument::Ist, $authorization->instrument);
    }

    public function test_it_resolves_the_exact_direct_public_order_grant(): void
    {
        $fixture = $this->participantGraph('DIRECT_PUBLIC', true);

        $authorization = $this->asService(fn (): CaseAuthorization => $this->resolver()->resolveParticipantForUpdate(
            new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
            GenericAssessmentInstrument::Ist,
        ));

        $this->assertSame($fixture['case'], $authorization->caseId);
        $this->assertSame(CaseAuthorizationOrigin::DirectPublic, $authorization->origin);
        $this->assertSame(CaseAuthorizationGrantKind::Entitlement, $authorization->grantKind);
        $this->assertSame($fixture['entitlement'], $authorization->grantId);
        $this->assertSame($fixture['package'], $authorization->packageId);
    }

    public function test_it_rechecks_the_exact_case_from_a_trusted_selected_candidate(): void
    {
        $fixture = $this->participantGraph('DIRECT_PUBLIC', true);
        $publicId = (string) DB::table('assessment_cases')->where('id', $fixture['case'])->value('public_id');
        $candidate = new AssessmentSessionSelectionCandidate(
            new AssessmentSessionHistoryKey($publicId, GenericAssessmentInstrument::Ist),
            $fixture['participant'],
            $fixture['branch'],
            CaseAuthorizationOrigin::DirectPublic,
            'entitlement:'.$fixture['entitlement'],
            true,
            null,
            null,
            null,
            false,
        );

        $authorization = $this->asService(fn (): CaseAuthorization => $this->resolver()
            ->resolveSelectedParticipantForUpdate(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                $candidate,
            ));

        $this->assertSame($fixture['case'], $authorization->caseId);
        $this->assertSame($fixture['entitlement'], $authorization->grantId);
    }

    public function test_selected_candidate_recheck_preserves_the_exact_case_when_another_case_is_not_ready(): void
    {
        DB::statement('DROP INDEX IF EXISTS entitlements_participant_id_test_type_unique');
        $fixture = $this->participantGraph('DIRECT_PUBLIC', true);
        $selected = $this->projectedCandidate($fixture);
        $this->insertDirectCase($fixture, 'pending');

        $authorization = $this->asService(fn (): CaseAuthorization => $this->resolver()
            ->resolveSelectedParticipantForUpdate(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                $selected,
            ));

        $this->assertSame($fixture['case'], $authorization->caseId);
        $this->assertSame($fixture['entitlement'], $authorization->grantId);
    }

    public function test_selected_legacy_candidate_rechecks_its_exact_selection_source(): void
    {
        $fixture = $this->participantGraph('SELEKSI_BEASISWA_JEPANG', false);
        $candidate = $this->projectedCandidate($fixture);

        $authorization = $this->asService(fn (): CaseAuthorization => $this->resolver()
            ->resolveSelectedParticipantForUpdate(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                $candidate,
            ));

        $this->assertSame($fixture['case'], $authorization->caseId);
        $this->assertSame(CaseAuthorizationOrigin::LegacySelection, $authorization->origin);
    }

    public function test_selected_candidate_rejects_source_drift_without_writing(): void
    {
        $fixture = $this->participantGraph('DIRECT_PUBLIC', true);
        $candidate = $this->projectedCandidate($fixture);
        DB::table('entitlements')->where('id', $fixture['entitlement'])->update([
            'status' => 'locked',
            'ready_at' => null,
            'updated_at' => now(),
        ]);

        try {
            $this->asService(fn (): CaseAuthorization => $this->resolver()
                ->resolveSelectedParticipantForUpdate(
                    new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                    $candidate,
                ));
            $this->fail('A stale selected candidate was accepted.');
        } catch (CaseAuthorizationRejected) {
            $this->assertDatabaseCount('test_sessions', 0);
            $this->assertDatabaseCount('test_session_grants', 0);
        }
    }

    public function test_live_candidate_rechecks_the_durable_session_and_grant_identity(): void
    {
        $fixture = $this->participantGraph('DIRECT_PUBLIC', true);
        $sessionPublicId = $this->insertLiveSessionGrant($fixture);
        $candidate = $this->projectedCandidate($fixture);

        $authorization = $this->asService(fn (): CaseAuthorization => $this->resolver()
            ->resolveSelectedParticipantForUpdate(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                $candidate,
            ));

        $this->assertSame($fixture['case'], $authorization->caseId);
        $this->assertSame($sessionPublicId, $candidate->sessionPublicId);
    }

    public function test_live_candidate_rejects_replay_identity_drift_without_writing(): void
    {
        $fixture = $this->participantGraph('DIRECT_PUBLIC', true);
        $this->insertLiveSessionGrant($fixture);
        $candidate = $this->projectedCandidate($fixture);
        $candidate = new AssessmentSessionSelectionCandidate(
            $candidate->historyKey,
            $candidate->participantId,
            $candidate->organizationId,
            $candidate->origin,
            $candidate->durableSourceGrantId,
            $candidate->eligibleForAllocation,
            (string) Str::ulid(),
            $candidate->sessionStatus,
            $candidate->assessmentParticipantId,
            $candidate->retestCandidate,
        );

        try {
            $this->asService(fn (): CaseAuthorization => $this->resolver()
                ->resolveSelectedParticipantForUpdate(
                    new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                    $candidate,
                ));
            $this->fail('A replay with drifted durable identity was accepted.');
        } catch (CaseAuthorizationRejected) {
            $this->assertDatabaseCount('test_sessions', 1);
            $this->assertDatabaseCount('test_session_grants', 1);
        }
    }

    public function test_it_resolves_the_exact_legacy_selection_grant(): void
    {
        $fixture = $this->participantGraph('SELEKSI_BEASISWA_JEPANG', false);

        $authorization = $this->asService(fn (): CaseAuthorization => $this->resolver()->resolveParticipantForUpdate(
            new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
            GenericAssessmentInstrument::Ist,
        ));

        $this->assertSame($fixture['case'], $authorization->caseId);
        $this->assertSame(CaseAuthorizationOrigin::LegacySelection, $authorization->origin);
        $this->assertSame(CaseAuthorizationGrantKind::Entitlement, $authorization->grantKind);
        $this->assertSame($fixture['entitlement'], $authorization->grantId);
        $this->assertNull($authorization->packageId);
    }

    public function test_it_rejects_missing_mismatched_or_ambiguous_participant_graphs(): void
    {
        $missing = $this->participantGraph('DIRECT_PUBLIC', true);
        DB::table('entitlements')->where('id', $missing['entitlement'])->update([
            'status' => 'locked', 'ready_at' => null,
        ]);
        $this->assertRejected($missing);

        $mismatch = $this->participantGraph('DIRECT_PUBLIC', true);
        DB::table('orders')->where('assessment_case_id', $mismatch['case'])->update([
            'status' => 'pending', 'paid_at' => null,
        ]);
        $this->assertRejected($mismatch);

        $ambiguous = $this->participantGraph('SELEKSI_BEASISWA_JEPANG', false);
        $this->insertUnboundOrder($ambiguous['participant']);
        $this->assertRejected($ambiguous);

        $multiple = $this->participantGraph('DIRECT_PUBLIC', true);
        DB::table('assessment_cases')->insert([
            'public_id' => (string) Str::ulid(), 'participant_id' => $multiple['participant'],
            'organization_id' => $multiple['branch'], 'package_id' => $multiple['package'],
            'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => 'KAIGO',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRejected($multiple);
    }

    public function test_direct_graph_rejects_package_or_entitlement_composition_drift(): void
    {
        $fixture = $this->participantGraph('DIRECT_PUBLIC', true);
        DB::table('package_items')->where('package_id', $fixture['package'])
            ->where('test_type', 'ist')->delete();

        $this->assertRejected($fixture);
    }

    public function test_wrong_scope_and_integrated_graph_mismatch_fail_closed(): void
    {
        $fixture = AssessmentAccessFixture::create();

        $this->expectException(CaseAuthorizationRejected::class);
        $this->asService(fn () => $this->resolver()->resolveIntegratedForUpdate(
            new AssessmentPrincipal($fixture['participant'], $fixture['organization'] + 1, $fixture['attempt']),
            GenericAssessmentInstrument::Ist,
        ));
    }

    public function test_integrated_grant_rejects_a_competing_direct_public_graph(): void
    {
        $fixture = AssessmentAccessFixture::create();
        DB::table('participants')->where('id', $fixture['participant'])->update([
            'source_system' => 'DIRECT_PUBLIC', 'package_id' => $fixture['package'],
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $fixture['participant'],
            'organization_id' => $fixture['organization'], 'package_id' => $fixture['package'],
            'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = $this->insertUnboundOrder($fixture['participant'], $publicId, $case);
        foreach (['dass21', 'ist'] as $type) {
            DB::table('entitlements')->insert([
                'participant_id' => $fixture['participant'], 'order_id' => $order,
                'assessment_case_id' => $type === 'dass21' ? null : $case,
                'test_type' => $type, 'status' => 'ready', 'ready_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->expectException(CaseAuthorizationRejected::class);
        $this->asService(fn (): CaseAuthorization => $this->resolver()->resolveIntegratedForUpdate(
            new AssessmentPrincipal($fixture['participant'], $fixture['organization'], $fixture['attempt']),
            GenericAssessmentInstrument::Ist,
        ));
    }

    public function test_database_failures_are_not_relabelled_as_authorization_denials(): void
    {
        $fixture = AssessmentAccessFixture::create();
        Schema::drop('orders');

        $this->expectException(QueryException::class);
        $this->asService(fn (): CaseAuthorization => $this->resolver()->resolveIntegratedForUpdate(
            new AssessmentPrincipal($fixture['participant'], $fixture['organization'], $fixture['attempt']),
            GenericAssessmentInstrument::Ist,
        ));
    }

    public function test_resolver_requires_an_existing_service_transaction(): void
    {
        $fixture = AssessmentAccessFixture::create();

        $this->expectException(LogicException::class);
        $this->resolver()->resolveIntegratedForUpdate(
            new AssessmentPrincipal($fixture['participant'], $fixture['organization'], $fixture['attempt']),
            GenericAssessmentInstrument::Ist,
        );
    }

    public function test_resolver_rejects_a_non_service_transaction(): void
    {
        $fixture = AssessmentAccessFixture::create();

        $this->expectException(LogicException::class);
        DB::transaction(fn () => $this->resolver()->resolveIntegratedForUpdate(
            new AssessmentPrincipal($fixture['participant'], $fixture['organization'], $fixture['attempt']),
            GenericAssessmentInstrument::Ist,
        ));
    }

    public function test_dass_is_rejected_by_the_typed_boundary_before_any_query(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            GenericAssessmentInstrument::fromExternal('dass21');
            $this->fail('DASS must not enter the generic case resolver.');
        } catch (UnsupportedGenericAssessmentInstrument) {
            $this->assertSame([], $queries);
        }
    }

    public function test_public_contract_keeps_old_entrypoints_and_accepts_only_a_typed_selected_candidate(): void
    {
        $reflection = new \ReflectionClass(CaseAuthorizationResolver::class);
        foreach (['resolveIntegratedForUpdate', 'resolveParticipantForUpdate'] as $methodName) {
            $parameters = $reflection->getMethod($methodName)->getParameters();
            $this->assertCount(2, $parameters);
            $this->assertSame('instrument', $parameters[1]->getName());
            $this->assertSame(GenericAssessmentInstrument::class, (string) $parameters[1]->getType());
        }

        $selected = $reflection->getMethod('resolveSelectedParticipantForUpdate')->getParameters();
        $this->assertCount(2, $selected);
        $this->assertSame(ParticipantPrincipal::class, (string) $selected[0]->getType());
        $this->assertSame(AssessmentSessionSelectionCandidate::class, (string) $selected[1]->getType());

        $source = file_get_contents(app_path('Services/AssessmentSessions/CaseAuthorizationResolver.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('dass21', $source);
    }

    /** @return array{branch:int,participant:int,package:int,case:int,entitlement:int} */
    private function participantGraph(string $source, bool $direct): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $key, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => 'Synthetic', 'amount' => 99000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['dass21', 'ist'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'package_id' => $direct ? $package : null,
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
            'source_system' => $source, 'full_name' => 'Synthetic', 'intended_field' => 'KAIGO',
            'phone' => '620000000000', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'organization_id' => $branch,
            'package_id' => $direct ? $package : null,
            'origin' => $direct ? 'DIRECT_PUBLIC' : 'LEGACY_SELECTION',
            'intended_field_snapshot' => 'KAIGO', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = null;
        if ($direct) {
            $order = $this->insertUnboundOrder($participant, $publicId, $case);
        } else {
            DB::table('selection_participants')->insert([
                'client_id' => 'client-'.$key, 'external_candidate_id' => 'candidate-'.$key,
                'selection_round_id' => 'round-'.$key, 'registration_id' => 'registration-'.$key,
                'participant_id' => $participant, 'assessment_case_id' => $case,
                'idempotency_key' => 'key-'.$key, 'request_hash' => hash('sha256', $key),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['dass21', 'ist'] as $type) {
            $id = DB::table('entitlements')->insertGetId([
                'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
                'assessment_case_id' => $type === 'dass21' ? null : $case,
                'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($type === 'ist') {
                $entitlement = $id;
            }
        }

        return compact('branch', 'participant', 'package', 'case', 'entitlement');
    }

    private function insertUnboundOrder(int $participant, ?string $publicId = null, ?int $case = null): int
    {
        return DB::table('orders')->insertGetId([
            'public_id' => $publicId ?? (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'payment_method_id' => null,
            'status' => 'paid', 'amount' => 0, 'currency' => 'IDR', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array{branch:int,participant:int,package:int,case:int,entitlement:int} $fixture */
    private function projectedCandidate(array $fixture): AssessmentSessionSelectionCandidate
    {
        $projection = app(RlsContextRunner::class)->runAsService(
            fn () => app(ParticipantAssessmentSessionCandidates::class)->project(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            ),
        );

        return $projection->candidates[0];
    }

    /** @param array{branch:int,participant:int,package:int} $fixture */
    private function insertDirectCase(array $fixture, string $orderStatus): int
    {
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId,
            'participant_id' => $fixture['participant'],
            'organization_id' => $fixture['branch'],
            'package_id' => $fixture['package'],
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => 'KAIGO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId,
            'participant_id' => $fixture['participant'],
            'assessment_case_id' => $case,
            'status' => $orderStatus,
            'amount' => 99000,
            'currency' => 'IDR',
            'paid_at' => $orderStatus === 'paid' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('entitlements')->insert([
            'participant_id' => $fixture['participant'],
            'order_id' => $order,
            'assessment_case_id' => $case,
            'test_type' => 'ist',
            'status' => 'ready',
            'ready_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $case;
    }

    /** @param array{branch:int,participant:int,package:int,case:int,entitlement:int} $fixture */
    private function insertLiveSessionGrant(array $fixture): string
    {
        $sessionPublicId = (string) Str::ulid();
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId,
            'participant_id' => $fixture['participant'],
            'assessment_case_id' => $fixture['case'],
            'test_type' => 'ist',
            'attempt_no' => 1,
            'authorization_id' => 'grant:v1:DIRECT_PUBLIC:entitlement:'.$fixture['entitlement'],
            'allocation_intent_id' => 'allocation:v1:DIRECT_PUBLIC:entitlement:'.$fixture['entitlement'].':ist',
            'duration_seconds' => 600,
            'status' => AssessmentSessionStatus::InProgress->value,
            'started_at' => now()->subMinute(),
            'ends_at' => now()->addMinutes(9),
            'answers_revision' => 0,
            'created_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);
        DB::table('test_session_grants')->insert([
            'test_session_id' => $session,
            'assessment_case_id' => $fixture['case'],
            'participant_id' => $fixture['participant'],
            'organization_id' => $fixture['branch'],
            'test_type' => 'ist',
            'origin' => 'DIRECT_PUBLIC',
            'grant_kind' => 'entitlement',
            'order_id' => DB::table('orders')->where('assessment_case_id', $fixture['case'])->value('id'),
            'entitlement_id' => $fixture['entitlement'],
            'created_at' => now(),
        ]);
        DB::table('entitlements')->where('id', $fixture['entitlement'])->update([
            'status' => 'in_progress',
            'started_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);

        return $sessionPublicId;
    }

    /** @param array{branch:int,participant:int} $fixture */
    private function assertRejected(array $fixture): void
    {
        try {
            $this->asService(fn () => $this->resolver()->resolveParticipantForUpdate(
                new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                GenericAssessmentInstrument::Ist,
            ));
            $this->fail('Malformed authorization graph was accepted.');
        } catch (CaseAuthorizationRejected $exception) {
            $this->assertSame('CASE_AUTHORIZATION_REJECTED', $exception->getMessage());
        }
    }

    private function resolver(): CaseAuthorizationResolver
    {
        return app(CaseAuthorizationResolver::class);
    }

    /** @param Closure(): CaseAuthorization $callback */
    private function asService(Closure $callback): CaseAuthorization
    {
        return app(RlsContextRunner::class)->runAsService($callback);
    }
}
