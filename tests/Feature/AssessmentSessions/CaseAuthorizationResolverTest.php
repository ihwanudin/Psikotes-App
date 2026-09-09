<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationGrantKind;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
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
        DB::table('entitlements')->insert([
            'participant_id' => $fixture['participant'], 'order_id' => null,
            'test_type' => 'papi', 'status' => 'ready', 'ready_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

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

    public function test_public_contract_cannot_accept_dass_or_opaque_authorization_identifiers(): void
    {
        $reflection = new \ReflectionClass(CaseAuthorizationResolver::class);
        foreach (['resolveIntegratedForUpdate', 'resolveParticipantForUpdate'] as $methodName) {
            $parameters = $reflection->getMethod($methodName)->getParameters();
            $this->assertCount(2, $parameters);
            $this->assertSame('instrument', $parameters[1]->getName());
            $this->assertSame(GenericAssessmentInstrument::class, (string) $parameters[1]->getType());
        }

        $source = file_get_contents(app_path('Services/AssessmentSessions/CaseAuthorizationResolver.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('authorization_id', $source);
        $this->assertStringNotContainsString('allocation_intent_id', $source);
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
