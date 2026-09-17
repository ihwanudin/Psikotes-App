<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationGrantKind;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use PHPUnit\Framework\TestCase;

final class AssessmentSessionDefinitionAuthorityTest extends TestCase
{
    public function test_contract_issues_a_definition_from_typed_server_authority_inputs(): void
    {
        $definition = self::definition();
        $authorization = new CaseAuthorization(
            caseId: 101,
            casePublicId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            participantId: 201,
            organizationId: 301,
            packageId: 401,
            origin: CaseAuthorizationOrigin::Integrated,
            instrument: GenericAssessmentInstrument::Ist,
            grantKind: CaseAuthorizationGrantKind::AssessmentEntitlement,
            grantId: 501,
        );
        $authority = new class($definition) implements AssessmentSessionDefinitionAuthority
        {
            /** @var array{GenericAssessmentInstrument, CaseAuthorization, string}|null */
            public ?array $received = null;

            public function __construct(private readonly SessionDefinition $definition) {}

            public function issueForNewSession(
                GenericAssessmentInstrument $instrument,
                CaseAuthorization $authorization,
                string $sessionPublicId,
            ): SessionDefinition {
                $this->received = [$instrument, $authorization, $sessionPublicId];

                return $this->definition;
            }
        };

        $issued = $authority->issueForNewSession(
            GenericAssessmentInstrument::Ist,
            $authorization,
            '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        );

        $this->assertSame($definition, $issued);
        $this->assertSame([
            GenericAssessmentInstrument::Ist,
            $authorization,
            '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        ], $authority->received);
    }

    private static function definition(): SessionDefinition
    {
        $input = [
            'instrument' => 'ist',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture',
            'checksum' => '',
            'total_duration_seconds' => 60,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 60,
                'item_count' => 1,
            ]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
        $input['checksum'] = SessionDefinition::checksumFor($input);

        return SessionDefinition::fromArray($input);
    }
}
