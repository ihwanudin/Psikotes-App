<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentSessionDefinitionUnavailable;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationGrantKind;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionDefinitionCatalog;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\DatabaseAssessmentSessionDefinitionAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\OrganizationPaymentTestCase;

final class DatabaseAssessmentSessionDefinitionAuthorityTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    public function test_container_binds_the_frozen_authority_to_the_database_provider(): void
    {
        $this->assertInstanceOf(
            DatabaseAssessmentSessionDefinitionAuthority::class,
            app(AssessmentSessionDefinitionAuthority::class),
        );
    }

    public function test_empty_catalog_is_typed_fail_closed(): void
    {
        $this->expectException(AssessmentSessionDefinitionUnavailable::class);

        app(RlsContextRunner::class)->runAsService(fn () => $this->provider()->issueForNewSession(
            GenericAssessmentInstrument::Ist,
            $this->authorization(GenericAssessmentInstrument::Ist),
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ));
    }

    public function test_active_fixed_template_issues_an_exact_session_definition(): void
    {
        $template = $this->fixedTemplate();
        $this->insertTemplate($template);

        $definition = app(RlsContextRunner::class)->runAsService(fn (): SessionDefinition => $this->provider()
            ->issueForNewSession(
                GenericAssessmentInstrument::Ist,
                $this->authorization(GenericAssessmentInstrument::Ist),
                '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            ));

        $this->assertSame('synthetic-v1', $definition->version);
        $this->assertSame('synthetic-catalog-test', $definition->provenance);
        $this->assertNull($definition->seed);
        $this->assertNull($definition->generator);
        $this->assertSame(SessionDefinition::checksumFor($template), $definition->checksum);
    }

    public function test_seeded_template_issues_a_fresh_server_seed_without_storing_it_in_the_catalog(): void
    {
        $template = $this->kraepelinTemplate();
        $this->insertTemplate($template);
        $provider = new DatabaseAssessmentSessionDefinitionAuthority(
            app(RlsContextRunner::class),
            static fn (): string => 'synthetic-issued-seed',
        );

        $definition = app(RlsContextRunner::class)->runAsService(fn (): SessionDefinition => $provider
            ->issueForNewSession(
                GenericAssessmentInstrument::Kraepelin,
                $this->authorization(GenericAssessmentInstrument::Kraepelin),
                '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            ));

        $this->assertSame('synthetic-issued-seed', $definition->seed);
        $this->assertSame('synthetic-generator', $definition->generator['algorithm'] ?? null);
        $stored = json_decode((string) DB::table('assessment_session_definitions')->value('template_payload'), true);
        $this->assertNull($stored['seed'] ?? null);
        $this->assertNotSame(
            DB::table('assessment_session_definitions')->value('template_checksum'),
            $definition->checksum,
        );
    }

    public function test_corrupt_active_template_fails_closed(): void
    {
        $template = $this->fixedTemplate();
        $this->insertTemplate($template, str_repeat('a', 64));

        $this->expectException(InvalidAssessmentSessionDefinitionCatalog::class);

        app(RlsContextRunner::class)->runAsService(fn () => $this->provider()->issueForNewSession(
            GenericAssessmentInstrument::Ist,
            $this->authorization(GenericAssessmentInstrument::Ist),
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ));
    }

    public function test_provider_requires_the_existing_service_transaction_boundary(): void
    {
        $this->expectException(LogicException::class);

        $this->provider()->issueForNewSession(
            GenericAssessmentInstrument::Ist,
            $this->authorization(GenericAssessmentInstrument::Ist),
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );
    }

    private function provider(): DatabaseAssessmentSessionDefinitionAuthority
    {
        return app(DatabaseAssessmentSessionDefinitionAuthority::class);
    }

    /** @param array<string, mixed> $template */
    private function insertTemplate(array $template, ?string $checksum = null): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('assessment_session_definitions')->insert([
            'instrument' => $template['instrument'],
            'version' => $template['version'],
            'provenance' => $template['provenance'],
            'template_checksum' => $checksum ?? SessionDefinition::checksumFor($template),
            'template_payload' => json_encode($template, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'activated_at' => now(),
            'deactivated_at' => null,
        ]));
    }

    /** @return array<string, mixed> */
    private function fixedTemplate(): array
    {
        return [
            'instrument' => 'ist',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-catalog-test',
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
    }

    /** @return array<string, mixed> */
    private function kraepelinTemplate(): array
    {
        return [
            'instrument' => 'kraepelin',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-catalog-test',
            'total_duration_seconds' => 750,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 750,
                'item_count' => 1350,
            ]],
            'randomization' => 'seeded',
            'seed' => null,
            'generator' => [
                'algorithm' => 'synthetic-generator',
                'version' => 'synthetic-v1',
                'columns' => 50,
                'seconds_per_column' => 15,
                'numbers_per_column' => 28,
                'answer_slots_per_column' => 27,
            ],
        ];
    }

    private function authorization(GenericAssessmentInstrument $instrument): CaseAuthorization
    {
        return new CaseAuthorization(
            caseId: 1,
            casePublicId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            participantId: 2,
            organizationId: 3,
            packageId: 4,
            origin: CaseAuthorizationOrigin::Integrated,
            instrument: $instrument,
            grantKind: CaseAuthorizationGrantKind::AssessmentEntitlement,
            grantId: 5,
        );
    }
}
