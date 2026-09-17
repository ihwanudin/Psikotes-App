<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentSessions\StartAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** PostgreSQL-authoritative serialization evidence for generic session start. */
final class AssessmentSessionStartActionConcurrencyTest extends TestCase
{
    /** @var array{branch:int,participant:int,session:int,public_id:string} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'Start Synthetic',
                'organization_code' => $key, 'display_name' => 'Start Synthetic',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'full_name' => 'Start Synthetic',
                'phone' => '620000000000',
            ]);
            $publicId = (string) Str::ulid();
            $session = DB::table('test_sessions')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
                'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
                'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3671,
                'status' => 'created', 'answers_revision' => 0,
            ]);

            return [
                'branch' => $branch,
                'participant' => $participant,
                'session' => $session,
                'public_id' => $publicId,
            ];
        });
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('test_sessions')->where('id', $this->fixture['session'])->delete();
            DB::table('participants')->where('id', $this->fixture['participant'])->delete();
            DB::table('branches')->where('id', $this->fixture['branch'])->delete();
        });
        parent::tearDown();
    }

    public function test_two_runtime_processes_start_once_and_replay_the_fixed_window(): void
    {
        DB::purge('pgsql');
        $workers = [$this->startWorker(), $this->startWorker()];

        try {
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }

            $holderIndex = $this->waitForLockHolder($workers);
            $waiterIndex = $holderIndex === 0 ? 1 : 0;
            $this->assertWorkerWaitsOnLock($workers[$waiterIndex]['backend']);

            fwrite($workers[$holderIndex]['socket'], "release\n");
            $winner = $this->readEvent($workers[$holderIndex]['socket'], 'result');
            $this->assertSame('locked', $this->readEvent($workers[$waiterIndex]['socket'])['event']);
            fwrite($workers[$waiterIndex]['socket'], "release\n");
            $loser = $this->readEvent($workers[$waiterIndex]['socket'], 'result');

            $this->assertTrue($winner['accepted']);
            $this->assertFalse($winner['replayed']);
            $this->assertTrue($loser['accepted']);
            $this->assertTrue($loser['replayed']);
            $this->assertSame($winner['started_at'], $loser['started_at']);
            $this->assertSame($winner['ends_at'], $loser['ends_at']);
        } finally {
            $this->stopWorkers($workers);
        }

        app(RlsContextRunner::class)->runAsService(function (): void {
            $row = DB::table('test_sessions')->where('id', $this->fixture['session'])->sole();
            $duration = DB::selectOne(
                'SELECT EXTRACT(EPOCH FROM (?::timestamptz - ?::timestamptz)) AS seconds',
                [$row->ends_at, $row->started_at],
            );
            $this->assertSame('in_progress', $row->status);
            $this->assertSame('3671.000000', $duration->seconds);
            $this->assertSame('2026-09-08 03:00:00.123456+00', $row->started_at);
        });
    }

    /** @return array{pid:int,backend:int,socket:resource} */
    private function startWorker(): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Assessment start concurrency requires pcntl; never skip.');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create assessment start worker.');
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
                    throw new RuntimeException('Start worker must be runtime non-owner without RLS bypass.');
                }
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                $this->writeEvent($pair[1], ['event' => 'ready', 'backend' => (int) $identity->pid]);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Start barrier timed out.');
                }

                $action = new StartAssessmentSession(
                    app(RlsContextRunner::class),
                    new AssessmentSessionStateMachine,
                    new AssessmentSessionDeadlinePolicy,
                    function () use ($pair): DateTimeImmutable {
                        $this->writeEvent($pair[1], ['event' => 'locked']);
                        if (fgets($pair[1]) !== "release\n") {
                            throw new RuntimeException('Start lock release barrier timed out.');
                        }

                        return new DateTimeImmutable('2026-09-08T03:00:00.123456+00:00');
                    },
                );
                $result = $action->execute($this->fixture['participant'], $this->fixture['public_id']);
                $this->writeEvent($pair[1], [
                    'event' => 'result', 'accepted' => $result->accepted, 'replayed' => $result->replayed,
                    'error' => $result->errorCode,
                    'started_at' => $result->startedAt?->format('Y-m-d H:i:s.uP'),
                    'ends_at' => $result->endsAt?->format('Y-m-d H:i:s.uP'),
                ]);
            } catch (Throwable $exception) {
                try {
                    $this->writeEvent($pair[1], [
                        'event' => 'unexpected', 'type' => $exception::class, 'message' => $exception->getMessage(),
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

    /** @param list<array{pid:int,backend:int,socket:resource}> $workers */
    private function waitForLockHolder(array $workers): int
    {
        $read = [$workers[0]['socket'], $workers[1]['socket']];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 10) !== 1) {
            throw new RuntimeException('Exactly one start worker should acquire the session lock.');
        }
        $readySocket = reset($read);
        if (! is_resource($readySocket)) {
            throw new RuntimeException('Start lock holder socket is invalid.');
        }
        $holder = $readySocket === $workers[0]['socket'] ? 0 : 1;
        $this->assertSame('locked', $this->readEvent($workers[$holder]['socket'])['event']);

        return $holder;
    }

    private function assertWorkerWaitsOnLock(int $backend): void
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

    /** @param resource $socket
     * @return array<string,mixed>
     */
    private function readEvent($socket, ?string $expected = null): array
    {
        $line = fgets($socket);
        if (! is_string($line)) {
            throw new RuntimeException('Start worker did not report an event.');
        }
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || ! is_string($event['event'] ?? null)) {
            throw new RuntimeException('Start worker event is invalid.');
        }
        if ($expected !== null) {
            $this->assertSame($expected, $event['event'], json_encode($event, JSON_THROW_ON_ERROR));
        }

        return $event;
    }

    /** @param resource $socket
     * @param  array<string,mixed>  $event
     */
    private function writeEvent($socket, array $event): void
    {
        fwrite($socket, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /** @param list<array{pid:int,backend:int,socket:resource}> $workers */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }
}
