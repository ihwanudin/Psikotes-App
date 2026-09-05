<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\GenericAssessmentResultVersion;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\Integrations\GenericAssessmentResultCallbackDispatcher;
use App\Services\Integrations\GenericAssessmentResultOutbox;
use App\Services\Integrations\GenericAssessmentResultStore;
use App\Services\Integrations\PsychotestSelectionRequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class GenericAssessmentResultCallbackDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'psychotest-to-selection-secret-32-bytes-minimum';

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-05 08:00:00+00:00');
        Http::preventStrayRequests();
        config()->set('selection_integration.result_callback_enabled', true);
        config()->set('selection_integration.result_callback_base_url', 'https://seleksi.beasiswajepang.id');
        config()->set('selection_integration.result_callback_secret', self::SECRET);
        config()->set('selection_integration.result_callback_timeout_seconds', 10);
        config()->set('selection_integration.client_secret', 'selection-to-psychotest-secret-is-distinct');
    }

    public function test_it_sends_the_exact_latest_canonical_envelope_and_acknowledges_accepted_response(): void
    {
        [$assessment, $source, $outboxId] = $this->outbox(98.75);
        Http::fake(['https://seleksi.beasiswajepang.id/*' => Http::response(['data' => ['status' => 'ACCEPTED']], 202)]);

        $result = app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
            $outboxId,
            $source->id,
            1,
            $source->result_checksum,
            str_repeat('callback-lease-token-', 2),
        );

        $this->assertSame('COMPLETED', $result['action']);
        $this->assertSame('ACKNOWLEDGED', $result['outcome']);
        Http::assertSent(function (Request $request) use ($assessment, $source): bool {
            $body = $request->body();
            $this->assertSame([
                'assessmentAttemptId' => $assessment->assessment_attempt_id,
                'iq' => 98.75,
                'engineVersion' => 'ist-2026.09.1',
                'completedAt' => '2026-09-05T03:15:30.123456Z',
                'finality' => 'FINALIZED',
                'revokedAt' => null,
                'resultVersion' => 1,
                'resultChecksum' => $source->result_checksum,
            ], json_decode($body, true, 32, JSON_THROW_ON_ERROR));
            $timestamp = (string) Date::now()->timestamp;
            $this->assertSame([$timestamp], $request->header('X-Psychotest-Timestamp'));
            $this->assertSame(['generic-assessment-result'], $request->header('X-Psychotest-Contract'));
            $this->assertSame(['1'], $request->header('X-Psychotest-Contract-Version'));
            $this->assertSame(['v2'], $request->header('X-Psychotest-Signature-Version'));
            $expected = app(PsychotestSelectionRequestSigner::class)->sign(
                $timestamp, 'POST', '/api/v2/integrations/psychotest/results', '', $body, self::SECRET,
            );
            $this->assertSame([$expected], $request->header('X-Psychotest-Signature'));

            return $request->url() === 'https://seleksi.beasiswajepang.id/api/v2/integrations/psychotest/results'
                && $request->hasHeader('Content-Type', 'application/json');
        });
        $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
            'outbox_id' => $outboxId, 'outcome' => 'ACKNOWLEDGED', 'reason_code' => null,
        ]);
        $audits = DB::table('audit_logs')
            ->where('action', 'like', 'generic_assessment_result_dispatch.%')->get();
        $this->assertCount(2, $audits);
        foreach ($audits as $audit) {
            $encoded = (string) $audit->context;
            $this->assertStringNotContainsString($assessment->assessment_attempt_id, $encoded);
            $this->assertStringNotContainsString('98.75', $encoded);
            $this->assertStringNotContainsString(self::SECRET, $encoded);
            $this->assertStringNotContainsString('seleksi.beasiswajepang.id', $encoded);
        }
    }

    public function test_http_outcomes_map_to_the_existing_durable_retry_policy(): void
    {
        $statuses = [200, 429, 503, 422, 408];
        Http::fake(static function () use (&$statuses) {
            $status = (int) array_shift($statuses);

            return Http::response($status === 200 ? ['data' => ['status' => 'REPLAYED']] : [], $status);
        });
        foreach ([
            200 => ['ACKNOWLEDGED', null],
            429 => ['RETRYABLE', 'RATE_LIMITED'],
            503 => ['RETRYABLE', 'TRANSIENT_UNAVAILABLE'],
            422 => ['PERMANENT', 'REMOTE_REJECTED'],
            408 => ['UNKNOWN', 'OUTCOME_UNCERTAIN'],
        ] as $status => [$expectedOutcome, $expectedReason]) {
            [, $source, $outboxId] = $this->outbox();
            $result = app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
                $outboxId,
                $source->id,
                1,
                $source->result_checksum,
                str_repeat('status-'.$status.'-token-', 3),
            );

            $this->assertSame($expectedOutcome, $result['outcome']);
            $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
                'outbox_id' => $outboxId, 'outcome' => $expectedOutcome, 'reason_code' => $expectedReason,
            ]);
        }
    }

    public function test_only_the_exact_bounded_json_acknowledgement_contract_is_acknowledged(): void
    {
        $responses = [
            Http::response(['data' => ['status' => 'REPLAYED']], 200, ['Content-Type' => 'application/json; charset=UTF-8']),
            Http::response(['data' => ['status' => 'ACCEPTED']], 200),
            Http::response(['data' => ['status' => 'REPLAYED']], 202),
            Http::response(['data' => ['status' => 'ACCEPTED'], 'extra' => true], 202),
            Http::response(['data' => ['status' => 'ACCEPTED', 'extra' => true]], 202),
            Http::response('{"data":{"status":"ACCEPTED"}}', 202, ['Content-Type' => 'text/plain']),
            Http::response('{not-json', 202, ['Content-Type' => 'application/json']),
            Http::response(str_repeat(' ', 4097), 202, ['Content-Type' => 'application/json']),
        ];
        Http::fake(static function () use (&$responses) {
            return array_shift($responses);
        });

        foreach ([
            'ACKNOWLEDGED',
            'UNKNOWN',
            'UNKNOWN',
            'UNKNOWN',
            'UNKNOWN',
            'UNKNOWN',
            'UNKNOWN',
            'UNKNOWN',
        ] as $index => $expectedOutcome) {
            [, $source, $outboxId] = $this->outbox();
            $result = app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
                $outboxId,
                $source->id,
                1,
                $source->result_checksum,
                str_repeat('ack-contract-'.$index.'-', 3),
            );

            $this->assertSame($expectedOutcome, $result['outcome'], 'ACK response case '.$index.' was misclassified.');
            $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
                'outbox_id' => $outboxId,
                'outcome' => $expectedOutcome,
                'reason_code' => $expectedOutcome === 'UNKNOWN' ? 'OUTCOME_UNCERTAIN' : null,
            ]);
        }

        $retry = app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
            $outboxId,
            $source->id,
            1,
            $source->result_checksum,
            str_repeat('malformed-ack-retry-', 2),
        );
        $this->assertSame('SKIPPED_TERMINAL', $retry['action']);
        Http::assertSentCount(8);
    }

    public function test_connection_ambiguity_is_unknown_terminal_and_is_not_automatically_resent(): void
    {
        [, $source, $outboxId] = $this->outbox();
        $requests = 0;
        Http::fake(function () use (&$requests): never {
            $requests++;
            throw new ConnectionException('synthetic timeout after possible acceptance');
        });
        $dispatcher = app(GenericAssessmentResultCallbackDispatcher::class);

        $first = $dispatcher->dispatchExact(
            $outboxId, $source->id, 1, $source->result_checksum, str_repeat('unknown-token-', 3),
        );
        $second = $dispatcher->dispatchExact(
            $outboxId, $source->id, 1, $source->result_checksum, str_repeat('later-token-', 4),
        );

        $this->assertSame('UNKNOWN', $first['outcome']);
        $this->assertSame('SKIPPED_TERMINAL', $second['action']);
        $this->assertSame(1, $requests);
        $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
            'outbox_id' => $outboxId, 'outcome' => 'UNKNOWN', 'reason_code' => 'OUTCOME_UNCERTAIN',
        ]);
    }

    public function test_redirects_are_not_followed_and_untrusted_endpoint_configuration_fails_before_claim(): void
    {
        [, $source, $outboxId] = $this->outbox();
        Http::fake(['https://seleksi.beasiswajepang.id/*' => Http::response('', 302, ['Location' => 'https://evil.example/collect'])]);
        $redirect = app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
            $outboxId, $source->id, 1, $source->result_checksum, str_repeat('redirect-token-', 3),
        );
        $this->assertSame('PERMANENT', $redirect['outcome']);
        Http::assertSentCount(1);

        [, $unsafeSource, $unsafeOutboxId] = $this->outbox();
        config()->set('selection_integration.result_callback_base_url', 'https://127.0.0.1');
        try {
            app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
                $unsafeOutboxId,
                $unsafeSource->id,
                1,
                $unsafeSource->result_checksum,
                str_repeat('unsafe-token-', 3),
            );
            $this->fail('Private receiver address was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('ASSESSMENT_RESULT_CALLBACK_CONFIG_INVALID', $exception->getMessage());
        }
        $this->assertDatabaseMissing('generic_assessment_result_dispatch_attempts', ['outbox_id' => $unsafeOutboxId]);
        Http::assertSentCount(1);

        config()->set('selection_integration.result_callback_base_url', 'https://evil.example');
        try {
            app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
                $unsafeOutboxId,
                $unsafeSource->id,
                1,
                $unsafeSource->result_checksum,
                str_repeat('unallowlisted-token-', 2),
            );
            $this->fail('An unallowlisted receiver hostname was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('ASSESSMENT_RESULT_CALLBACK_CONFIG_INVALID', $exception->getMessage());
        }

        config()->set('selection_integration.result_callback_base_url', 'https://seleksi.beasiswajepang.id');
        config()->set('selection_integration.result_callback_secret', (string) config('selection_integration.client_secret'));
        try {
            app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
                $unsafeOutboxId,
                $unsafeSource->id,
                1,
                $unsafeSource->result_checksum,
                str_repeat('shared-secret-token-', 2),
            );
            $this->fail('A shared bidirectional secret was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('ASSESSMENT_RESULT_CALLBACK_CONFIG_INVALID', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_stale_outbox_source_is_not_claimed_or_sent(): void
    {
        [$assessment, $source, $outboxId] = $this->outbox();
        $this->persist($assessment, 101.5, 2);
        Http::fake();

        try {
            app(GenericAssessmentResultCallbackDispatcher::class)->dispatchExact(
                $outboxId, $source->id, 1, $source->result_checksum, str_repeat('stale-token-', 3),
            );
            $this->fail('A stale outbox source was sent.');
        } catch (LogicException $exception) {
            $this->assertSame('ASSESSMENT_RESULT_CALLBACK_SOURCE_NOT_LATEST', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('generic_assessment_result_dispatch_attempts', 0);
    }

    public function test_signer_canonicalizes_sorted_duplicate_preserving_rfc3986_query(): void
    {
        $signature = app(PsychotestSelectionRequestSigner::class)->sign(
            '1757059200',
            'POST',
            '/api/v2/integrations/psychotest/results',
            'z=two+words&a=%2Fpath&z=one%20word',
            '{"result":true}',
            self::SECRET,
        );
        $canonical = implode("\n", [
            'psychotest-selection-hmac:v2',
            '1757059200',
            'generic-assessment-result',
            '1',
            'POST',
            '/api/v2/integrations/psychotest/results',
            'a=%2Fpath&z=one%20word&z=two%2Bwords',
            hash('sha256', '{"result":true}'),
        ]);

        $this->assertSame(hash_hmac('sha256', $canonical, self::SECRET), $signature);
    }

    /** @return array{AssessmentParticipant,GenericAssessmentResultVersion,string} */
    private function outbox(int|float $iq = 99.125): array
    {
        $assessment = $this->assessment();
        $source = $this->persist($assessment, $iq, 1);
        $outbox = app(GenericAssessmentResultOutbox::class)->enqueueExact(
            $source->id, $assessment->assessment_attempt_id, 1, $source->result_checksum,
        );

        return [$assessment, $source, (string) $outbox['outboxId']];
    }

    private function persist(AssessmentParticipant $assessment, int|float $iq, int $version): GenericAssessmentResultVersion
    {
        app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot([
            'assessmentAttemptId' => $assessment->assessment_attempt_id,
            'iq' => $iq,
            'engineVersion' => 'ist-2026.09.1',
            'completedAt' => '2026-09-05T10:15:30.123456+07:00',
            'finality' => 'FINALIZED',
            'revokedAt' => null,
            'resultVersion' => $version,
        ]);

        return GenericAssessmentResultVersion::query()
            ->where('assessment_participant_id', $assessment->id)
            ->where('result_version', $version)->sole();
    }

    private function assessment(): AssessmentParticipant
    {
        $key = (string) Str::ulid();
        $organization = Branch::query()->create([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic result organization',
            'organization_code' => $key, 'display_name' => 'Synthetic result organization',
            'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $organization->id, 'referral_branch_id' => $organization->id,
            'referral_source' => 'manual', 'source_system' => 'RESULT_CALLBACK_TEST',
            'full_name' => 'Synthetic participant', 'phone' => '620000000000',
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $organization->id, 'client_id' => $key,
            'credential_reference' => 'callback-test-only', 'enabled' => true,
            'result_delivery_mode' => 'CALLBACK_AND_POLL',
        ]);
        $package = TestPackage::query()->create([
            'code' => 'R'.$key, 'name' => 'Synthetic result package',
            'amount' => 100, 'currency' => 'IDR', 'is_active' => true,
        ]);

        return AssessmentParticipant::query()->create([
            'organization_id' => $organization->id, 'integration_client_id' => $client->id,
            'participant_id' => $participant->id, 'package_id' => $package->id,
            'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => 'RESULT_CALLBACK_TEST',
            'external_candidate_id' => $key, 'funding_mode' => 'SPONSORED',
            'assessment_status' => 'UNDER_REVIEW', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);
    }
}
