<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\AssessmentCase;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\GenericAssessmentResultVersion;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\Integrations\GenericAssessmentResultDispatch;
use App\Services\Integrations\GenericAssessmentResultOutbox;
use App\Services\Integrations\GenericAssessmentResultPollProjection;
use App\Services\Integrations\GenericAssessmentResultStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GenericAssessmentResultPollProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-05 05:00:00+00:00');
    }

    public function test_it_returns_the_latest_exact_publishable_envelope_without_rounding_and_audits_safely(): void
    {
        [$assessment, $client, $source] = $this->published(iq: 98.75);
        $resultTimestampsBefore = DB::table('generic_assessment_result_versions')
            ->where('id', $source->id)
            ->first(['completed_at', 'created_at', 'revoked_at']);
        Date::setTestNow('2024-02-29 10:15:00+07:00');
        $selects = 0;
        DB::listen(static function ($query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selects++;
            }
        });

        $result = app(GenericAssessmentResultPollProjection::class)->project(
            $client->id,
            strtolower($assessment->assessment_attempt_id),
        );

        $this->assertSame('AVAILABLE', $result['status']);
        $this->assertSame($assessment->assessment_attempt_id, $result['envelope']['assessmentAttemptId']);
        $this->assertSame(98.75, $result['envelope']['iq']);
        $this->assertSame($source->result_checksum, $result['envelope']['resultChecksum']);
        $this->assertSame(1, $selects);

        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result_poll.available')->sole();
        $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([
            'assessmentAttemptReference' => hash('sha256', $assessment->assessment_attempt_id),
            'resultVersion' => 1,
            'resultChecksum' => $source->result_checksum,
            'finality' => 'FINALIZED',
            'isRevoked' => false,
        ], $context);
        $this->assertSame('2024-02-29 03:15:00', $audit->occurred_at);
        $this->assertSame('2029-02-28 03:15:00', $audit->expires_at);
        $resultTimestampsAfter = DB::table('generic_assessment_result_versions')
            ->where('id', $source->id)
            ->first(['completed_at', 'created_at', 'revoked_at']);
        $this->assertEquals($resultTimestampsBefore, $resultTimestampsAfter);
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($assessment->assessment_attempt_id, $encoded);
        $this->assertStringNotContainsString('98.75', $encoded);
        $this->assertStringNotContainsString('Synthetic participant', $encoded);
        $this->assertStringNotContainsString('synthetic-client-secret', $encoded);
    }

    public function test_exact_cursor_is_replayed_but_an_older_cursor_receives_the_latest_correction(): void
    {
        [$assessment, $client, $first] = $this->published();
        $poll = app(GenericAssessmentResultPollProjection::class);

        $replay = $poll->project(
            $client->id, $assessment->assessment_attempt_id, 1, $first->result_checksum,
        );
        $this->assertSame('REPLAYED', $replay['status']);
        $this->assertSame($first->result_checksum, $replay['envelope']['resultChecksum']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'generic_assessment_result_poll.replayed']);

        $second = $this->persist($assessment, iq: 101.125, version: 2);
        app(GenericAssessmentResultOutbox::class)->enqueueExact(
            $second->id, $assessment->assessment_attempt_id, 2, $second->result_checksum,
        );
        $latest = $poll->project(
            $client->id, $assessment->assessment_attempt_id, 1, $first->result_checksum,
        );
        $this->assertSame('AVAILABLE', $latest['status']);
        $this->assertSame(2, $latest['envelope']['resultVersion']);
        $this->assertSame(101.125, $latest['envelope']['iq']);
        $this->assertSame($second->result_checksum, $latest['envelope']['resultChecksum']);
    }

    public function test_same_version_checksum_conflict_or_future_cursor_is_generic_unavailable(): void
    {
        [$assessment, $client, $source] = $this->published();
        $poll = app(GenericAssessmentResultPollProjection::class);

        foreach ([
            [1, str_repeat('b', 64)],
            [2, $source->result_checksum],
        ] as [$version, $checksum]) {
            $this->assertSame(
                ['status' => 'UNAVAILABLE', 'envelope' => null],
                $poll->project($client->id, $assessment->assessment_attempt_id, $version, $checksum),
            );
        }

        $this->assertSame(2, DB::table('audit_logs')
            ->where('action', 'generic_assessment_result_poll.unavailable')->count());
    }

    public function test_missing_foreign_or_partial_cursor_is_generic_unavailable_with_safe_audit(): void
    {
        [$assessment, $client, $source] = $this->published();
        [, $foreignClient] = $this->assessment();
        Date::setTestNow('2024-02-29 10:15:00+07:00');
        $poll = app(GenericAssessmentResultPollProjection::class);

        foreach ([
            [$foreignClient->id, $assessment->assessment_attempt_id, null, null],
            [$client->id, (string) Str::ulid(), null, null],
            [$client->id, $assessment->assessment_attempt_id, 1, null],
            [$client->id, $assessment->assessment_attempt_id, null, $source->result_checksum],
        ] as [$clientId, $attemptId, $version, $checksum]) {
            $result = $poll->project($clientId, $attemptId, $version, $checksum);
            $this->assertSame(['status' => 'UNAVAILABLE', 'envelope' => null], $result);
        }

        $audits = DB::table('audit_logs')->where('action', 'generic_assessment_result_poll.unavailable')->get();
        $this->assertCount(4, $audits);
        foreach ($audits as $audit) {
            $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(
                ['assessmentAttemptReference', 'resultVersion', 'resultChecksum', 'finality', 'isRevoked'],
                array_keys($context),
            );
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context['assessmentAttemptReference']);
            $this->assertNull($context['resultVersion']);
            $this->assertNull($context['resultChecksum']);
            $this->assertNull($context['finality']);
            $this->assertNull($context['isRevoked']);
            $this->assertSame('2024-02-29 03:15:00', $audit->occurred_at);
            $this->assertSame('2029-02-28 03:15:00', $audit->expires_at);
            $encoded = json_encode($context, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($assessment->assessment_attempt_id, $encoded);
            $this->assertStringNotContainsString('Synthetic participant', $encoded);
            $this->assertStringNotContainsString('synthetic-client-secret', $encoded);
        }
    }

    public function test_stale_outbox_is_unavailable_until_the_latest_persisted_result_is_published(): void
    {
        [$assessment, $client] = $this->published();
        $this->persist($assessment, iq: 105.5, version: 2);

        $result = app(GenericAssessmentResultPollProjection::class)->project(
            $client->id,
            $assessment->assessment_attempt_id,
        );

        $this->assertSame(['status' => 'UNAVAILABLE', 'envelope' => null], $result);
    }

    public function test_disabled_integration_client_cannot_read_or_confirm_attempt_existence(): void
    {
        [$assessment, $client] = $this->published();
        $client->forceFill(['enabled' => false])->save();

        $result = app(GenericAssessmentResultPollProjection::class)->project(
            $client->id,
            $assessment->assessment_attempt_id,
        );

        $this->assertSame(['status' => 'UNAVAILABLE', 'envelope' => null], $result);
        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result_poll.unavailable')->sole();
        $this->assertStringNotContainsString($assessment->assessment_attempt_id, (string) $audit->context);
        $this->assertNull($audit->subject_id);
    }

    public function test_dispatch_unknown_does_not_override_the_authoritative_poll_result(): void
    {
        [$assessment, $client, $source, $outboxId] = $this->published();
        $dispatch = app(GenericAssessmentResultDispatch::class);
        $token = str_repeat('poll-unknown-token-', 2);
        $claim = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $token);
        Date::setTestNow('2026-09-05 05:01:00+00:00');
        $dispatch->completeExact(
            $outboxId, $source->id, 1, $source->result_checksum,
            $claim['attemptId'], $token, 'UNKNOWN', 'OUTCOME_UNCERTAIN',
        );

        $result = app(GenericAssessmentResultPollProjection::class)->project(
            $client->id,
            $assessment->assessment_attempt_id,
        );

        $this->assertSame('AVAILABLE', $result['status']);
        $this->assertSame($source->result_checksum, $result['envelope']['resultChecksum']);
    }

    public function test_corrupt_persisted_source_fails_closed_as_generic_unavailable(): void
    {
        [$assessment, $client] = $this->published();
        DB::unprepared('DROP TRIGGER generic_assessment_result_update_guard');
        DB::table('generic_assessment_result_versions')
            ->where('assessment_participant_id', $assessment->id)
            ->update(['iq' => 120, 'iq_canonical' => '120']);

        $result = app(GenericAssessmentResultPollProjection::class)->project(
            $client->id,
            $assessment->assessment_attempt_id,
        );

        $this->assertSame(['status' => 'UNAVAILABLE', 'envelope' => null], $result);
        $this->assertDatabaseHas('audit_logs', ['action' => 'generic_assessment_result_poll.unavailable']);
    }

    /** @return array{AssessmentParticipant, IntegrationClient, GenericAssessmentResultVersion, string} */
    private function published(int|float $iq = 99.125): array
    {
        [$assessment, $client] = $this->assessment();
        $source = $this->persist($assessment, $iq);
        $outbox = app(GenericAssessmentResultOutbox::class)->enqueueExact(
            $source->id, $assessment->assessment_attempt_id, 1, $source->result_checksum,
        );

        return [$assessment, $client, $source, (string) $outbox['outboxId']];
    }

    private function persist(AssessmentParticipant $assessment, int|float $iq = 99.125, int $version = 1): GenericAssessmentResultVersion
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
            'referral_source' => 'manual', 'source_system' => 'RESULT_TEST',
            'full_name' => 'Synthetic participant', 'phone' => '620000000000',
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $organization->id, 'client_id' => $key,
            'credential_reference' => 'synthetic-client-secret', 'enabled' => true,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'R'.$key, 'name' => 'Synthetic result package',
            'amount' => 100, 'currency' => 'IDR', 'is_active' => true,
        ]);
        $assessmentAttemptId = (string) Str::ulid();
        $case = AssessmentCase::query()->create([
            'public_id' => $assessmentAttemptId,
            'participant_id' => $participant->id,
            'organization_id' => $organization->id,
            'package_id' => $package->id,
            'origin' => 'INTEGRATED',
            'intended_field_snapshot' => null,
        ]);
        $assessment = AssessmentParticipant::query()->create([
            'organization_id' => $organization->id, 'integration_client_id' => $client->id,
            'participant_id' => $participant->id, 'package_id' => $package->id,
            'assessment_case_id' => $case->id,
            'assessment_attempt_id' => $assessmentAttemptId, 'source_system' => 'RESULT_TEST',
            'external_candidate_id' => $key, 'funding_mode' => 'SPONSORED',
            'assessment_status' => 'UNDER_REVIEW', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);

        return [$assessment, $client];
    }
}
