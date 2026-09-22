<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\SubtestNext;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\TimedSegmentTransitionPolicy;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantJwt;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

/**
 * F2 timed-segments stage 4 (2026-09-22). Thin HTTP-level smoke coverage
 * for POST /sessions/{id}/subtest/next -- proves the route/controller/
 * middleware wiring and error-code -> status mapping. The underlying
 * decision logic itself is exhaustively covered at the domain
 * (TimedSegmentTransitionPolicyTest) and action (SubtestNextTest) layers;
 * this file does not re-test that.
 */
final class SubtestNextHttpTest extends OrganizationPaymentTestCase
{
    private int $attempt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
        $this->bindClock('2026-09-08T03:00:30+07:00');
    }

    public function test_confirming_a_reading_gap_returns_200_with_the_new_segment_state(): void
    {
        $participant = $this->participant();
        $sessionId = $this->sessionPublicId(
            $participant, becameCurrentAt: '2026-09-08 03:00:10+07:00', startedAt: null, readingCapSeconds: 60,
        );

        $response = $this->withToken($this->token($participant))
            ->postJson("/api/sessions/{$sessionId}/subtest/next")
            ->assertOk();

        $response->assertJsonPath('session_id', $sessionId);
        $response->assertJsonPath('current_segment.index', 0);
        $this->assertNotNull($response->json('current_segment.started_at'));
    }

    public function test_early_finish_disallowed_returns_422(): void
    {
        $participant = $this->participant();
        $sessionId = $this->sessionPublicId(
            $participant, becameCurrentAt: '2026-09-08 03:00:00+07:00', startedAt: '2026-09-08 03:00:00+07:00',
        );

        $this->withToken($this->token($participant))
            ->postJson("/api/sessions/{$sessionId}/subtest/next")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_SESSION_TRANSITION');
    }

    public function test_nonexistent_session_returns_404(): void
    {
        $participant = $this->participant();

        $this->withToken($this->token($participant))
            ->postJson('/api/sessions/'.Str::ulid().'/subtest/next')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'SESSION_NOT_FOUND');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/sessions/'.Str::ulid().'/subtest/next')
            ->assertStatus(401);
    }

    private function bindClock(string $iso): void
    {
        $this->app->instance(SubtestNext::class, new SubtestNext(
            $this->app->make(RlsContextRunner::class),
            new TimedSegmentTransitionPolicy,
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        ));
    }

    private function token(int $participant): string
    {
        $branch = (int) DB::table('participants')->where('id', $participant)->value('branch_id');

        return app(ParticipantJwt::class)->issue($participant, $branch);
    }

    private function participant(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
    }

    private function sessionPublicId(
        int $participant,
        string $becameCurrentAt,
        ?string $startedAt,
        int $readingCapSeconds = 0,
    ): string {
        $publicId = (string) Str::ulid();
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-subtest-next-http-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [
                ['code' => 'SE', 'duration_seconds' => 1800, 'item_count' => 5, 'reading_cap_seconds' => $readingCapSeconds],
                ['code' => 'WA', 'duration_seconds' => 1800, 'item_count' => 5],
            ],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);

        $durationSeconds = 3600 + $readingCapSeconds;
        $endsAt = (new DateTimeImmutable('2026-09-08 03:00:00.000000+07:00'))
            ->modify("+{$durationSeconds} seconds")
            ->format('Y-m-d H:i:s.uP');
        $normalizedBecameCurrentAt = (new DateTimeImmutable($becameCurrentAt))->format('Y-m-d H:i:s.uP');
        $normalizedStartedAt = $startedAt === null ? null : (new DateTimeImmutable($startedAt))->format('Y-m-d H:i:s.uP');

        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $durationSeconds, 'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-08 03:00:00.000000+07:00', 'ends_at' => $endsAt,
            'current_segment_index' => 0,
            'current_segment_became_current_at' => $normalizedBecameCurrentAt,
            'current_segment_started_at' => $normalizedStartedAt,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ]);

        return $publicId;
    }
}
