<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentResults\ScoreAssessmentSession;
use App\Actions\AssessmentSessions\AutosaveAssessmentAnswers;
use App\Actions\AssessmentSessions\GetAssessmentSession;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Actions\AssessmentSessions\SubmitAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionSubmitPolicy;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantJwt;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

/**
 * F2 session-http (2026-09-21). HTTP-level coverage for
 * GET /sessions/{id}, POST /sessions/{id}/answers, POST /sessions/{id}/submit.
 * Plain OrganizationPaymentTestCase (not DatabaseTruncation): unlike the
 * start command, none of GetAssessmentSession/AutosaveAssessmentAnswers/
 * SubmitAssessmentSession asserts DB::transactionLevel() === 0 -- each just
 * calls RlsContextRunner::runAsService() directly, which is compatible with
 * RefreshDatabase's own wrapping transaction (confirmed by reading
 * RlsContextRunner::runAsService() and by the existing
 * AutosaveAssessmentAnswersTest.php action-level suite, which already runs
 * under this same base class).
 */
final class AssessmentSessionHttpTest extends OrganizationPaymentTestCase
{
    // Space-separated, matching AutosaveAssessmentAnswers::timestamp()'s
    // 'Y-m-d H:i:s.uP' format exactly: the SQLite lifecycle trigger compares
    // expired_at/ends_at as raw TEXT, so a fixture written with the ISO 'T'
    // separator sorts differently than a runtime-written space-separated
    // value even when both parse to the same instant, and the contract
    // check fails on a false mismatch.
    private const STARTED = '2026-09-21 00:00:00.000000+00:00';

    private const ENDS = '2026-09-21 01:00:00.000000+00:00';

    // Comfortably inside every fixture's [STARTED, ENDS] window. Bound by
    // default in setUp() for all three actions so a test that isn't
    // specifically about deadline/clock behaviour doesn't silently depend on
    // the real wall clock (which, run after ENDS, would turn every such test
    // into a false DEADLINE_EXCEEDED). Tests that need a different instant
    // (the exact-deadline pair, the remaining_seconds-clamp test) rebind
    // their own clock and override this default.
    private const DEFAULT_NOW = '2026-09-21T00:30:00.000000+00:00';

    private int $attempt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
        $this->bindGetClock(self::DEFAULT_NOW);
        $this->bindAutosaveClock(self::DEFAULT_NOW);
        $this->bindSubmitClock(self::DEFAULT_NOW);
    }

    // -- GET /sessions/{id} -------------------------------------------------

    public function test_get_returns_200_with_the_full_shape_for_an_in_progress_session(): void
    {
        $this->bindGetClock('2026-09-21T00:30:00.000000+00:00');
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $response = $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}")->assertOk();

        $response->assertJsonPath('session_id', $session)
            ->assertJsonPath('test_type', 'ist')
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('submitted_at', null)
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('remaining_seconds', 1800)
            ->assertJsonPath('answers_revision', 0);
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function sessionStates(): iterable
    {
        // [status, expected remaining_seconds at 00:30 (halfway through the
        // window), expected submitted_at is present]
        yield 'created' => ['created', 0, false];
        yield 'in_progress' => ['in_progress', 1800, false];
        yield 'submitted' => ['submitted', 0, true];
        yield 'scored' => ['scored', 0, true];
        yield 'expired' => ['expired', 0, false];
        yield 'void' => ['void', 0, false];
    }

    #[DataProvider('sessionStates')]
    public function test_get_represents_all_six_states_with_correct_remaining_seconds_and_submitted_at(
        string $status,
        int $expectedRemainingSeconds,
        bool $expectSubmittedAt,
    ): void {
        $this->bindGetClock('2026-09-21T00:30:00.000000+00:00');
        $participant = $this->participant();
        $session = $this->sessionRow($participant, $status);

        $response = $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}")->assertOk();

        $response->assertJsonPath('status', $status)
            ->assertJsonPath('remaining_seconds', $expectedRemainingSeconds);
        $this->assertSame($expectSubmittedAt, $response->json('submitted_at') !== null);
    }

    public function test_get_remaining_seconds_is_never_negative_past_the_deadline(): void
    {
        // API_CONTRACT.md line 98: remaining_seconds never negative. A session
        // still row-level 'in_progress' (not yet swept to 'expired' by a
        // write) read after its own ends_at must clamp to 0, not go negative.
        $this->bindGetClock('2026-09-21T02:00:00.000000+00:00');
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}")
            ->assertOk()
            ->assertJsonPath('remaining_seconds', 0);
    }

    // ─── stage 5 (2026-09-22): current_segment ───

    public function test_get_current_segment_is_null_for_a_session_that_never_started(): void
    {
        $this->bindGetClock('2026-09-21T00:30:00.000000+00:00');
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'created');

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}")
            ->assertOk()
            ->assertJsonPath('current_segment', null);
    }

    public function test_get_current_segment_reports_the_single_implicit_segment_for_an_untouched_subtest(): void
    {
        $this->bindGetClock('2026-09-21T00:30:00.000000+00:00');
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $response = $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}")->assertOk();

        $response->assertJsonPath('current_segment.code', 'SYN')
            ->assertJsonPath('current_segment.index', 0)
            ->assertJsonPath('current_segment.started_at', '2026-09-21T00:00:00.000000Z')
            ->assertJsonPath('current_segment.remaining_seconds', 1800);
    }

    /**
     * A segment waiting out its own reading gap reports started_at/ends_at/
     * remaining_seconds together as null - a real, observable state, not
     * an error (tasks/handoffs/f2/timed-segments-plan.md's response-shape
     * section).
     */
    public function test_get_current_segment_reports_the_null_triple_while_waiting_on_a_reading_gap(): void
    {
        $this->bindGetClock('2026-09-21T00:00:30.000000+00:00');
        $participant = $this->participant();
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'session-http-reading-gap-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 5, 'reading_cap_seconds' => 60]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $publicId = (string) Str::ulid();
        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3660, 'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => self::STARTED,
            'ends_at' => '2026-09-21 01:01:00.000000+00:00',
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ]);

        $response = $this->withToken($this->token($participant))->getJson("/api/sessions/{$publicId}")->assertOk();

        $response->assertJsonPath('current_segment.index', 0)
            ->assertJsonPath('current_segment.started_at', null)
            ->assertJsonPath('current_segment.ends_at', null)
            ->assertJsonPath('current_segment.remaining_seconds', null);
    }

    public function test_get_nonexistent_and_foreign_session_are_byte_identical_404(): void
    {
        $owner = $this->participant();
        $stranger = $this->participant();
        $session = $this->sessionRow($owner, 'in_progress');

        $foreign = $this->withToken($this->token($stranger))->getJson("/api/sessions/{$session}");
        $nonexistent = $this->withToken($this->token($stranger))->getJson('/api/sessions/'.Str::ulid());

        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame(404, $nonexistent->getStatusCode());
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
        $this->assertSame($this->stableHeaders($foreign), $this->stableHeaders($nonexistent));
        $foreign->assertJsonPath('error.code', 'SESSION_NOT_FOUND');
        $foreign->assertJsonPath('error.details', null);
    }

    // -- POST /sessions/{id}/answers -----------------------------------------

    public function test_autosave_accepts_a_valid_batch_and_returns_the_receipt(): void
    {
        $this->bindAutosaveClock('2026-09-21T00:30:00.000000+00:00');
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $mutation = (string) Str::ulid();

        $response = $this->withToken($this->token($participant))
            ->postJson("/api/sessions/{$session}/answers", [
                'mutation_id' => $mutation,
                'revision' => 1,
                'items' => [['item_no' => 1, 'value' => 'A']],
            ])
            ->assertOk();

        $response->assertJsonPath('session_id', $session)
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('answers_revision', 1)
            ->assertJsonPath('accepted_item_numbers', [1]);
        $this->assertDatabaseHas('test_sessions', ['public_id' => $session, 'answers_revision' => 1]);
    }

    /** @return iterable<string, array{string, array<string, mixed>, int, string}> */
    public static function autosaveRejections(): iterable
    {
        yield 'session not started' => [
            'created', ['mutation_id' => null, 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']]], 409, 'SESSION_NOT_STARTED',
        ];
        yield 'session closed (already submitted)' => [
            'submitted', ['mutation_id' => null, 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']]], 409, 'SESSION_CLOSED',
        ];
        yield 'revision gap' => [
            'in_progress', ['mutation_id' => null, 'revision' => 2, 'items' => [['item_no' => 1, 'value' => 'A']]], 409, 'AUTOSAVE_REVISION_GAP',
        ];
    }

    #[DataProvider('autosaveRejections')]
    public function test_autosave_error_codes_map_to_the_correct_http_status(
        string $status,
        array $payload,
        int $expectedStatus,
        string $expectedCode,
    ): void {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, $status);
        $payload['mutation_id'] = (string) Str::ulid();

        $this->withToken($this->token($participant))
            ->postJson("/api/sessions/{$session}/answers", $payload)
            ->assertStatus($expectedStatus)
            ->assertJsonPath('error.code', $expectedCode);
    }

    public function test_autosave_stale_revision_and_mutation_payload_mismatch_are_409(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $mutation = (string) Str::ulid();
        $token = $this->token($participant);
        $this->withToken($token)->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => $mutation, 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']],
        ])->assertOk();

        $this->withToken($token)->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']],
        ])->assertStatus(409)->assertJsonPath('error.code', 'AUTOSAVE_STALE_REVISION');

        $this->withToken($token)->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => $mutation, 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'B']],
        ])->assertStatus(409)->assertJsonPath('error.code', 'MUTATION_PAYLOAD_MISMATCH');
    }

    public function test_autosave_invalid_batch_is_422(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => [],
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ANSWER_BATCH');
    }

    public function test_autosave_nonexistent_and_foreign_session_are_byte_identical_404(): void
    {
        $owner = $this->participant();
        $stranger = $this->participant();
        $session = $this->sessionRow($owner, 'in_progress');
        $payload = ['mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']]];
        $token = $this->token($stranger);

        $foreign = $this->withToken($token)->postJson("/api/sessions/{$session}/answers", $payload);
        $nonexistent = $this->withToken($token)->postJson('/api/sessions/'.Str::ulid().'/answers', $payload);

        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
        $this->assertSame($this->stableHeaders($foreign), $this->stableHeaders($nonexistent));
        $foreign->assertJsonPath('error.details', null);
        $this->assertSame(0, DB::table('answers')->count());
    }

    public function test_autosave_exact_deadline_is_accepted_and_one_tick_after_expires(): void
    {
        $this->bindAutosaveClock(self::ENDS);
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']],
        ])->assertOk();

        $this->bindAutosaveClock('2026-09-21T01:00:00.000001+00:00');
        $lateParticipant = $this->participant();
        // ADR-0032 PR2 (2026-09-23): kraepelin -- this session actually
        // reaches the expiry seal (now wired to score), and this fixture
        // has no assessment_case_id for a real instrument to score against.
        $lateSession = $this->sessionRow($lateParticipant, 'in_progress', testType: 'kraepelin');
        $this->withToken($this->token($lateParticipant))->postJson("/api/sessions/{$lateSession}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']],
        ])->assertStatus(409)->assertJsonPath('error.code', 'DEADLINE_EXCEEDED');
        $this->assertDatabaseHas('test_sessions', ['public_id' => $lateSession, 'status' => 'expired']);
    }

    public function test_autosave_revision_gap_details_report_only_own_session_and_request_revisions(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $response = $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 5, 'items' => [['item_no' => 1, 'value' => 'A']],
        ])->assertStatus(409);

        $response->assertJsonPath('error.details.current_revision', 0)
            ->assertJsonPath('error.details.proposed_revision', 5);
    }

    public function test_autosave_item_no_beyond_session_bound_is_422_through_http(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress', itemCount: 2);

        $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => [['item_no' => 3, 'value' => 'A']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ANSWER_BATCH');
        $this->assertSame(0, DB::table('answers')->count());
    }

    public function test_autosave_accepts_the_rmib_shaped_shallow_object_value_end_to_end(): void
    {
        // The only currently-tested nested-object value shape
        // (AssessmentAutosavePolicyTest, AssessmentSessionAutosaveActionTest)
        // -- {"rank": N} -- must still reach the domain layer through the new
        // FormRequest transport guard, not be rejected as "not scalar".
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => [['item_no' => 1, 'value' => ['rank' => 3]]],
        ])->assertOk();
        $this->assertSame('{"rank":3}', DB::table('answers')->where('item_no', 1)->value('value'));
    }

    public function test_autosave_rejects_a_value_nested_deeper_than_one_level(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1,
            'items' => [['item_no' => 1, 'value' => ['nested' => ['score' => 1]]]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ANSWER_BATCH');
        $this->assertSame(0, DB::table('answers')->count());
    }

    public function test_autosave_value_string_length_boundary(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $token = $this->token($participant);

        $this->withToken($token)->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1,
            'items' => [['item_no' => 1, 'value' => str_repeat('a', 64)]],
        ])->assertOk();

        $this->withToken($token)->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 2,
            'items' => [['item_no' => 1, 'value' => str_repeat('a', 65)]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ANSWER_BATCH');
    }

    public function test_autosave_items_count_boundary_at_300(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress', itemCount: 301);
        $token = $this->token($participant);

        $atLimit = array_map(fn (int $n): array => ['item_no' => $n, 'value' => 'A'], range(1, 300));
        $this->withToken($token)->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => $atLimit,
        ])->assertOk();

        $overLimit = array_map(fn (int $n): array => ['item_no' => $n, 'value' => 'A'], range(1, 301));
        $this->withToken($token)->postJson("/api/sessions/{$session}/answers", [
            'mutation_id' => (string) Str::ulid(), 'revision' => 2, 'items' => $overLimit,
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ANSWER_BATCH');
    }

    // -- POST /sessions/{id}/submit ------------------------------------------

    public function test_submit_accepts_and_returns_the_stable_shape(): void
    {
        $this->bindSubmitClock('2026-09-21T00:30:00.000000+00:00');
        $participant = $this->participant();
        // ADR-0032 (2026-09-22): kraepelin, deliberately -- this test is about
        // the submit endpoint's own response shape, not scoring, and this
        // fixture never inserts any answers (answers_revision stays 0,
        // which LoadSealedGenericAnswerSet always rejects regardless of
        // instrument). Kraepelin is the one supported test_type
        // ScoreAssessmentSession skips, so submit succeeds without needing
        // a real answer set.
        $session = $this->sessionRow($participant, 'in_progress', testType: 'kraepelin');

        $response = $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/submit")->assertOk();

        $response->assertJsonPath('session_id', $session)
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('answers_revision', 0);
        $this->assertNotNull($response->json('submitted_at'));
        $this->assertDatabaseHas('test_sessions', ['public_id' => $session, 'status' => 'submitted']);
    }

    public function test_submit_is_safely_repeatable_without_reopening_answers(): void
    {
        $participant = $this->participant();
        // ADR-0032 (2026-09-22): kraepelin -- see the previous test's comment.
        $session = $this->sessionRow($participant, 'in_progress', testType: 'kraepelin');
        $token = $this->token($participant);

        $first = $this->withToken($token)->postJson("/api/sessions/{$session}/submit")->assertOk();
        $second = $this->withToken($token)->postJson("/api/sessions/{$session}/submit")->assertOk();

        $this->assertSame($first->json('submitted_at'), $second->json('submitted_at'));
        $this->assertSame(1, DB::table('test_sessions')->where('public_id', $session)->where('status', 'submitted')->count());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function submitRejections(): iterable
    {
        yield 'not started' => ['created', 409, 'SESSION_NOT_STARTED'];
        yield 'already void' => ['void', 409, 'SESSION_CLOSED'];
    }

    #[DataProvider('submitRejections')]
    public function test_submit_error_codes_map_to_the_correct_http_status(string $status, int $expectedStatus, string $expectedCode): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, $status);

        $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/submit")
            ->assertStatus($expectedStatus)
            ->assertJsonPath('error.code', $expectedCode);
    }

    public function test_submit_deadline_exceeded_is_409_and_expires_the_session(): void
    {
        $this->bindSubmitClock('2026-09-21T01:00:00.000001+00:00');
        $participant = $this->participant();
        // ADR-0032 PR2 (2026-09-23): kraepelin -- see the autosave test
        // above's identical comment.
        $session = $this->sessionRow($participant, 'in_progress', testType: 'kraepelin');

        $this->withToken($this->token($participant))->postJson("/api/sessions/{$session}/submit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DEADLINE_EXCEEDED');
        $this->assertDatabaseHas('test_sessions', ['public_id' => $session, 'status' => 'expired']);
    }

    public function test_submit_nonexistent_and_foreign_session_are_byte_identical_404(): void
    {
        $owner = $this->participant();
        $stranger = $this->participant();
        $session = $this->sessionRow($owner, 'in_progress');
        $token = $this->token($stranger);

        $foreign = $this->withToken($token)->postJson("/api/sessions/{$session}/submit");
        $nonexistent = $this->withToken($token)->postJson('/api/sessions/'.Str::ulid().'/submit');

        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
        $this->assertSame($this->stableHeaders($foreign), $this->stableHeaders($nonexistent));
        $this->assertDatabaseHas('test_sessions', ['public_id' => $session, 'status' => 'in_progress']);
    }

    // -- helpers --------------------------------------------------------------

    private function bindGetClock(string $iso): void
    {
        $this->app->instance(GetAssessmentSession::class, new GetAssessmentSession(
            $this->app->make(RlsContextRunner::class),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        ));
    }

    private function bindAutosaveClock(string $iso): void
    {
        $this->app->instance(AutosaveAssessmentAnswers::class, new AutosaveAssessmentAnswers(
            $this->app->make(RlsContextRunner::class),
            new AssessmentAutosavePolicy,
            new SealExpiredAssessmentSession(
                $this->app->make(RlsContextRunner::class),
                $this->app->make(ScoreAssessmentSession::class),
            ),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        ));
    }

    private function bindSubmitClock(string $iso): void
    {
        $this->app->instance(SubmitAssessmentSession::class, new SubmitAssessmentSession(
            $this->app->make(RlsContextRunner::class),
            new AssessmentSessionSubmitPolicy,
            new SealExpiredAssessmentSession(
                $this->app->make(RlsContextRunner::class),
                $this->app->make(ScoreAssessmentSession::class),
            ),
            $this->app->make(ScoreAssessmentSession::class),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        ));
    }

    /** @return array<string, list<string>> */
    private function stableHeaders(TestResponse $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date']);

        return $headers;
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

    private function sessionRow(int $participant, string $status, int $itemCount = 5, string $testType = 'ist'): string
    {
        $publicId = (string) Str::ulid();
        // ADR-0032 (2026-09-22): kraepelin's SessionDefinition validation is
        // rigid (fixed 50-column/27-slot/15s matrix, see SessionDefinition's
        // kraepelinConfiguration()) -- it cannot share the generic
        // itemCount/3600s shape every other test_type here uses, so it gets
        // its own fixed constants instead.
        $totalDurationSeconds = $testType === 'kraepelin' ? 750 : 3600;
        $definitionSource = [
            'instrument' => $testType, 'version' => 'synthetic-definition-v1',
            'provenance' => 'session-http-test-only', 'total_duration_seconds' => $totalDurationSeconds,
            'subtests' => $testType === 'kraepelin'
                ? [['code' => 'K', 'duration_seconds' => 750, 'item_count' => 1350]]
                : [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => $itemCount]],
            'randomization' => 'fixed', 'seed' => null,
            'generator' => $testType === 'kraepelin' ? [
                'algorithm' => 'synthetic-generator', 'version' => 'synthetic-v1',
                'columns' => 50, 'seconds_per_column' => 15,
                'numbers_per_column' => 28, 'answer_slots_per_column' => 27,
            ] : null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);

        $row = [
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => $testType,
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $totalDurationSeconds, 'status' => $status, 'answers_revision' => 0,
            'started_at' => null, 'ends_at' => null, 'submitted_at' => null,
            'scored_at' => null, 'expired_at' => null, 'voided_at' => null, 'void_reason' => null,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ];

        $row = match ($status) {
            'created' => $row,
            'in_progress' => [...$row, 'started_at' => self::STARTED, 'ends_at' => self::ENDS],
            'submitted' => [...$row, 'started_at' => self::STARTED, 'ends_at' => self::ENDS,
                'submitted_at' => '2026-09-21 00:30:00.000000+00:00'],
            'scored' => [...$row, 'started_at' => self::STARTED, 'ends_at' => self::ENDS,
                'submitted_at' => '2026-09-21 00:30:00.000000+00:00', 'scored_at' => '2026-09-21 00:31:00.000000+00:00'],
            'expired' => [...$row, 'started_at' => self::STARTED, 'ends_at' => self::ENDS,
                'expired_at' => '2026-09-21 01:00:01.000000+00:00'],
            'void' => [...$row, 'voided_at' => '2026-09-21 00:10:00.000000+00:00', 'void_reason' => 'synthetic'],
            default => throw new InvalidArgumentException("Unknown status: {$status}"),
        };

        DB::table('test_sessions')->insert($row);

        return $publicId;
    }
}
