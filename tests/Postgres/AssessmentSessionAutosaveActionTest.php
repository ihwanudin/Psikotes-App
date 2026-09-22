<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentSessions\AutosaveAssessmentAnswers;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** PostgreSQL-authoritative application and serialization evidence for generic autosave. */
final class AssessmentSessionAutosaveActionTest extends TestCase
{
    /** @var array{branch:int,participant:int,session:int,public_id:string}|null */
    private ?array $committedFixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($this->committedFixture !== null) {
            $this->cleanupCommittedFixture($this->committedFixture);
            $this->committedFixture = null;
        }
        parent::tearDown();
    }

    public function test_action_writes_through_non_owner_service_rls_and_replays_exact_receipt(): void
    {
        $identity = DB::selectOne(
            'SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
        );
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);

        $fixture = $this->fixture();
        $mutation = (string) Str::ulid();
        $observedRole = null;
        $first = $this->action(function () use (&$observedRole): DateTimeImmutable {
            $observedRole = app(RlsContextRunner::class)->current()?->role;

            return new DateTimeImmutable('2026-09-08T03:30:00.123456+00:00');
        })->execute($fixture['participant'], $fixture['public_id'], $mutation, 1, [
            ['item_no' => 2, 'value' => ['z' => 2, 'a' => 1]],
            ['item_no' => 1, 'value' => 'A'],
        ]);

        $this->assertTrue($first->accepted);
        $this->assertFalse($first->replayed);
        $this->assertSame('service', $observedRole);
        $this->assertNotNull($first->receipt);
        $beforeReplay = $this->serviceSnapshot($fixture['session']);

        $replay = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:30:00.999999+00:00'))
            ->execute($fixture['participant'], $fixture['public_id'], $mutation, 1, [
                ['item_no' => 1, 'value' => 'A'],
                ['item_no' => 2, 'value' => ['a' => 1, 'z' => 2]],
            ]);
        $afterReplay = $this->serviceSnapshot($fixture['session']);

        $this->assertTrue($replay->accepted);
        $this->assertTrue($replay->replayed);
        $this->assertNotNull($replay->receipt);
        $this->assertSame($first->receipt->mutationId, $replay->receipt->mutationId);
        $this->assertSame($first->receipt->revision, $replay->receipt->revision);
        $this->assertSame($first->receipt->acceptedItemNumbers, $replay->receipt->acceptedItemNumbers);
        $this->assertSame(
            $first->receipt->receivedAt->format('Y-m-d H:i:s.uP'),
            $replay->receipt->receivedAt->format('Y-m-d H:i:s.uP'),
        );
        $this->assertSame($beforeReplay, $afterReplay);

        $mismatch = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:31:00+00:00'))
            ->execute($fixture['participant'], $fixture['public_id'], $mutation, 1, [
                ['item_no' => 1, 'value' => 'B'],
                ['item_no' => 2, 'value' => ['a' => 1, 'z' => 2]],
            ]);
        $this->assertFalse($mismatch->accepted);
        $this->assertSame('MUTATION_PAYLOAD_MISMATCH', $mismatch->errorCode);
        $this->assertSame($afterReplay, $this->serviceSnapshot($fixture['session']));
    }

    public function test_exact_deadline_is_accepted_and_one_microsecond_late_is_expired(): void
    {
        $exact = $this->fixture();
        $accepted = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:00:00.000000+00:00'))
            ->execute($exact['participant'], $exact['public_id'], (string) Str::ulid(), 1, [
                ['item_no' => 1, 'value' => 'A'],
            ]);
        $this->assertTrue($accepted->accepted);

        $late = $this->fixture();
        $rejected = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:00:00.000001+00:00'))
            ->execute($late['participant'], $late['public_id'], (string) Str::ulid(), 1, [
                ['item_no' => 1, 'value' => 'A'],
            ]);
        $this->assertFalse($rejected->accepted);
        $this->assertSame('DEADLINE_EXCEEDED', $rejected->errorCode);
        $this->assertSame('expired', $rejected->status);

        $state = app(RlsContextRunner::class)->runAsService(fn (): object => DB::table('test_sessions')
            ->where('id', $late['session'])->select('status', 'answers_revision', 'expired_at')->sole());
        $this->assertSame('expired', $state->status);
        $this->assertSame(0, $state->answers_revision);
        $this->assertNotNull($state->expired_at);
        $this->assertSame(0, $this->serviceCount('assessment_autosave_mutations', $late['session']));
        $this->assertSame(0, $this->serviceCount('answers', $late['session']));
    }

    public function test_wrong_participant_and_isolated_dass_identity_fail_closed(): void
    {
        $generic = $this->fixture();
        $foreign = $this->graph();
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+00:00'));

        $wrongOwner = $action->execute($foreign['participant'], $generic['public_id'], (string) Str::ulid(), 1, [
            ['item_no' => 1, 'value' => 'A'],
        ]);
        $this->assertFalse($wrongOwner->accepted);
        $this->assertSame('SESSION_NOT_FOUND', $wrongOwner->errorCode);

        $dassPublicId = app(RlsContextRunner::class)->runAsService(function () use ($generic): string {
            $document = ConsentDocument::for('dass');
            $consent = DB::table('consent_records')->insertGetId([
                'participant_id' => $generic['participant'], 'consent_type' => 'dass',
                'status' => 'accepted', 'document_version' => $document->version,
                'document_hash' => $document->hash, 'consented_at' => now(),
            ]);
            $publicId = (string) Str::ulid();
            DB::table('dass.assessments')->insert([
                'public_id' => $publicId, 'participant_id' => $generic['participant'],
                'consent_record_id' => $consent, 'status' => 'in_progress',
                'started_at' => now(), 'expires_at' => now()->addYear(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $publicId;
        });
        $dass = $action->execute($generic['participant'], $dassPublicId, (string) Str::ulid(), 1, [
            ['item_no' => 1, 'value' => 3],
        ]);
        $this->assertFalse($dass->accepted);
        $this->assertSame('SESSION_NOT_FOUND', $dass->errorCode);
        $this->assertSame(0, $this->serviceCount('answers', $generic['session']));
    }

    public function test_database_failure_rolls_back_receipt_and_prior_answer_statement_work(): void
    {
        $fixture = $this->fixture();
        $mutation = (string) Str::ulid();

        try {
            app(RlsContextRunner::class)->runAsService(function () use ($fixture, $mutation): void {
                DB::table('answers')->insert([
                    'session_id' => $fixture['session'], 'item_no' => 1,
                    'value' => json_encode('old', JSON_THROW_ON_ERROR), 'revision' => 1,
                    'answered_at' => '2026-09-08 03:00:00.000000+00',
                ]);

                try {
                    $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+00:00'))
                        ->execute($fixture['participant'], $fixture['public_id'], $mutation, 1, [
                            ['item_no' => 2, 'value' => 'new'],
                            ['item_no' => 1, 'value' => 'new'],
                        ]);
                    $this->fail('The PostgreSQL revision guard should reject the second answer row.');
                } catch (QueryException $exception) {
                    $this->assertSame('P0001', $exception->getCode());
                }

                $this->assertSame(0, DB::table('assessment_autosave_mutations')
                    ->where('session_id', $fixture['session'])->count());
                $this->assertSame(0, DB::table('answers')
                    ->where('session_id', $fixture['session'])->where('item_no', 2)->count());
                $this->assertSame(1, DB::table('answers')
                    ->where('session_id', $fixture['session'])->where('item_no', 1)->count());
                $this->assertSame(0, DB::table('test_sessions')
                    ->where('id', $fixture['session'])->value('answers_revision'));
                DB::statement('SET CONSTRAINTS answers_ledger_guard IMMEDIATE');
            });
            $this->fail('The deliberately inconsistent seed should roll back its complete service transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }

        $this->assertSame(0, $this->serviceCount('assessment_autosave_mutations', $fixture['session']));
        $this->assertSame(0, $this->serviceCount('answers', $fixture['session']));
    }

    public function test_two_processes_serialize_same_mutation_to_one_commit_and_exact_replay(): void
    {
        DB::rollBack();
        $fixture = $this->fixture();
        $this->committedFixture = $fixture;
        $mutation = (string) Str::ulid();
        DB::purge('pgsql');
        $workers = [
            $this->startWorker($fixture, $mutation),
            $this->startWorker($fixture, $mutation),
        ];

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
            $this->assertSame($winner['receipt'], $loser['receipt']);
        } finally {
            $this->stopWorkers($workers);
        }

        $snapshot = $this->serviceSnapshot($fixture['session']);
        $this->assertSame(1, $snapshot['revision']);
        $this->assertSame(1, $snapshot['mutations']);
        $this->assertSame(1, $snapshot['answers']);
    }

    private function action(callable $clock): AutosaveAssessmentAnswers
    {
        return new AutosaveAssessmentAnswers(
            app(RlsContextRunner::class),
            new AssessmentAutosavePolicy,
            new SealExpiredAssessmentSession(app(RlsContextRunner::class)),
            $clock(...),
        );
    }

    /** @return array{branch:int,participant:int} */
    private function graph(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'Autosave Synthetic',
                'organization_code' => $key, 'display_name' => 'Autosave Synthetic',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'full_name' => 'Autosave Synthetic',
                'phone' => '620000000000',
            ]);

            return compact('branch', 'participant');
        });
    }

    /** @return array{branch:int,participant:int,session:int,public_id:string} */
    private function fixture(): array
    {
        $graph = $this->graph();

        return app(RlsContextRunner::class)->runAsService(function () use ($graph): array {
            // F2 session-http (2026-09-21): a real S3-allocated session
            // always has this snapshot; AutosaveAssessmentAnswers now reads
            // it to bound item_no against the session's real item count.
            // item_count=10 is generous headroom above every item_no this
            // file's tests use (1-2).
            $definitionSource = [
                'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
                'provenance' => 'synthetic-autosave-pg-test-only', 'total_duration_seconds' => 3600,
                'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 10]],
                'randomization' => 'fixed', 'seed' => null, 'generator' => null,
            ];
            $definition = SessionDefinition::fromArray([
                ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
            ]);
            $publicId = (string) Str::ulid();
            $session = DB::table('test_sessions')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $graph['participant'],
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

            return [...$graph, 'session' => $session, 'public_id' => $publicId];
        });
    }

    /** @return array{revision:int,mutations:int,answers:int,updated_at:string|null} */
    private function serviceSnapshot(int $session): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($session): array {
            $row = DB::table('test_sessions')->where('id', $session)
                ->select('answers_revision', 'updated_at')->sole();

            return [
                'revision' => (int) $row->answers_revision,
                'mutations' => DB::table('assessment_autosave_mutations')->where('session_id', $session)->count(),
                'answers' => DB::table('answers')->where('session_id', $session)->count(),
                'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
            ];
        });
    }

    private function serviceCount(string $table, int $session): int
    {
        return app(RlsContextRunner::class)->runAsService(
            fn (): int => DB::table($table)->where('session_id', $session)->count(),
        );
    }

    /**
     * @param  array{branch:int,participant:int,session:int,public_id:string}  $fixture
     * @return array{pid:int,backend:int,socket:resource}
     */
    private function startWorker(array $fixture, string $mutation): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Assessment autosave concurrency requires pcntl; never skip.');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create assessment autosave worker.');
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
                    throw new RuntimeException('Autosave worker must be runtime non-owner without RLS bypass.');
                }
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                $this->writeEvent($pair[1], ['event' => 'ready', 'backend' => (int) $identity->pid]);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Autosave start barrier timed out.');
                }

                $action = $this->action(function () use ($pair): DateTimeImmutable {
                    $this->writeEvent($pair[1], ['event' => 'locked']);
                    if (fgets($pair[1]) !== "release\n") {
                        throw new RuntimeException('Autosave lock release barrier timed out.');
                    }

                    return new DateTimeImmutable('2026-09-08T03:30:00.654321+00:00');
                });
                $result = $action->execute(
                    $fixture['participant'],
                    $fixture['public_id'],
                    $mutation,
                    1,
                    [['item_no' => 1, 'value' => ['choice' => 'A']]],
                );
                $this->writeEvent($pair[1], [
                    'event' => 'result', 'accepted' => $result->accepted, 'replayed' => $result->replayed,
                    'error' => $result->errorCode,
                    'receipt' => $result->receipt === null ? null : [
                        'mutation_id' => $result->receipt->mutationId,
                        'revision' => $result->receipt->revision,
                        'items' => $result->receipt->acceptedItemNumbers,
                        'received_at' => $result->receipt->receivedAt->format('Y-m-d H:i:s.uP'),
                    ],
                ]);
            } catch (Throwable $exception) {
                try {
                    $this->writeEvent($pair[1], [
                        'event' => 'unexpected', 'type' => $exception::class, 'message' => $exception->getMessage(),
                    ]);
                } catch (Throwable) {
                    // The parent already owns the useful failure when it closes the barrier socket.
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
            throw new RuntimeException('Exactly one autosave worker should acquire the session lock.');
        }
        $readySocket = reset($read);
        if (! is_resource($readySocket)) {
            throw new RuntimeException('Autosave lock holder socket is invalid.');
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
            throw new RuntimeException('Autosave worker did not report an event.');
        }
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || ! is_string($event['event'] ?? null)) {
            throw new RuntimeException('Autosave worker event is invalid.');
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
            throw new RuntimeException('Autosave concurrency cleanup requires the marked disposable database.');
        }

        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.assessment_autosave_cleanup', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        $owner = DB::connection('assessment_autosave_cleanup');

        try {
            $owner->transaction(function () use ($owner, $fixture): void {
                // The production ledger stays append-only. Only the marked disposable
                // database owner may remove this exact synthetic concurrency graph.
                $owner->statement("SET LOCAL session_replication_role = 'replica'");
                $owner->table('assessment_autosave_mutations')->where('session_id', $fixture['session'])->delete();
                $owner->table('answers')->where('session_id', $fixture['session'])->delete();
                $owner->table('test_sessions')->where('id', $fixture['session'])->delete();
                $owner->table('participants')->where('id', $fixture['participant'])->delete();
                $owner->table('branches')->where('id', $fixture['branch'])->delete();
            });
        } finally {
            DB::purge('assessment_autosave_cleanup');
            config()->set('database.connections.assessment_autosave_cleanup', null);
        }
    }
}
