<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentResults\ScoreAssessmentSession;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Actions\AssessmentSessions\SweepExpiredAssessmentSessions;
use App\Contracts\SealsExpiredAssessmentSessions;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * F2 (2026-09-21), scoring wired in ADR-0032 PR2 (2026-09-23). Extracted
 * sealing logic (`SealExpiredAssessmentSession`) is the exact same one
 * `AutosaveAssessmentAnswers`/`SubmitAssessmentSession` use when they
 * discover expiry incidentally -- see those tests for that side; this
 * file covers the sweep's own candidate-selection and per-session
 * isolation behavior, deliberately using `kraepelin` fixtures (skipped by
 * `ScoreAssessmentSession` entirely) so it stays about sweep mechanics, not
 * scoring -- see `SealExpiredAssessmentSessionScoringTest` for that.
 */
final class SweepExpiredAssessmentSessionsTest extends TestCase
{
    private const STARTED = '2026-09-21 00:00:00.000000+00:00';

    private const ENDS = '2026-09-21 01:00:00.000000+00:00';

    private int $attempt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }

    public function test_it_seals_only_overdue_in_progress_sessions_and_leaves_everything_else_untouched(): void
    {
        $overdue = $this->sessionRow('in_progress', self::ENDS);
        $notYetDue = $this->sessionRow('in_progress', '2026-09-21 02:00:00.000000+00:00');
        $created = $this->sessionRow('created', null);
        $submitted = $this->sessionRow('submitted', self::ENDS);
        $alreadyExpired = $this->sessionRow('expired', self::ENDS);
        $void = $this->sessionRow('void', null);

        $result = $this->sweep('2026-09-21T01:30:00.000000+00:00')->handle(200);

        $this->assertSame(['sealed' => 1, 'failed' => 0], $result);
        $this->assertSame('expired', $this->statusOf($overdue));
        $this->assertSame('in_progress', $this->statusOf($notYetDue));
        $this->assertSame('created', $this->statusOf($created));
        $this->assertSame('submitted', $this->statusOf($submitted));
        $this->assertSame('expired', $this->statusOf($alreadyExpired));
        $this->assertSame('void', $this->statusOf($void));
    }

    public function test_exactly_at_ends_at_is_not_swept_and_one_tick_after_is(): void
    {
        $atBoundary = $this->sessionRow('in_progress', self::ENDS);

        $exact = $this->sweep('2026-09-21T01:00:00.000000+00:00')->handle(200);
        $this->assertSame(['sealed' => 0, 'failed' => 0], $exact);
        $this->assertSame('in_progress', $this->statusOf($atBoundary));

        $oneTickLater = $this->sweep('2026-09-21T01:00:00.000001+00:00')->handle(200);
        $this->assertSame(['sealed' => 1, 'failed' => 0], $oneTickLater);
        $this->assertSame('expired', $this->statusOf($atBoundary));
    }

    public function test_one_sessions_failure_does_not_stop_the_others_from_being_sealed(): void
    {
        $first = $this->sessionRow('in_progress', self::ENDS);
        $poisoned = $this->sessionRow('in_progress', self::ENDS);
        $third = $this->sessionRow('in_progress', self::ENDS);
        $poisonedId = (int) DB::table('test_sessions')->where('public_id', $poisoned)->value('id');

        // A genuine, deterministic failure for exactly one candidate --
        // its own transaction rolls back, and the sweep must catch that
        // and continue to the next candidate rather than aborting the
        // batch. (Real PostgreSQL concurrent-failure evidence, a sweep
        // racing a participant's own last autosave, is a separate test.)
        $contexts = app(RlsContextRunner::class);
        $real = new SealExpiredAssessmentSession($contexts, app(ScoreAssessmentSession::class));
        $sealer = new class($real, $poisonedId) implements SealsExpiredAssessmentSessions
        {
            public function __construct(
                private readonly SealsExpiredAssessmentSessions $real,
                private readonly int $poisonedId,
            ) {}

            public function seal(int $sessionId, DateTimeImmutable $sealedAt): void
            {
                if ($sessionId === $this->poisonedId) {
                    throw new RuntimeException('Synthetic sealing failure for the isolation test.');
                }
                $this->real->seal($sessionId, $sealedAt);
            }
        };
        $sweep = new SweepExpiredAssessmentSessions(
            $contexts,
            $sealer,
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-21T01:30:00.000000+00:00'),
        );

        $result = $sweep->handle(200);

        $this->assertSame(['sealed' => 2, 'failed' => 1], $result);
        $this->assertSame('expired', $this->statusOf($first));
        $this->assertSame('in_progress', $this->statusOf($poisoned));
        $this->assertSame('expired', $this->statusOf($third));
    }

    public function test_the_limit_option_caps_how_many_candidates_are_processed_per_run(): void
    {
        $sessions = [
            $this->sessionRow('in_progress', self::ENDS),
            $this->sessionRow('in_progress', self::ENDS),
            $this->sessionRow('in_progress', self::ENDS),
        ];

        $result = $this->sweep('2026-09-21T01:30:00.000000+00:00')->handle(2);

        $this->assertSame(['sealed' => 2, 'failed' => 0], $result);
        $statuses = array_map(fn (string $id): string => $this->statusOf($id), $sessions);
        $this->assertSame(2, count(array_filter($statuses, static fn (string $status): bool => $status === 'expired')));
        $this->assertSame(1, count(array_filter($statuses, static fn (string $status): bool => $status === 'in_progress')));
    }

    public function test_it_rejects_a_non_positive_limit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->sweep('2026-09-21T01:30:00.000000+00:00')->handle(0);
    }

    private function sweep(string $iso): SweepExpiredAssessmentSessions
    {
        $contexts = app(RlsContextRunner::class);

        return new SweepExpiredAssessmentSessions(
            $contexts,
            new SealExpiredAssessmentSession($contexts, app(ScoreAssessmentSession::class)),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        );
    }

    private function statusOf(string $publicId): string
    {
        return (string) DB::table('test_sessions')->where('public_id', $publicId)->value('status');
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

    private function sessionRow(string $status, ?string $endsAt): string
    {
        // A fresh participant per call: the partial unique index allowing
        // only one created/in_progress attempt per (participant_id,
        // test_type) would otherwise collide across the multiple
        // in_progress fixtures these tests need side by side.
        $publicId = (string) Str::ulid();
        // ADR-0032 PR2 (2026-09-23): kraepelin, deliberately -- this file is
        // about the sweep's own candidate-selection and per-session
        // isolation, not scoring, and these fixtures never set up a
        // session_definition_payload. Kraepelin is the one supported
        // test_type ScoreAssessmentSession (now wired into
        // SealExpiredAssessmentSession::sealWithinTransaction()) skips
        // entirely, so sealing succeeds without a real answer set.
        $row = [
            'public_id' => $publicId, 'participant_id' => $this->participant(), 'test_type' => 'kraepelin',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => $status, 'answers_revision' => 0,
            'started_at' => null, 'ends_at' => null, 'submitted_at' => null,
            'scored_at' => null, 'expired_at' => null, 'voided_at' => null, 'void_reason' => null,
        ];

        $row = match ($status) {
            'created' => $row,
            'in_progress' => [...$row, 'started_at' => self::STARTED, 'ends_at' => $endsAt],
            'submitted' => [...$row, 'started_at' => self::STARTED, 'ends_at' => $endsAt,
                'submitted_at' => $endsAt],
            'expired' => [...$row, 'started_at' => self::STARTED, 'ends_at' => $endsAt,
                'expired_at' => '2026-09-21 01:00:01.000000+00:00'],
            'void' => [...$row, 'voided_at' => '2026-09-21 00:10:00.000000+00:00', 'void_reason' => 'synthetic'],
            default => throw new InvalidArgumentException("Unknown status: {$status}"),
        };

        DB::table('test_sessions')->insert($row);

        return $publicId;
    }
}
