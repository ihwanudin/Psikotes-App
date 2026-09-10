<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RecordAssessmentOutcome;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\Integrations\DeliverIntegrationCallback;
use App\Services\Integrations\ReconcileUnknownCallback;
use App\Services\Notifications\DispatchNotificationOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssessmentBillingFixture;
use Tests\TestCase;

final class AssessmentResultIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'result-client-secret-with-at-least-32-bytes';

    private IntegrationClient $client;

    private AssessmentParticipant $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-29 10:15:00+07:00');
        config()->set('assessment_integration.credentials.result-secret', self::SECRET);

        $organization = Branch::query()->create([
            'code' => 'SAKURA', 'name' => 'LPK Sakura', 'ref_code' => 'LPK-SAKURA',
            'organization_code' => 'LPK_SAKURA', 'organization_type' => 'EXTERNAL_LPK',
            'display_name' => 'LPK Sakura', 'status' => 'ACTIVE',
            'allowed_funding_modes' => ['SPONSORED'], 'is_default' => true, 'is_active' => true,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'WORK_V1', 'name' => 'Work', 'amount' => 100_000,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        $package->items()->create(['test_type' => 'ist']);
        $this->client = IntegrationClient::query()->create([
            'organization_id' => $organization->id,
            'client_id' => 'SAKURA_APP',
            'credential_reference' => 'result-secret',
            'callback_base_url' => 'https://selection.sakura.example',
            'result_delivery_mode' => 'CALLBACK_AND_POLL',
            'enabled' => true,
        ]);
        IntegrationSource::query()->create([
            'integration_client_id' => $this->client->id,
            'source_system' => 'SAKURA_SELECTION',
            'authentication_mode' => 'HMAC_SHA256',
            'allowed_assessment_packages' => ['WORK_V1'],
            'participant_provisioning_mode' => 'API',
            'commercial_mode' => 'CONTRACT',
            'allowed_funding_modes' => ['SPONSORED'],
            'callback_path' => '/api/psychotest/events',
            'callback_configuration' => ['reconciliationPath' => '/api/psychotest/events/{eventId}'],
            'status' => 'ACTIVE',
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $organization->id, 'referral_branch_id' => $organization->id,
            'referral_source' => 'manual', 'package_id' => $package->id,
            'full_name' => 'Private Person', 'gender' => 'female', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA', 'intended_field' => 'UMUM',
            'phone' => '628111111111', 'email' => 'private@example.test', 'test_number' => 'TEST-001',
        ]);
        $attemptPublicId = (string) Str::ulid();
        $case = AssessmentBillingFixture::createExactIntegratedCase(
            $participant->id, $organization->id, $package->id, $attemptPublicId,
        );
        $this->assessment = AssessmentParticipant::query()->create([
            'integration_client_id' => $this->client->id,
            'organization_id' => $organization->id,
            'participant_id' => $participant->id,
            'package_id' => $package->id,
            'assessment_case_id' => $case,
            'assessment_attempt_id' => $attemptPublicId,
            'source_system' => 'SAKURA_SELECTION',
            'external_candidate_id' => 'CAND-001',
            'external_process_id' => 'PROCESS-001',
            'assessment_round_id' => 'ROUND-001',
            'funding_mode' => 'SPONSORED',
            'assessment_status' => 'UNDER_REVIEW',
            'result_version' => 0,
            'idempotency_key' => 'seed',
            'request_hash' => str_repeat('a', 64),
            'logical_assessment_key' => str_repeat('b', 64),
        ]);
    }

    public function test_finalization_creates_exactly_one_safe_outbox_event_and_pull_projection(): void
    {
        $action = app(RecordAssessmentOutcome::class);
        $action->finalize($this->assessment, 'RECOMMENDED');
        $action->finalize($this->assessment->fresh(), 'RECOMMENDED');

        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame(0, app(DispatchNotificationOutbox::class)->handle());
        $message = json_decode((string) $this->getConnection()->table('outbox_messages')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PSYCHOTEST_RESULT_FINALIZED', $message['eventType']);
        $this->assertSame(1, $message['resultVersion']);
        $serialized = json_encode($message, JSON_THROW_ON_ERROR);
        foreach (['Private Person', 'private@example.test', '628111111111', 'answer', 'object_key', 'signed_url', self::SECRET] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        $this->signedGet('/api/integrations/v1/assessments/participants/CAND-001/result?externalProcessId=PROCESS-001')
            ->assertOk()
            ->assertExactJson(['data' => [
                'participantId' => (string) $this->assessment->participant_id,
                'externalCandidateId' => 'CAND-001',
                'externalProcessId' => 'PROCESS-001',
                'assessmentRoundId' => 'ROUND-001',
                'assessmentStatus' => 'FINALIZED',
                'recommendation' => 'RECOMMENDED',
                'resultVersion' => 1,
                'finalizedAt' => '2026-08-29T03:15:00.000000Z',
                'revokedAt' => null,
            ]]);
    }

    public function test_start_and_completion_emit_ordered_events_without_incrementing_result_version(): void
    {
        $this->assessment->forceFill(['assessment_status' => 'READY'])->save();
        $action = app(RecordAssessmentOutcome::class);

        $action->start($this->assessment);
        $action->start($this->assessment->fresh());
        $action->complete($this->assessment->fresh());

        $this->assertSame('COMPLETED', $this->assessment->fresh()->assessment_status);
        $this->assertSame(0, $this->assessment->fresh()->result_version);
        $events = $this->getConnection()->table('outbox_messages')->orderBy('id')->pluck('payload')
            ->map(fn (string $payload): string => json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['eventType'])
            ->all();
        $this->assertSame(['PSYCHOTEST_STARTED', 'PSYCHOTEST_COMPLETED'], $events);
    }

    public function test_client_cannot_pull_another_clients_result(): void
    {
        $other = IntegrationClient::query()->create([
            'organization_id' => $this->client->organization_id,
            'client_id' => 'OTHER_APP', 'credential_reference' => 'result-secret',
            'result_delivery_mode' => 'POLL', 'enabled' => true,
        ]);

        $this->signedGet('/api/integrations/v1/assessments/participants/CAND-001/result', $other->client_id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESULT_NOT_FOUND');
    }

    public function test_revoke_and_void_increment_result_version_and_emit_distinct_events(): void
    {
        $action = app(RecordAssessmentOutcome::class);
        $action->finalize($this->assessment, 'NEEDS_REVIEW');
        $action->revoke($this->assessment->fresh());

        $this->assessment->refresh();
        $this->assertSame('REVOKED', $this->assessment->assessment_status);
        $this->assertSame(2, $this->assessment->result_version);
        $this->assertDatabaseCount('outbox_messages', 2);

        $action->void($this->assessment);
        $this->assertSame('VOID', $this->assessment->fresh()->assessment_status);
        $this->assertSame(3, $this->assessment->fresh()->result_version);
        $this->assertDatabaseCount('outbox_messages', 3);
    }

    public function test_unknown_callback_is_reconciled_before_any_retry(): void
    {
        app(RecordAssessmentOutcome::class)->finalize($this->assessment, 'RECOMMENDED');
        $eventId = (string) $this->getConnection()->table('outbox_messages')->value('message_id');

        Http::fake(fn () => throw new ConnectionException('timeout after send'));
        app(DeliverIntegrationCallback::class)->handle($eventId);
        $this->assertDatabaseHas('integration_callback_deliveries', ['event_id' => $eventId, 'status' => 'UNKNOWN', 'attempts' => 1]);
        $this->assertDatabaseHas('outbox_messages', ['message_id' => $eventId, 'status' => 'processing']);

        Http::swap(new HttpFactory);
        Http::fake();
        app(DeliverIntegrationCallback::class)->handle($eventId);
        Http::assertNothingSent();

        Http::swap(new HttpFactory);
        Http::fake(fn () => Http::response(['received' => true]));
        $this->assertSame('DELIVERED', app(ReconcileUnknownCallback::class)->handle($eventId));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://selection.sakura.example/api/psychotest/events/'.$eventId);
        $this->assertDatabaseHas('integration_callback_deliveries', ['event_id' => $eventId, 'status' => 'DELIVERED', 'attempts' => 1]);
        $this->assertDatabaseHas('outbox_messages', ['message_id' => $eventId, 'status' => 'processed']);
    }

    private function signedGet(string $uri, string $clientId = 'SAKURA_APP'): TestResponse
    {
        $timestamp = (string) Date::now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp."\n".hash('sha256', ''), self::SECRET);

        return $this->withHeaders([
            'Accept' => 'application/json', 'X-Client-Id' => $clientId,
            'X-Timestamp' => $timestamp, 'X-Signature' => $signature,
        ])->get($uri);
    }
}
