<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\AutosaveAssessmentAnswers;
use App\Actions\AssessmentSessions\GetAssessmentSessionAnswers;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantJwt;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

/**
 * F2 session-answers-readback (2026-09-21). HTTP-level coverage for
 * GET /sessions/{id}/answers. Plain OrganizationPaymentTestCase, same
 * reasoning as AssessmentSessionHttpTest.php: GetAssessmentSessionAnswers
 * has no assertCleanOuterBoundary(), just runAsService(), compatible with
 * RefreshDatabase's own wrapping transaction.
 */
final class AssessmentSessionAnswersReadbackTest extends OrganizationPaymentTestCase
{
    private const STARTED = '2026-09-21 00:00:00.000000+00:00';

    private const ENDS = '2026-09-21 01:00:00.000000+00:00';

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
        $this->bindClock(self::DEFAULT_NOW);
    }

    public function test_readback_returns_the_stored_answers_and_matching_revision(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $this->autosave($participant, $session, 1, [
            ['item_no' => 2, 'value' => 'A'],
            ['item_no' => 1, 'value' => ['rank' => 3]],
        ]);

        $response = $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/answers")->assertOk();

        $response->assertExactJson([
            'session_id' => $session,
            'answers_revision' => 1,
            'answers' => [
                ['item_no' => 1, 'value' => ['rank' => 3]],
                ['item_no' => 2, 'value' => 'A'],
            ],
        ]);
    }

    public function test_readback_returns_an_empty_list_for_an_in_progress_session_with_no_autosaves_yet(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/answers")
            ->assertOk()
            ->assertExactJson(['session_id' => $session, 'answers_revision' => 0, 'answers' => []]);
    }

    public function test_readback_created_session_is_409_session_not_started(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'created');

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/answers")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_NOT_STARTED');
    }

    /** @return iterable<string, array{string}> */
    public static function closedStatuses(): iterable
    {
        yield 'submitted' => ['submitted'];
        yield 'scored' => ['scored'];
        yield 'expired' => ['expired'];
        yield 'void' => ['void'];
    }

    #[DataProvider('closedStatuses')]
    public function test_readback_closed_sessions_are_409_session_closed(string $status): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, $status);

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/answers")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_CLOSED');
    }

    public function test_readback_exact_deadline_is_readable_and_one_tick_after_is_deadline_exceeded_without_side_effects(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $this->autosave($participant, $session, 1, [['item_no' => 1, 'value' => 'A']]);

        $this->bindClock(self::ENDS);
        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/answers")
            ->assertOk()
            ->assertJsonPath('answers_revision', 1);

        $beforeStatus = DB::table('test_sessions')->where('public_id', $session)->select('status', 'expired_at')->first();

        $this->bindClock('2026-09-21T01:00:00.000001+00:00');
        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/answers")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DEADLINE_EXCEEDED');

        // Reading past the deadline must never write -- expiry sealing stays
        // the write path's job (AutosaveAssessmentAnswers/SubmitAssessmentSession).
        $afterStatus = DB::table('test_sessions')->where('public_id', $session)->select('status', 'expired_at')->first();
        $this->assertEquals($beforeStatus, $afterStatus);
        $this->assertSame('in_progress', $afterStatus->status);
        $this->assertNull($afterStatus->expired_at);
        $sessionId = (int) DB::table('test_sessions')->where('public_id', $session)->value('id');
        $this->assertSame(1, DB::table('answers')->where('session_id', $sessionId)->count());
    }

    public function test_readback_nonexistent_and_foreign_session_are_byte_identical_404(): void
    {
        $owner = $this->participant();
        $stranger = $this->participant();
        $session = $this->sessionRow($owner, 'in_progress');
        $token = $this->token($stranger);

        $foreign = $this->withToken($token)->getJson("/api/sessions/{$session}/answers");
        $nonexistent = $this->withToken($token)->getJson('/api/sessions/'.Str::ulid().'/answers');

        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame(404, $nonexistent->getStatusCode());
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
        $foreignHeaders = $foreign->headers->all();
        $nonexistentHeaders = $nonexistent->headers->all();
        unset($foreignHeaders['date'], $nonexistentHeaders['date']);
        $this->assertSame($foreignHeaders, $nonexistentHeaders);
        $foreign->assertJsonPath('error.code', 'SESSION_NOT_FOUND');
        $foreign->assertJsonPath('error.details', null);
    }

    public function test_readback_never_exposes_a_dass21_row_planted_in_the_generic_table(): void
    {
        DB::unprepared('DROP TRIGGER test_sessions_contract_insert');
        $participant = $this->participant();
        $session = (string) Str::ulid();
        DB::table('test_sessions')->insert([
            'public_id' => $session, 'participant_id' => $participant, 'test_type' => 'dass21',
            'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => self::STARTED, 'ends_at' => self::ENDS,
        ]);

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/answers")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'SESSION_NOT_FOUND');
    }

    public function test_readback_response_is_a_strict_whitelist(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $this->autosave($participant, $session, 1, [['item_no' => 1, 'value' => 'A']]);

        $response = $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/answers")->assertOk();

        $this->assertSame(['session_id', 'answers_revision', 'answers'], array_keys($response->json()));
        $this->assertSame(['item_no', 'value'], array_keys($response->json('answers.0')));
    }

    private function bindClock(string $iso): void
    {
        $this->app->instance(GetAssessmentSessionAnswers::class, new GetAssessmentSessionAnswers(
            $this->app->make(RlsContextRunner::class),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        ));
    }

    /**
     * Fixtures go through the real action, not a direct table write: the
     * test_sessions_identity_revision_guard trigger requires every
     * answers_revision bump to be backed by a matching
     * assessment_autosave_mutations ledger row, exactly as production
     * writes it. A hand-crafted revision bump violates that contract.
     *
     * @param  list<array{item_no: int, value: mixed}>  $items
     */
    private function autosave(int $participant, string $sessionPublicId, int $revision, array $items): void
    {
        $action = new AutosaveAssessmentAnswers(
            $this->app->make(RlsContextRunner::class),
            new AssessmentAutosavePolicy,
            new SealExpiredAssessmentSession($this->app->make(RlsContextRunner::class)),
            fn (): DateTimeImmutable => new DateTimeImmutable(self::DEFAULT_NOW),
        );
        $result = $action->execute($participant, $sessionPublicId, (string) Str::ulid(), $revision, $items);
        if (! $result->accepted) {
            throw new RuntimeException("Fixture autosave was rejected: {$result->errorCode}");
        }
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

    private function sessionRow(int $participant, string $status): string
    {
        $publicId = (string) Str::ulid();
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'answers-readback-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 5]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);

        $row = [
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => $status, 'answers_revision' => 0,
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
