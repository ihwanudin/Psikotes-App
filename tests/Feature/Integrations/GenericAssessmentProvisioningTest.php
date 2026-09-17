<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\AssessmentCase;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class GenericAssessmentProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'generic-client-secret-with-at-least-32-bytes';

    private Branch $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-29 10:15:00+07:00');
        config()->set('assessment_integration.credentials.lpk-sakura-secret', self::SECRET);
        config()->set('assessment_integration.signature_tolerance_seconds', 300);

        $this->organization = Branch::query()->create([
            'code' => 'SAKURA',
            'name' => 'Legacy Sakura Name',
            'ref_code' => 'LPK-SAKURA',
            'organization_code' => 'LPK_SAKURA',
            'organization_type' => 'EXTERNAL_LPK',
            'display_name' => 'LPK Sakura',
            'status' => 'ACTIVE',
            'allowed_funding_modes' => ['SPONSORED', 'INVOICED_TO_ORGANIZATION'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $package = TestPackage::query()->create([
            'code' => 'SELEKSI_KERJA_JEPANG_V1',
            'name' => 'Seleksi Kerja Jepang',
            'amount' => 100_000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);
        $package->items()->create(['test_type' => 'dass21', 'sort_order' => 2]);

        $client = IntegrationClient::query()->create([
            'organization_id' => $this->organization->id,
            'client_id' => 'LPK_SAKURA_ADMISSION_APP',
            'credential_reference' => 'lpk-sakura-secret',
            'result_delivery_mode' => 'CALLBACK_AND_POLL',
            'enabled' => true,
        ]);
        IntegrationSource::query()->create([
            'integration_client_id' => $client->id,
            'source_system' => 'LPK_SAKURA_SELECTION',
            'authentication_mode' => 'HMAC_SHA256',
            'allowed_assessment_packages' => ['SELEKSI_KERJA_JEPANG_V1'],
            'participant_provisioning_mode' => 'API',
            'commercial_mode' => 'CONTRACT',
            'allowed_funding_modes' => ['SPONSORED'],
            'status' => 'ACTIVE',
        ]);
    }

    public function test_integrated_lpk_can_provision_without_creating_a_commercial_order(): void
    {
        $response = $this->signedRequest($this->payload(), 'assessment:v1:sakura-1')->assertCreated();

        $participantId = (int) $response->json('data.participantId');
        $response->assertJsonPath('data.assessmentStatus', 'READY');
        $this->assertDatabaseHas('participants', [
            'id' => $participantId,
            'branch_id' => $this->organization->id,
            'package_id' => TestPackage::query()->where('code', 'SELEKSI_KERJA_JEPANG_V1')->value('id'),
            'source_system' => 'LPK_SAKURA_SELECTION',
            'attribution_source' => 'LPK-SAKURA',
        ]);
        $this->assertDatabaseHas('assessment_participants', [
            'source_system' => 'LPK_SAKURA_SELECTION',
            'external_candidate_id' => 'SKR-2026-0001',
            'external_process_id' => 'SEL-SKR-2026-0001',
            'funding_mode' => 'SPONSORED',
            'assessment_status' => 'READY',
        ]);
        $attempt = AssessmentParticipant::query()->sole();
        $case = AssessmentCase::query()->sole();
        $this->assertSame($case->id, $attempt->assessment_case_id);
        $this->assertSame($case->public_id, $attempt->assessment_attempt_id);
        $this->assertSame($participantId, $case->participant_id);
        $this->assertSame($this->organization->id, $case->organization_id);
        $this->assertSame($attempt->package_id, $case->package_id);
        $this->assertSame('INTEGRATED', $case->origin);
        $this->assertNull($case->intended_field_snapshot);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'psychotest.assessment-event',
            'status' => 'pending',
        ]);
        $event = json_decode((string) $this->getConnection()->table('outbox_messages')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PSYCHOTEST_PARTICIPANT_PROVISIONED', $event['eventType']);
        $this->assertDatabaseHas('entitlements', ['participant_id' => $participantId, 'test_type' => 'ist']);
        $this->assertDatabaseHas('entitlements', ['participant_id' => $participantId, 'test_type' => 'dass21']);

        $audit = $this->getConnection()->table('audit_logs')->sole();
        $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['source_system', 'assessment_attempt_id'], array_keys($context));
        $encodedAudit = json_encode($audit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([
            'Ayu Pratiwi',
            'ayu@example.test',
            '6281234567890',
            'SKR-2026-0001',
            'SEL-SKR-2026-0001',
            'REG-SKR-0001',
            'ROUND-2026-08',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $encodedAudit);
        }

        $genericEntitlement = $this->getConnection()->table('entitlements')
            ->where('participant_id', $participantId)
            ->where('test_type', 'ist')
            ->sole();
        $dassEntitlement = $this->getConnection()->table('entitlements')
            ->where('participant_id', $participantId)
            ->where('test_type', 'dass21')
            ->sole();
        $this->assertSame($case->id, $genericEntitlement->assessment_case_id);
        $this->assertNull($dassEntitlement->assessment_case_id);
    }

    public function test_provisioning_audit_retention_uses_a_no_overflow_leap_day_anchor(): void
    {
        $originalTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Asia/Bangkok');
            Date::setTestNow('2024-02-29 10:15:00+07:00');

            $this->signedRequest($this->payload(), 'assessment:v1:leap-day-retention')->assertCreated();

            $audit = $this->getConnection()->table('audit_logs')
                ->where('action', 'assessment_participant.provisioned')
                ->sole();
            $this->assertSame('2024-02-29 10:15:00', $audit->occurred_at);
            $this->assertSame('2029-02-28 10:15:00', $audit->expires_at);
        } finally {
            Date::setTestNow();
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_unknown_fields_source_spoofing_package_and_funding_are_rejected(): void
    {
        $cases = [
            [[...$this->payload(), 'branchId' => 999], 'VALIDATION_FAILED'],
            [[...$this->payload(), 'sourceSystem' => 'UNREGISTERED_SOURCE'], 'SOURCE_NOT_ALLOWED'],
            [[...$this->payload(), 'organizationCode' => 'OTHER_ORG'], 'ORGANIZATION_MISMATCH'],
            [[...$this->payload(), 'assessmentPackageCode' => 'OTHER_PACKAGE'], 'PACKAGE_NOT_ALLOWED'],
            [[...$this->payload(), 'fundingMode' => 'COMMERCIAL_SELF_PAY'], 'FUNDING_MODE_NOT_ALLOWED'],
        ];

        foreach ($cases as $index => [$payload, $errorCode]) {
            $this->signedRequest($payload, 'assessment:v1:invalid-'.$index)
                ->assertStatus($errorCode === 'VALIDATION_FAILED' ? 422 : 403)
                ->assertJsonPath('error.code', $errorCode);
        }

        $this->assertDatabaseCount('participants', 0);
    }

    public function test_replay_is_idempotent_and_changed_payload_conflicts(): void
    {
        $key = 'assessment:v1:replay';
        $first = $this->signedRequest($this->payload(), $key)->assertCreated();
        $second = $this->signedRequest($this->payload(), $key)->assertOk();

        $this->assertSame($first->json('data.assessmentAttemptId'), $second->json('data.assessmentAttemptId'));
        $this->signedRequest([...$this->payload(), 'profile' => [...$this->payload()['profile'], 'fullName' => 'Changed']], $key)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('assessment_participants', 1);
        $this->assertDatabaseCount('assessment_cases', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_database_rejects_removing_the_case_binding_before_replay(): void
    {
        $key = 'assessment:v1:missing-case';
        $this->signedRequest($this->payload(), $key)->assertCreated();
        try {
            AssessmentParticipant::query()->sole()->update(['assessment_case_id' => null]);
            $this->fail('The immutable assessment case binding was removed.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->signedRequest($this->payload(), $key)
            ->assertOk();

        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('assessment_participants', 1);
        $this->assertDatabaseCount('assessment_cases', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_same_logical_assessment_with_a_new_key_replays_without_a_second_attempt(): void
    {
        $first = $this->signedRequest($this->payload(), 'assessment:v1:first')->assertCreated();
        $second = $this->signedRequest($this->payload(), 'assessment:v1:transport-retry')->assertOk();

        $this->assertSame($first->json('data.assessmentAttemptId'), $second->json('data.assessmentAttemptId'));
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('assessment_participants', 1);
        $this->assertDatabaseCount('assessment_cases', 1);
        $this->assertDatabaseCount('entitlements', 2);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_replay_fails_closed_when_key_and_logical_identity_match_different_attempts(): void
    {
        $firstKey = 'assessment:v1:first-attempt';
        $second = [...$this->payload(), 'externalCandidateId' => 'SKR-2026-0002'];
        $this->signedRequest($this->payload(), $firstKey)->assertCreated();
        $this->signedRequest($second, 'assessment:v1:second-attempt')->assertCreated();

        $this->signedRequest($second, $firstKey)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseCount('participants', 2);
        $this->assertDatabaseCount('assessment_participants', 2);
        $this->assertDatabaseCount('assessment_cases', 2);
        $this->assertDatabaseCount('outbox_messages', 2);
    }

    public function test_new_case_gets_its_own_generic_entitlement_but_duplicate_case_grant_is_rejected(): void
    {
        $this->signedRequest($this->payload(), 'assessment:v1:first-case')->assertCreated();
        $secondCase = [...$this->payload(), 'assessmentRoundId' => 'ROUND-2026-09'];

        $this->signedRequest($secondCase, 'assessment:v1:second-case')->assertCreated();

        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('assessment_participants', 2);
        $this->assertDatabaseCount('assessment_cases', 2);
        // DASS remains participant-scoped; generic instruments are case-scoped.
        $this->assertDatabaseCount('entitlements', 3);
        $cases = AssessmentCase::query()->orderBy('id')->get();
        $this->assertCount(2, $cases);
        foreach ($cases as $case) {
            $this->assertDatabaseHas('entitlements', [
                'participant_id' => $case->participant_id,
                'assessment_case_id' => $case->id,
                'test_type' => 'ist',
            ]);
        }

        $existing = Entitlement::query()->where('assessment_case_id', $cases->first()->id)
            ->where('test_type', 'ist')->sole();
        $this->expectException(QueryException::class);
        Entitlement::query()->create([
            'participant_id' => $existing->participant_id,
            'assessment_case_id' => $existing->assessment_case_id,
            'order_id' => null,
            'test_type' => 'ist',
            'status' => 'ready',
            'ready_at' => now(),
        ]);
    }

    public function test_two_clients_may_use_the_same_external_candidate_without_collision(): void
    {
        $otherOrganization = Branch::query()->create([
            'code' => 'MOMIJI', 'name' => 'LPK Momiji', 'ref_code' => 'LPK-MOMIJI',
            'organization_code' => 'LPK_MOMIJI', 'organization_type' => 'EXTERNAL_LPK',
            'display_name' => 'LPK Momiji', 'status' => 'ACTIVE',
            'allowed_funding_modes' => ['SPONSORED'], 'is_default' => false, 'is_active' => true,
        ]);
        $otherClient = IntegrationClient::query()->create([
            'organization_id' => $otherOrganization->id,
            'client_id' => 'LPK_MOMIJI_APP',
            'credential_reference' => 'momiji-secret',
            'result_delivery_mode' => 'POLL',
            'enabled' => true,
        ]);
        IntegrationSource::query()->create([
            'integration_client_id' => $otherClient->id,
            'source_system' => 'MOMIJI_SELECTION',
            'authentication_mode' => 'HMAC_SHA256',
            'allowed_assessment_packages' => ['SELEKSI_KERJA_JEPANG_V1'],
            'participant_provisioning_mode' => 'API',
            'commercial_mode' => 'CONTRACT',
            'allowed_funding_modes' => ['SPONSORED'],
            'status' => 'ACTIVE',
        ]);
        config()->set('assessment_integration.credentials.momiji-secret', self::SECRET);

        $this->signedRequest($this->payload(), 'assessment:v1:sakura')->assertCreated();
        $momiji = [...$this->payload(), 'sourceSystem' => 'MOMIJI_SELECTION', 'organizationCode' => 'LPK_MOMIJI'];
        $this->signedRequest($momiji, 'assessment:v1:momiji', 'LPK_MOMIJI_APP')->assertCreated();

        $this->assertDatabaseCount('participants', 2);
        $this->assertDatabaseCount('assessment_participants', 2);
    }

    public function test_package_without_mandatory_dass_cannot_provision(): void
    {
        $package = TestPackage::query()->where('code', 'SELEKSI_KERJA_JEPANG_V1')->firstOrFail();
        $package->items()->where('test_type', 'dass21')->delete();

        $this->signedRequest($this->payload(), 'assessment:v1:invalid-composition')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PACKAGE_NOT_ALLOWED');

        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('assessment_participants', 0);
        $this->assertDatabaseCount('entitlements', 0);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'sourceSystem' => 'LPK_SAKURA_SELECTION',
            'externalCandidateId' => 'SKR-2026-0001',
            'externalProcessId' => 'SEL-SKR-2026-0001',
            'externalRegistrationId' => 'REG-SKR-0001',
            'assessmentRoundId' => 'ROUND-2026-08',
            'organizationCode' => 'LPK_SAKURA',
            'assessmentPackageCode' => 'SELEKSI_KERJA_JEPANG_V1',
            'fundingMode' => 'SPONSORED',
            'profile' => [
                'fullName' => 'Ayu Pratiwi',
                'birthDate' => '2001-04-15',
                'gender' => 'MALE',
                'educationLevel' => 'SMA',
                'email' => 'ayu@example.test',
                'phone' => '6281234567890',
            ],
            'metadata' => ['cohortCode' => '2026-08'],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return TestResponse<Response>
     */
    private function signedRequest(array $payload, string $key, string $clientId = 'LPK_SAKURA_ADMISSION_APP'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) Date::now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), self::SECRET);

        return $this->call('POST', '/api/integrations/v1/assessments/participants', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => $clientId,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], $body);
    }
}
