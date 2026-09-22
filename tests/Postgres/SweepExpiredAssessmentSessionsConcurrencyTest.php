<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentSessions\AutosaveAssessmentAnswers;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ForkedProcessResult;
use Throwable;

/**
 * F2 (2026-09-21), Lead's explicit requirement: PostgreSQL evidence that
 * the sweep's sealing path and a participant's own last (expiry-discovering)
 * autosave never both "win" against the same session -- exactly one
 * transitions `in_progress -> expired`, the other observes the row is no
 * longer `in_progress` and fails cleanly (`SealExpiredAssessmentSession`'s
 * own `WHERE status = 'in_progress'` optimistic guard), never corrupting
 * `expired_at`/`status` into an inconsistent combination.
 *
 * Worker A runs the participant's own path (`AutosaveAssessmentAnswers`,
 * which discovers expiry internally via the deadline policy and calls
 * `SealExpiredAssessmentSession::sealWithinTransaction()`). Worker B runs
 * exactly what `SweepExpiredAssessmentSessions` calls per candidate
 * (`SealExpiredAssessmentSession::seal()`). Both target the SAME session
 * id and are released together; PostgreSQL's row lock on `test_sessions`
 * (acquired by A's `lockForUpdate()` and implicitly by B's `UPDATE`)
 * serializes them regardless of exact interleaving.
 */
final class SweepExpiredAssessmentSessionsConcurrencyTest extends TestCase
{
    /** @var array{branch:int,participant:int,session:int,public_id:string}|null */
    private ?array $committedFixture = null;

    protected function tearDown(): void
    {
        if ($this->committedFixture !== null) {
            $this->cleanupCommittedFixture($this->committedFixture);
            $this->committedFixture = null;
        }
        parent::tearDown();
    }

    public function test_the_sweep_and_the_participants_own_expiry_discovery_never_both_win(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $fixture = $this->fixture();
        $this->committedFixture = $fixture;

        $lateIso = '2026-09-08T04:00:00.000001+00:00';
        $workers = [];
        try {
            $workers[] = $this->startWorker(fn () => $this->runAutosaveWorker($fixture, $lateIso));
            $workers[] = $this->startWorker(fn () => $this->runSweepSealWorker($fixture, $lateIso));

            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }

            $results = [];
            foreach ($workers as $worker) {
                $results[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            }

            $succeeded = array_values(array_filter($results, static fn (array $result): bool => ($result['sealed'] ?? false) === true));
            $failed = array_values(array_filter($results, static fn (array $result): bool => isset($result['error'])));
            $this->assertCount(1, $succeeded, 'Exactly one worker must win the race and seal the session.');
            $this->assertCount(1, $failed, 'Exactly one worker must lose the race and fail cleanly.');
            $this->assertStringContainsString('sealed', (string) $failed[0]['error']);

            app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
                $session = DB::table('test_sessions')->where('id', $fixture['session'])->first();
                $this->assertSame('expired', $session->status);
                $this->assertNotNull($session->expired_at);
                $this->assertNull($session->submitted_at);
            });
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }

    /**
     * @param  array{branch:int,participant:int,session:int,public_id:string}  $fixture
     * @return array{pid:int,socket:resource}
     */
    private function startWorker(callable $body): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create expired-session concurrency worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 15);
            try {
                DB::purge('pgsql');
                $identity = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
                if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                    throw new RuntimeException('Worker must be runtime non-owner without RLS bypass.');
                }
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Expired-session concurrency worker start barrier timed out.');
                }
                $result = $body();
            } catch (Throwable $exception) {
                $result = ['sealed' => false, 'error' => $exception->getMessage(), 'class' => $exception::class];
            }
            ForkedProcessResult::sendAndExit($pair[1], $result, static function (): void {
                DB::disconnect('pgsql');
            });
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 15);

        return ['pid' => $pid, 'socket' => $pair[0]];
    }

    /**
     * `DEADLINE_EXCEEDED` means THIS call's own read found the session
     * still `in_progress` and it performed the seal itself (its
     * `lockForUpdate()` read and the conditional `UPDATE` inside
     * `sealWithinTransaction()` run under the same transaction/lock, so
     * that `UPDATE` can never lose to a concurrent writer once this point
     * is reached -- it always affects exactly one row). `SESSION_CLOSED`
     * means the OTHER worker had already committed the seal before this
     * one's read even happened -- a clean loss, not an error, but not a
     * seal performed by this worker either. Anything else is a genuine
     * unexpected outcome.
     *
     * @param  array{branch:int,participant:int,session:int,public_id:string}  $fixture
     * @return array{sealed: bool}
     */
    private function runAutosaveWorker(array $fixture, string $lateIso): array
    {
        $contexts = app(RlsContextRunner::class);
        $action = new AutosaveAssessmentAnswers(
            $contexts,
            new AssessmentAutosavePolicy,
            new SealExpiredAssessmentSession($contexts),
            fn (): DateTimeImmutable => new DateTimeImmutable($lateIso),
        );
        $result = $action->execute($fixture['participant'], $fixture['public_id'], (string) Str::ulid(), 1, [
            ['item_no' => 1, 'value' => 'A'],
        ]);
        if ($result->accepted) {
            throw new RuntimeException('Expected the late autosave to be rejected, not accepted.');
        }
        if ($result->errorCode === 'DEADLINE_EXCEEDED') {
            return ['sealed' => true];
        }
        if ($result->errorCode === 'SESSION_CLOSED') {
            throw new RuntimeException('Lost the race: the sweep sealed the session first.');
        }

        throw new RuntimeException("Unexpected autosave outcome: {$result->errorCode}");
    }

    /**
     * @param  array{branch:int,participant:int,session:int,public_id:string}  $fixture
     * @return array{sealed: bool}
     */
    private function runSweepSealWorker(array $fixture, string $lateIso): array
    {
        $contexts = app(RlsContextRunner::class);
        $sealer = new SealExpiredAssessmentSession($contexts);
        $sealer->seal($fixture['session'], new DateTimeImmutable($lateIso));

        return ['sealed' => true];
    }

    /** @return array{branch:int,participant:int,session:int,public_id:string} */
    private function fixture(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'Sweep Concurrency Synthetic',
                'organization_code' => $key, 'display_name' => 'Sweep Concurrency Synthetic',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'full_name' => 'Sweep Concurrency Synthetic',
                'phone' => '620000000000',
            ]);
            $definitionSource = [
                'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
                'provenance' => 'sweep-concurrency-test-only', 'total_duration_seconds' => 3600,
                'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 5]],
                'randomization' => 'fixed', 'seed' => null, 'generator' => null,
            ];
            $definition = SessionDefinition::fromArray([
                ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
            ]);
            $publicId = (string) Str::ulid();
            $session = DB::table('test_sessions')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant,
                'test_type' => 'ist', 'attempt_no' => 1,
                'authorization_id' => (string) Str::ulid(),
                'allocation_intent_id' => (string) Str::ulid(),
                'duration_seconds' => 3600, 'status' => 'in_progress', 'answers_revision' => 0,
                'started_at' => '2026-09-08 03:00:00.000000+00',
                'ends_at' => '2026-09-08 04:00:00.000000+00',
                'session_definition_version' => $definition->version,
                'session_definition_provenance' => $definition->provenance,
                'session_definition_checksum' => $definition->checksum,
                'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            ]);

            return compact('branch', 'participant', 'session', 'publicId') + ['public_id' => $publicId];
        });
    }

    /** @param array{branch:int,participant:int,session:int,public_id:string} $fixture */
    private function cleanupCommittedFixture(array $fixture): void
    {
        $runId = getenv('ORG_TEST_RUN_ID');
        $database = DB::selectOne(<<<'SQL'
            SELECT shobj_description(oid, 'pg_database') AS marker
            FROM pg_database
            WHERE datname = current_database()
            SQL);
        if (! is_string($runId) || $runId === '' || $database?->marker !== "ONCAM_ORG_TEST:{$runId}") {
            throw new RuntimeException('Sweep concurrency cleanup requires the marked disposable database.');
        }

        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.sweep_concurrency_cleanup', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        $owner = DB::connection('sweep_concurrency_cleanup');

        try {
            $owner->transaction(function () use ($owner, $fixture): void {
                $owner->statement("SET LOCAL session_replication_role = 'replica'");
                $owner->table('assessment_autosave_mutations')->where('session_id', $fixture['session'])->delete();
                $owner->table('answers')->where('session_id', $fixture['session'])->delete();
                $owner->table('test_sessions')->where('id', $fixture['session'])->delete();
                $owner->table('participants')->where('id', $fixture['participant'])->delete();
                $owner->table('branches')->where('id', $fixture['branch'])->delete();
            });
        } finally {
            DB::purge('sweep_concurrency_cleanup');
            config()->set('database.connections.sweep_concurrency_cleanup', null);
        }
    }
}
