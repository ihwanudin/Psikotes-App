<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\GenericAssessmentResultVersion;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\Integrations\GenericAssessmentResultOutbox;
use App\Services\Integrations\GenericAssessmentResultStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class GenericAssessmentResultPollHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string CLIENT_SECRET = 'selection-poll-secret-with-at-least-32-bytes';

    private const string CALLBACK_SECRET = 'callback-secret-must-not-authenticate-poll';

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-05 06:00:00+00:00');
        config()->set('selection_integration.enabled', true);
        config()->set('selection_integration.client_secret', self::CLIENT_SECRET);
        config()->set('selection_integration.signature_tolerance_seconds', 300);
        config()->set('assessment_integration.credentials.callback-secret', self::CALLBACK_SECRET);

        Route::middleware('api')->prefix('api')->group(base_path('routes/selection-result-poll.php'));
    }

    public function test_selection_can_poll_an_exact_attempt_with_the_dedicated_credential(): void
    {
        [$assessment, $client, $result] = $this->published(98.75);
        config()->set('selection_integration.client_id', $client->client_id);
        $projectionRanAsService = false;
        DB::listen(static function ($query) use (&$projectionRanAsService): void {
            if (str_contains($query->sql, 'generic_assessment_result_outbox')) {
                $projectionRanAsService = app(RlsContextRunner::class)->current()?->role === 'service';
            }
        });

        $response = $this->signedPoll($assessment->assessment_attempt_id);

        $this->assertTrue($projectionRanAsService);
        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'status' => 'AVAILABLE',
                'envelope' => [
                    'assessmentAttemptId' => $assessment->assessment_attempt_id,
                    'iq' => 98.75,
                    'engineVersion' => 'ist-2026.09.1',
                    'completedAt' => '2026-09-05T03:15:30.123456Z',
                    'finality' => 'FINALIZED',
                    'revokedAt' => null,
                    'resultVersion' => 1,
                    'resultChecksum' => $result->result_checksum,
                ],
            ]]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => (string) $client->id,
            'action' => 'generic_assessment_result_poll.available',
        ]);
    }

    public function test_cursor_is_paired_and_the_signature_binds_path_and_query(): void
    {
        [$assessment, $client, $result] = $this->published();
        config()->set('selection_integration.client_id', $client->client_id);
        $query = 'version=1&checksum='.$result->result_checksum;

        $this->signedPoll($assessment->assessment_attempt_id, $query)
            ->assertOk()->assertJsonPath('data.status', 'REPLAYED');

        $otherAttempt = (string) Str::ulid();
        $this->signedPoll($otherAttempt, $query, self::CLIENT_SECRET, $this->path($assessment->assessment_attempt_id), $query)
            ->assertUnauthorized()->assertExactJson([
                'error' => ['code' => 'AUTHENTICATION_FAILED', 'message' => 'Autentikasi layanan tidak valid.'],
            ]);
        $this->signedPoll($assessment->assessment_attempt_id, 'version=2&checksum='.$result->result_checksum, self::CLIENT_SECRET, null, $query)
            ->assertUnauthorized()->assertExactJson([
                'error' => ['code' => 'AUTHENTICATION_FAILED', 'message' => 'Autentikasi layanan tidak valid.'],
            ]);
        $this->signedPoll($assessment->assessment_attempt_id, 'version=1', self::CLIENT_SECRET)
            ->assertOk()->assertExactJson(['data' => ['status' => 'UNAVAILABLE', 'envelope' => null]]);
    }

    public function test_stale_signed_request_is_rejected_without_polling_result_data(): void
    {
        [$assessment, $client] = $this->published();
        config()->set('selection_integration.client_id', $client->client_id);

        $this->signedPoll(
            $assessment->assessment_attempt_id,
            timestamp: (string) Date::now()->subMinutes(6)->timestamp,
        )->assertUnauthorized()->assertExactJson([
            'error' => ['code' => 'AUTHENTICATION_FAILED', 'message' => 'Autentikasi layanan tidak valid.'],
        ]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'generic_assessment_result_poll.available']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'selection_result_poll.authentication_denied']);
    }

    public function test_foreign_attempt_is_indistinguishable_from_an_unavailable_result(): void
    {
        [$assessment] = $this->published();
        [, $authorizedClient] = $this->assessment();
        config()->set('selection_integration.client_id', $authorizedClient->client_id);

        $this->signedPoll($assessment->assessment_attempt_id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => ['status' => 'UNAVAILABLE', 'envelope' => null]]);
    }

    public function test_bad_callback_credential_and_disabled_registry_binding_fail_generically_and_audit_safely(): void
    {
        [$assessment, $client] = $this->published();
        config()->set('selection_integration.client_id', $client->client_id);

        $this->signedPoll($assessment->assessment_attempt_id, '', self::CALLBACK_SECRET)
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'error' => ['code' => 'AUTHENTICATION_FAILED', 'message' => 'Autentikasi layanan tidak valid.'],
            ]);

        $client->forceFill(['enabled' => false])->save();
        $this->signedPoll($assessment->assessment_attempt_id)
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => ['code' => 'AUTHENTICATION_FAILED', 'message' => 'Autentikasi layanan tidak valid.'],
            ]);

        $audits = DB::table('audit_logs')->where('action', 'selection_result_poll.authentication_denied')->get();
        $this->assertCount(2, $audits);
        foreach ($audits as $audit) {
            $this->assertNull($audit->branch_id);
            $this->assertNull($audit->actor_id);
            $this->assertNull($audit->subject_id);
            $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($assessment->assessment_attempt_id, $encoded);
            $this->assertStringNotContainsString($client->client_id, $encoded);
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $encoded);
            $this->assertStringNotContainsString(self::CALLBACK_SECRET, $encoded);
            $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
            $this->assertContains($context['reasonCode'], ['SIGNATURE_INVALID', 'CLIENT_REGISTRY_UNAVAILABLE']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context['clientReference']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context['requestReference']);
        }
    }

    public function test_rate_limit_precedes_authentication_audit_and_remains_private(): void
    {
        [$assessment, $client] = $this->published();
        config()->set('selection_integration.client_id', $client->client_id);
        $key = 'selection-result-poll:'.hash('sha256', '127.0.0.1');
        for ($attempt = 0; $attempt < 120; $attempt++) {
            RateLimiter::hit($key, 60);
        }

        $this->signedPoll($assessment->assessment_attempt_id, secret: 'wrong-secret-with-at-least-32-bytes')
            ->assertTooManyRequests()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'error' => ['code' => 'RATE_LIMITED', 'message' => 'Terlalu banyak permintaan layanan.'],
            ]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'selection_result_poll.authentication_denied']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'generic_assessment_result_poll.available']);
    }

    /** @return array{AssessmentParticipant, IntegrationClient, GenericAssessmentResultVersion} */
    private function published(int|float $iq = 99.125): array
    {
        [$assessment, $client] = $this->assessment();
        app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot([
            'assessmentAttemptId' => $assessment->assessment_attempt_id,
            'iq' => $iq,
            'engineVersion' => 'ist-2026.09.1',
            'completedAt' => '2026-09-05T10:15:30.123456+07:00',
            'finality' => 'FINALIZED',
            'revokedAt' => null,
            'resultVersion' => 1,
        ]);
        $result = GenericAssessmentResultVersion::query()
            ->where('assessment_participant_id', $assessment->id)->sole();
        app(GenericAssessmentResultOutbox::class)->enqueueExact(
            $result->id,
            $assessment->assessment_attempt_id,
            1,
            $result->result_checksum,
        );

        return [$assessment, $client, $result];
    }

    /** @return array{AssessmentParticipant, IntegrationClient} */
    private function assessment(): array
    {
        $key = (string) Str::ulid();
        $organization = Branch::query()->create([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic result organization',
            'organization_code' => $key, 'display_name' => 'Synthetic result organization',
            'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $organization->id, 'referral_branch_id' => $organization->id,
            'referral_source' => 'manual', 'source_system' => 'RESULT_HTTP_TEST',
            'full_name' => 'Synthetic participant', 'phone' => '620000000000',
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $organization->id, 'client_id' => $key,
            'credential_reference' => 'callback-secret', 'enabled' => true,
            'result_delivery_mode' => 'CALLBACK_AND_POLL',
        ]);
        $package = TestPackage::query()->create([
            'code' => 'R'.$key, 'name' => 'Synthetic result package',
            'amount' => 100, 'currency' => 'IDR', 'is_active' => true,
        ]);
        $assessment = AssessmentParticipant::query()->create([
            'organization_id' => $organization->id, 'integration_client_id' => $client->id,
            'participant_id' => $participant->id, 'package_id' => $package->id,
            'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => 'RESULT_HTTP_TEST',
            'external_candidate_id' => $key, 'funding_mode' => 'SPONSORED',
            'assessment_status' => 'UNDER_REVIEW', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);

        return [$assessment, $client];
    }

    private function signedPoll(
        string $attemptId,
        string $query = '',
        string $secret = self::CLIENT_SECRET,
        ?string $signedPath = null,
        ?string $signedQuery = null,
        ?string $timestamp = null,
    ): TestResponse {
        $path = $this->path($attemptId);
        $timestamp ??= (string) Date::now()->timestamp;
        $signature = hash_hmac('sha256', implode("\n", [
            $timestamp,
            'selection-result-poll:v1',
            'GET',
            $signedPath ?? $path,
            $this->canonicalQuery($signedQuery ?? $query),
            hash('sha256', ''),
        ]), $secret);

        return $this->call('GET', $path.($query === '' ? '' : '?'.$query), [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => (string) config('selection_integration.client_id'),
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_INTEGRATION_CONTRACT' => 'selection-result-poll:v1',
            'HTTP_X_SIGNATURE_VERSION' => 'v2',
            'HTTP_X_SIGNATURE' => $signature,
        ]);
    }

    private function path(string $attemptId): string
    {
        return '/api/integrations/v1/selection/assessment-attempts/'.$attemptId.'/result';
    }

    private function canonicalQuery(string $query): string
    {
        parse_str($query, $parameters);
        ksort($parameters);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
