<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentSessions\SubtestNext;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\TimedSegmentTransitionPolicy;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * F2 timed-segments stage 4 (2026-09-22). Real-PostgreSQL evidence for
 * Lead's explicit requirement: two genuinely concurrent SubtestNext calls
 * for the SAME session must serialize on the row lock (lockForUpdate),
 * not both read the same stored segment state independently - proven the
 * same way tests/Postgres/StartParticipantAssessmentSessionConcurrencyTest.php
 * proves it for session start: two forked processes, and the SAME
 * injectable-clock pause technique that test already established
 * (SubtestNext's own constructor accepts a clock closure, called AFTER the
 * row is locked - pausing there via a socket barrier holds the lock open
 * without any change to SubtestNext itself, and pg_stat_activity.
 * wait_event_type='Lock' on the second backend proves it is genuinely
 * blocked at the database level, not just "happened to run after").
 *
 * SQLite's grammar compiles lockForUpdate() to an empty string (see
 * tests/Feature/AssessmentSessions/SubtestNextTest.php's own docblock on
 * why a single-connection race can't be simulated meaningfully there), so
 * this is the only place this property can actually be shown.
 */
final class SubtestNextConcurrencyTest extends TestCase
{
    /** @var array{branch: int, participant: int, session: int}|null */
    private ?array $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->cleanupFixture($this->fixture);
        }
        parent::tearDown();
    }

    public function test_two_concurrent_calls_for_the_same_session_serialize_and_advance_exactly_once(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Timed-segments concurrency evidence requires pcntl; never skip.');
        DB::purge('pgsql');

        $this->fixture = $this->sessionFixture();
        $sessionPublicId = app(RlsContextRunner::class)->runAsService(
            fn (): string => (string) DB::table('test_sessions')->where('id', $this->fixture['session'])->value('public_id'),
        );

        $holder = $this->startWorker($this->fixture['participant'], $sessionPublicId);
        try {
            $waiter = $this->startWorker($this->fixture['participant'], $sessionPublicId);
            try {
                fwrite($holder['socket'], "go\n");
                $this->assertSame('locked', $this->readEvent($holder['socket'])['event']);

                fwrite($waiter['socket'], "go\n");
                $this->assertBackendWaitsOnLock($waiter['backend']);

                fwrite($holder['socket'], "release\n");
                $winner = $this->readEvent($holder['socket'], 'result');

                // The waiter's own lockForUpdate() only unblocks once the
                // holder has committed - by the time IT calls the clock
                // closure, it is looking at the post-commit row already.
                $this->assertSame('locked', $this->readEvent($waiter['socket'])['event']);
                fwrite($waiter['socket'], "release\n");
                $loser = $this->readEvent($waiter['socket'], 'result');

                $this->assertTrue($winner['ok']);
                $this->assertTrue($winner['accepted']);
                $this->assertTrue($loser['ok']);
                $this->assertFalse(
                    $loser['accepted'],
                    'The second call must see the first\'s committed advance and reject, not repeat it.',
                );
                $this->assertSame('INVALID_SESSION_TRANSITION', $loser['errorCode']);
            } finally {
                $this->stopWorker($waiter);
            }
        } finally {
            $this->stopWorker($holder);
        }

        app(RlsContextRunner::class)->runAsService(function (): void {
            $row = DB::table('test_sessions')->where('id', $this->fixture['session'])->sole();
            $this->assertSame(0, (int) $row->current_segment_index, 'Confirming the reading gap never advances the index.');
            $this->assertNotNull($row->current_segment_started_at);
        });
    }

    /** @return array{pid: int, backend: int, socket: resource} */
    private function startWorker(int $participantId, string $sessionPublicId): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create subtest/next concurrency worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 20);
            try {
                DB::purge('pgsql');
                $identity = DB::selectOne(
                    'SELECT pg_backend_pid() AS pid, current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
                );
                if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                    throw new RuntimeException('Worker must be runtime non-owner without RLS bypass.');
                }
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                $this->writeEvent($pair[1], ['event' => 'ready', 'backend' => (int) $identity->pid]);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Start barrier timed out.');
                }

                $clock = function () use ($pair): DateTimeImmutable {
                    $this->writeEvent($pair[1], ['event' => 'locked']);
                    if (fgets($pair[1]) !== "release\n") {
                        throw new RuntimeException('Lock release barrier timed out.');
                    }

                    return new DateTimeImmutable('2026-09-08 03:00:30+07:00');
                };
                $action = new SubtestNext(app(RlsContextRunner::class), new TimedSegmentTransitionPolicy, $clock);
                $result = $action->execute($participantId, $sessionPublicId);
                $this->writeEvent($pair[1], [
                    'event' => 'result', 'ok' => true,
                    'accepted' => $result->accepted, 'errorCode' => $result->errorCode,
                ]);
            } catch (Throwable $exception) {
                try {
                    $this->writeEvent($pair[1], [
                        'event' => 'result', 'ok' => false, 'error' => $exception::class.': '.$exception->getMessage(),
                    ]);
                } catch (Throwable) {
                    // The parent already owns the failure after closing the barrier socket.
                }
            }
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 20);
        $ready = $this->readEvent($pair[0], 'ready');

        return ['pid' => $pid, 'backend' => $ready['backend'], 'socket' => $pair[0]];
    }

    private function assertBackendWaitsOnLock(int $backend): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->assertSame('Lock', $waiting?->wait_event_type);
    }

    /**
     * @param  resource  $socket
     * @return array<string, mixed>
     */
    private function readEvent($socket, ?string $expected = null): array
    {
        $line = fgets($socket);
        if (! is_string($line)) {
            throw new RuntimeException('Worker did not report an event.');
        }
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || ! is_string($event['event'] ?? null)) {
            throw new RuntimeException('Worker event is invalid.');
        }
        if ($expected !== null) {
            $this->assertSame($expected, $event['event'], json_encode($event, JSON_THROW_ON_ERROR));
        }

        return $event;
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $event
     */
    private function writeEvent($socket, array $event): void
    {
        fwrite($socket, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /** @param array{pid: int, backend: int, socket: resource} $worker */
    private function stopWorker(array $worker): void
    {
        fclose($worker['socket']);
        pcntl_waitpid($worker['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    /** @return array{branch: int, participant: int, session: int} */
    private function sessionFixture(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'SubtestNext Concurrency',
                'organization_code' => $key, 'display_name' => 'SubtestNext Concurrency',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
                'full_name' => 'Synthetic', 'phone' => '620000000000',
            ]);

            $definitionSource = [
                'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
                'provenance' => 'synthetic-subtest-next-concurrency-test-only', 'total_duration_seconds' => 3600,
                'subtests' => [
                    ['code' => 'SE', 'duration_seconds' => 1800, 'item_count' => 5, 'reading_cap_seconds' => 60],
                    ['code' => 'WA', 'duration_seconds' => 1800, 'item_count' => 5],
                ],
                'randomization' => 'fixed', 'seed' => null, 'generator' => null,
            ];
            $definition = SessionDefinition::fromArray([
                ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
            ]);

            $session = DB::table('test_sessions')->insertGetId([
                'public_id' => (string) Str::ulid(), 'participant_id' => $participant, 'test_type' => 'ist',
                'attempt_no' => 1,
                'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
                'duration_seconds' => 3660, 'status' => 'in_progress', 'answers_revision' => 0,
                'started_at' => '2026-09-08 03:00:00.000000+07:00', 'ends_at' => '2026-09-08 04:01:00.000000+07:00',
                'current_segment_index' => 0,
                'current_segment_became_current_at' => '2026-09-08 03:00:00.000000+07:00',
                'current_segment_started_at' => null,
                'session_definition_version' => $definition->version,
                'session_definition_provenance' => $definition->provenance,
                'session_definition_checksum' => $definition->checksum,
                'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['branch' => $branch, 'participant' => $participant, 'session' => $session];
        });
    }

    /** @param array{branch: int, participant: int, session: int} $fixture */
    private function cleanupFixture(array $fixture): void
    {
        $runId = getenv('ORG_TEST_RUN_ID');
        $database = DB::selectOne(<<<'SQL'
            SELECT shobj_description(oid, 'pg_database') AS marker
            FROM pg_database
            WHERE datname = current_database()
            SQL);
        if (! is_string($runId) || $runId === '' || $database?->marker !== "ONCAM_ORG_TEST:{$runId}") {
            throw new RuntimeException('Subtest/next concurrency cleanup requires the marked disposable database.');
        }

        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.subtest_next_concurrency_cleanup', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        $owner = DB::connection('subtest_next_concurrency_cleanup');

        try {
            $owner->transaction(function () use ($owner, $fixture): void {
                $owner->statement("SET LOCAL session_replication_role = 'replica'");
                $owner->table('test_sessions')->where('id', $fixture['session'])->delete();
                $owner->table('participants')->where('id', $fixture['participant'])->delete();
                $owner->table('branches')->where('id', $fixture['branch'])->delete();
            });
        } finally {
            DB::purge('subtest_next_concurrency_cleanup');
            config()->set('database.connections.subtest_next_concurrency_cleanup', null);
        }
    }
}
