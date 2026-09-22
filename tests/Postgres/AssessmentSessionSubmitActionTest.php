<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentResults\ScoreAssessmentSession;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Actions\AssessmentSessions\SubmitAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentSessionSubmitPolicy;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/** PostgreSQL-authoritative submit action evidence on the non-owner runtime role. */
final class AssessmentSessionSubmitActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_exact_deadline_submits_and_one_microsecond_late_expires_under_service_rls(): void
    {
        $identity = DB::selectOne(
            'SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
        );
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);

        // ADR-0032 (2026-09-22): kraepelin -- this fixture never sets a
        // session_definition_payload, so any instrument ScoreAssessmentSession
        // would actually try to score fails closed. Kraepelin is skipped
        // entirely by ScoreAssessmentSession (its own pipeline, out of
        // ADR-0032's scope), so this test's own concern -- submit's RLS role
        // and exact-deadline acceptance -- stays isolated from scoring.
        $exact = $this->fixture('in_progress', 7, 'kraepelin');
        $observedRole = null;
        $accepted = $this->action(function () use (&$observedRole): DateTimeImmutable {
            $observedRole = app(RlsContextRunner::class)->current()?->role;

            return new DateTimeImmutable('2026-09-08T04:00:00.000000+07:00');
        })->execute($exact['participant'], $exact['public_id']);

        $this->assertTrue($accepted->accepted);
        $this->assertFalse($accepted->replayed);
        $this->assertSame('service', $observedRole);
        $this->assertSame('submitted', $accepted->status);
        $this->assertSame(7, $accepted->answersRevision);

        // ADR-0032 PR2 (2026-09-23): kraepelin -- this one actually reaches
        // the expiry seal (now wired to score), same reasoning as the
        // $exact fixture above.
        $late = $this->fixture('in_progress', 3, 'kraepelin');
        $expired = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:00:00.000001+07:00'))
            ->execute($late['participant'], $late['public_id']);
        $this->assertFalse($expired->accepted);
        $this->assertSame('DEADLINE_EXCEEDED', $expired->errorCode);
        $this->assertSame('expired', $expired->status);

        app(RlsContextRunner::class)->runAsService(function () use ($exact, $late): void {
            $this->assertTrue((bool) DB::selectOne(
                'SELECT submitted_at = ?::timestamptz AS exact FROM test_sessions WHERE id = ?',
                ['2026-09-08 04:00:00.000000+07', $exact['session']],
            )->exact);
            $this->assertSame('submitted', DB::table('test_sessions')->where('id', $exact['session'])->value('status'));
            $this->assertSame('expired', DB::table('test_sessions')->where('id', $late['session'])->value('status'));
            $this->assertNull(DB::table('test_sessions')->where('id', $late['session'])->value('submitted_at'));
        });
    }

    public function test_submitted_and_scored_replay_without_persisted_drift(): void
    {
        foreach (['submitted', 'scored'] as $status) {
            $fixture = $this->fixture($status, 9);
            $before = app(RlsContextRunner::class)->runAsService(fn (): object => DB::table('test_sessions')
                ->where('id', $fixture['session'])->select('status', 'submitted_at', 'updated_at')->sole());

            $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-09T12:00:00+07:00'))
                ->execute($fixture['participant'], $fixture['public_id']);
            $after = app(RlsContextRunner::class)->runAsService(fn (): object => DB::table('test_sessions')
                ->where('id', $fixture['session'])->select('status', 'submitted_at', 'updated_at')->sole());

            $this->assertTrue($result->accepted);
            $this->assertTrue($result->replayed);
            $this->assertSame($status, $result->status);
            $this->assertEquals($before, $after);
        }
    }

    private function action(callable $clock): SubmitAssessmentSession
    {
        return new SubmitAssessmentSession(
            app(RlsContextRunner::class),
            new AssessmentSessionSubmitPolicy,
            new SealExpiredAssessmentSession(app(RlsContextRunner::class), app(ScoreAssessmentSession::class)),
            app(ScoreAssessmentSession::class),
            $clock(...),
        );
    }

    /** @return array{participant:int,session:int,public_id:string} */
    private function fixture(string $status, int $revision, string $testType = 'ist'): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($status, $revision, $testType): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'Submit Synthetic',
                'organization_code' => $key, 'display_name' => 'Submit Synthetic',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'full_name' => 'Submit Synthetic',
                'phone' => '620000000000',
            ]);
            $publicId = (string) Str::ulid();
            $session = DB::table('test_sessions')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => $testType,
                'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
                'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
                'status' => $status, 'answers_revision' => $revision,
                'started_at' => '2026-09-08 03:00:00.000000+07',
                'ends_at' => '2026-09-08 04:00:00.000000+07',
                'submitted_at' => in_array($status, ['submitted', 'scored'], true)
                    ? '2026-09-08 03:59:00.654321+07' : null,
                'scored_at' => $status === 'scored' ? '2026-09-08 04:00:01.000000+07' : null,
            ]);

            return ['participant' => $participant, 'session' => $session, 'public_id' => $publicId];
        });
    }
}
