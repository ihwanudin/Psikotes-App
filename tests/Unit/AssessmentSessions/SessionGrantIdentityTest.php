<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationGrantKind;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionGrantIdentity;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SessionGrantIdentityTest extends TestCase
{
    public function test_integrated_factory_exports_only_the_integrated_source_identity(): void
    {
        $identity = SessionGrantIdentity::integrated(
            self::authorization(CaseAuthorizationOrigin::Integrated, CaseAuthorizationGrantKind::AssessmentEntitlement, 701),
            assessmentParticipantId: 501,
        );

        $this->assertSame([
            'assessment_case_id' => 101,
            'participant_id' => 201,
            'organization_id' => 301,
            'test_type' => 'ist',
            'origin' => 'INTEGRATED',
            'grant_kind' => 'assessment_entitlement',
            'assessment_participant_id' => 501,
            'order_id' => null,
            'selection_participant_id' => null,
            'assessment_entitlement_id' => 701,
            'entitlement_id' => null,
        ], $identity->toArray());
    }

    public function test_direct_public_factory_exports_only_the_order_entitlement_identity(): void
    {
        $identity = SessionGrantIdentity::directPublic(
            self::authorization(CaseAuthorizationOrigin::DirectPublic, CaseAuthorizationGrantKind::Entitlement, 702),
            orderId: 601,
        );

        $this->assertSame([
            'assessment_case_id' => 101,
            'participant_id' => 201,
            'organization_id' => 301,
            'test_type' => 'ist',
            'origin' => 'DIRECT_PUBLIC',
            'grant_kind' => 'entitlement',
            'assessment_participant_id' => null,
            'order_id' => 601,
            'selection_participant_id' => null,
            'assessment_entitlement_id' => null,
            'entitlement_id' => 702,
        ], $identity->toArray());
    }

    public function test_legacy_selection_factory_exports_only_the_selection_entitlement_identity(): void
    {
        $identity = SessionGrantIdentity::legacySelection(
            self::authorization(CaseAuthorizationOrigin::LegacySelection, CaseAuthorizationGrantKind::Entitlement, 703),
            selectionParticipantId: 801,
        );

        $this->assertSame([
            'assessment_case_id' => 101,
            'participant_id' => 201,
            'organization_id' => 301,
            'test_type' => 'ist',
            'origin' => 'LEGACY_SELECTION',
            'grant_kind' => 'entitlement',
            'assessment_participant_id' => null,
            'order_id' => null,
            'selection_participant_id' => 801,
            'assessment_entitlement_id' => null,
            'entitlement_id' => 703,
        ], $identity->toArray());
    }

    /** @param Closure(CaseAuthorization): SessionGrantIdentity $factory */
    #[DataProvider('crossOriginFactories')]
    public function test_factories_reject_cross_origin_or_grant_kind_authorizations(
        Closure $factory,
        CaseAuthorizationOrigin $origin,
        CaseAuthorizationGrantKind $grantKind,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $factory(self::authorization($origin, $grantKind, 704));
    }

    /** @return iterable<string, array{Closure(CaseAuthorization): SessionGrantIdentity, CaseAuthorizationOrigin, CaseAuthorizationGrantKind}> */
    public static function crossOriginFactories(): iterable
    {
        yield 'integrated rejects direct entitlement' => [
            static fn (CaseAuthorization $authorization): SessionGrantIdentity => SessionGrantIdentity::integrated($authorization, 501),
            CaseAuthorizationOrigin::DirectPublic,
            CaseAuthorizationGrantKind::Entitlement,
        ];
        yield 'direct rejects integrated entitlement' => [
            static fn (CaseAuthorization $authorization): SessionGrantIdentity => SessionGrantIdentity::directPublic($authorization, 601),
            CaseAuthorizationOrigin::Integrated,
            CaseAuthorizationGrantKind::AssessmentEntitlement,
        ];
        yield 'legacy rejects direct origin' => [
            static fn (CaseAuthorization $authorization): SessionGrantIdentity => SessionGrantIdentity::legacySelection($authorization, 801),
            CaseAuthorizationOrigin::DirectPublic,
            CaseAuthorizationGrantKind::Entitlement,
        ];
        yield 'direct rejects wrong grant kind' => [
            static fn (CaseAuthorization $authorization): SessionGrantIdentity => SessionGrantIdentity::directPublic($authorization, 601),
            CaseAuthorizationOrigin::DirectPublic,
            CaseAuthorizationGrantKind::AssessmentEntitlement,
        ];
    }

    /** @param Closure(): SessionGrantIdentity $factory */
    #[DataProvider('invalidSourceFactories')]
    public function test_factories_reject_non_positive_locked_source_ids(Closure $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /** @return iterable<string, array{Closure(): SessionGrantIdentity}> */
    public static function invalidSourceFactories(): iterable
    {
        yield 'integrated assessment participant' => [
            static fn (): SessionGrantIdentity => SessionGrantIdentity::integrated(
                self::authorization(CaseAuthorizationOrigin::Integrated, CaseAuthorizationGrantKind::AssessmentEntitlement, 701),
                0,
            ),
        ];
        yield 'direct order' => [
            static fn (): SessionGrantIdentity => SessionGrantIdentity::directPublic(
                self::authorization(CaseAuthorizationOrigin::DirectPublic, CaseAuthorizationGrantKind::Entitlement, 702),
                -1,
            ),
        ];
        yield 'legacy selection participant' => [
            static fn (): SessionGrantIdentity => SessionGrantIdentity::legacySelection(
                self::authorization(CaseAuthorizationOrigin::LegacySelection, CaseAuthorizationGrantKind::Entitlement, 703),
                0,
            ),
        ];
    }

    public function test_identity_is_readonly_and_export_mutation_cannot_change_it(): void
    {
        $identity = SessionGrantIdentity::directPublic(
            self::authorization(CaseAuthorizationOrigin::DirectPublic, CaseAuthorizationGrantKind::Entitlement, 702),
            601,
        );
        $expected = $identity->toArray();
        $exported = $identity->toArray();
        $exported['order_id'] = 999;
        $reflection = new ReflectionClass($identity);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->getConstructor()?->isPrivate());
        $this->assertSame($expected, $identity->toArray());
    }

    private static function authorization(
        CaseAuthorizationOrigin $origin,
        CaseAuthorizationGrantKind $grantKind,
        int $grantId,
    ): CaseAuthorization {
        return new CaseAuthorization(
            caseId: 101,
            casePublicId: '01JTESTSESSIONCASE00000000',
            participantId: 201,
            organizationId: 301,
            packageId: $origin === CaseAuthorizationOrigin::LegacySelection ? null : 401,
            origin: $origin,
            instrument: GenericAssessmentInstrument::Ist,
            grantKind: $grantKind,
            grantId: $grantId,
        );
    }
}
