<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\Branch;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class SelectionParticipantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const string CLIENT_ID = 'selection-app';

    private const string CLIENT_SECRET = 'test-selection-secret-with-at-least-32-bytes';

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-29 10:15:00+07:00');
        config()->set('selection_integration.enabled', true);
        config()->set('selection_integration.client_id', self::CLIENT_ID);
        config()->set('selection_integration.client_secret', self::CLIENT_SECRET);
        config()->set('selection_integration.branch_ref', 'BEASISWA-JEPANG');
        config()->set('selection_integration.intended_field', 'UMUM');
        config()->set('selection_integration.test_types', ['ist']);
        config()->set('selection_integration.signature_tolerance_seconds', 300);

        $this->branch = app(RlsContextRunner::class)->run(
            new RlsContext('service'),
            fn (): Branch => Branch::query()->create([
                'code' => 'BEASISWA',
                'name' => 'Program Beasiswa Jepang',
                'ref_code' => 'BEASISWA-JEPANG',
                'is_default' => true,
                'is_active' => true,
            ]),
        );
    }

    public function test_selection_app_can_provision_a_ready_scholarship_participant(): void
    {
        $response = $this->signedRequest($this->payload(), 'psychotest-participant:v1:01K3TESTCANDIDATE000000001');

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['participantId']]);

        $participantId = (int) $response->json('data.participantId');
        $this->assertDatabaseHas('participants', [
            'id' => $participantId,
            'branch_id' => $this->branch->id,
            'referral_branch_id' => $this->branch->id,
            'referral_source' => 'manual',
            'full_name' => 'Ayu Pratiwi',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'UMUM',
            'email' => 'ayu@example.test',
        ]);
        $this->assertDatabaseHas('entitlements', [
            'participant_id' => $participantId,
            'order_id' => null,
            'test_type' => 'ist',
            'status' => 'ready',
        ]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('selection_participants', [
            'client_id' => self::CLIENT_ID,
            'external_candidate_id' => '01K3TESTCANDIDATE000000001',
            'selection_round_id' => '01K3TESTROUND0000000000001',
            'registration_id' => 'REG-2026-0001',
            'participant_id' => $participantId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $this->branch->id,
            'actor_type' => 'service',
            'actor_id' => self::CLIENT_ID,
            'action' => 'selection_participant.provisioned',
            'subject_id' => (string) $participantId,
        ]);
    }

    public function test_an_identical_idempotent_replay_returns_the_same_participant(): void
    {
        $payload = $this->payload();
        $key = 'psychotest-participant:v1:01K3TESTCANDIDATE000000001';
        $first = $this->signedRequest($payload, $key)->assertCreated();
        $second = $this->signedRequest($payload, $key)->assertOk();

        $this->assertSame($first->json('data.participantId'), $second->json('data.participantId'));
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('selection_participants', 1);
        $this->assertDatabaseCount('entitlements', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_reusing_an_idempotency_key_for_different_data_is_rejected(): void
    {
        $key = 'psychotest-participant:v1:01K3TESTCANDIDATE000000001';
        $this->signedRequest($this->payload(), $key)->assertCreated();

        $changed = [...$this->payload(), 'fullName' => 'Nama Berbeda'];
        $this->signedRequest($changed, $key)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseCount('participants', 1);
    }

    public function test_same_candidate_with_a_new_key_replays_only_when_payload_is_identical(): void
    {
        $first = $this->signedRequest(
            $this->payload(),
            'psychotest-participant:v1:01K3TESTCANDIDATE000000001',
        )->assertCreated();
        $second = $this->signedRequest(
            $this->payload(),
            'psychotest-participant:v1:retry-with-new-key',
        )->assertOk();

        $this->assertSame($first->json('data.participantId'), $second->json('data.participantId'));
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('selection_participants', 1);
    }

    public function test_invalid_signature_is_rejected_before_personal_data_is_stored(): void
    {
        $this->signedRequest($this->payload(), 'psychotest-participant:v1:invalid-signature', 'wrong-secret')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('selection_participants', 0);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $this->signedRequest(
            $this->payload(),
            'psychotest-participant:v1:stale-timestamp',
            self::CLIENT_SECRET,
            (string) Date::now()->subMinutes(6)->timestamp,
        )->assertUnauthorized()->assertJsonPath('error.code', 'STALE_REQUEST');

        $this->assertDatabaseCount('participants', 0);
    }

    public function test_unavailable_configured_branch_fails_without_falling_back_to_commercial_branch(): void
    {
        config()->set('selection_integration.branch_ref', 'MISSING-BRANCH');

        $this->signedRequest($this->payload(), 'psychotest-participant:v1:missing-branch')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'INTEGRATION_UNAVAILABLE');

        $this->assertDatabaseCount('participants', 0);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'externalCandidateId' => '01K3TESTCANDIDATE000000001',
            'selectionRoundId' => '01K3TESTROUND0000000000001',
            'registrationId' => 'REG-2026-0001',
            'fullName' => 'Ayu Pratiwi',
            'birthDate' => '2001-04-15',
            'gender' => 'female',
            'educationLevel' => 'SMA/SMK',
            'email' => 'ayu@example.test',
            'phone' => '+6281234567890',
        ];
    }

    /** @param array<string, string> $payload */
    private function signedRequest(
        array $payload,
        string $idempotencyKey,
        string $secret = self::CLIENT_SECRET,
        ?string $timestamp = null,
    ): TestResponse {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= (string) Date::now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), $secret);

        return $this->call(
            'POST',
            '/api/integrations/v1/selection/participants',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CLIENT_ID' => self::CLIENT_ID,
                'HTTP_X_TIMESTAMP' => $timestamp,
                'HTTP_X_SIGNATURE' => $signature,
                'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
            ],
            $body,
        );
    }
}
