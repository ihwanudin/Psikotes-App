<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentResults\ScoreAssessmentSession;
use App\Actions\AssessmentSessions\AutosaveAssessmentAnswers;
use App\Actions\AssessmentSessions\GetAssessmentSessionAnswers;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Http\Controllers\GetAssessmentSessionAnswersController;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * F2 session-answers-readback (2026-09-21). PostgreSQL HTTP-level evidence
 * for GET /sessions/{id}/answers, same convention as
 * AssessmentSessionHttpControllerTest.php (controllers invoked directly
 * with a manually-built Request carrying participant_principal, against
 * the real psikotes_runtime non-owner/NOBYPASSRLS role).
 *
 * The concurrency test is the one Lead's plan sign-off made mandatory at
 * PostgreSQL specifically: GetAssessmentSessionAnswers reads
 * test_sessions.answers_revision and the answers rows in a single
 * statement (a LEFT JOIN) precisely because PostgreSQL READ COMMITTED
 * gives a fresh snapshot per *statement*, not per transaction -- two
 * separate SELECTs could let a concurrent autosave's commit land in
 * between them, reporting a revision that doesn't match the answers
 * actually returned. This can only be demonstrated (or refuted) against
 * real PostgreSQL; SQLite's single-writer model can't reproduce the
 * interleaving this guards against.
 */
final class AssessmentSessionAnswersReadbackControllerTest extends TestCase
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

    public function test_happy_path_through_the_real_controller_under_postgres(): void
    {
        $identity = DB::selectOne(
            'SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
        );
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);

        $fixture = $this->fixture();
        $autosave = new AutosaveAssessmentAnswers(
            app(RlsContextRunner::class), new AssessmentAutosavePolicy,
            new SealExpiredAssessmentSession(app(RlsContextRunner::class), app(ScoreAssessmentSession::class)),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+00:00'),
        );
        $autosaveResult = $autosave->execute($fixture['participant'], $fixture['public_id'], (string) Str::ulid(), 1, [
            ['item_no' => 1, 'value' => 'A'],
        ]);
        $this->assertTrue($autosaveResult->accepted);

        $response = (new GetAssessmentSessionAnswersController)(
            $this->principalRequest($fixture['participant'], $fixture['branch']), $fixture['public_id'], $this->readAction('2026-09-08T03:31:00+00:00'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame($fixture['public_id'], $body['session_id']);
        $this->assertSame(1, $body['answers_revision']);
        $this->assertSame([['item_no' => 1, 'value' => 'A']], $body['answers']);
    }

    public function test_foreign_session_is_404_through_the_real_controller_under_postgres_rls(): void
    {
        $owner = $this->fixture();
        $stranger = $this->graph();

        $response = (new GetAssessmentSessionAnswersController)(
            $this->principalRequest($stranger['participant'], $stranger['branch']),
            $owner['public_id'],
            $this->readAction('2026-09-08T03:30:00+00:00'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('SESSION_NOT_FOUND', $response->getData(true)['error']['code']);
    }

    public function test_a_read_during_an_uncommitted_concurrent_autosave_never_returns_a_torn_revision_and_answers_pair(): void
    {
        DB::rollBack();
        $fixture = $this->fixture();
        $this->committedFixture = $fixture;
        DB::purge('pgsql');
        $worker = $this->startAutosaveWorker($fixture);

        try {
            fwrite($worker['socket'], "go\n");
            $this->assertSame('locked', $this->readEvent($worker['socket'])['event']);

            // The autosave worker holds test_sessions locked FOR UPDATE but has
            // not committed. A plain SELECT never blocks on a row lock in
            // PostgreSQL, so this read proceeds immediately and must see a
            // self-consistent pre-commit snapshot: revision 0 with no answers,
            // never revision 1 with no answers or revision 0 with an answer row.
            $duringResponse = (new GetAssessmentSessionAnswersController)(
                $this->principalRequest($fixture['participant'], $fixture['branch']), $fixture['public_id'], $this->readAction('2026-09-08T03:30:30+00:00'),
            );
            $duringBody = $duringResponse->getData(true);
            $this->assertSame(200, $duringResponse->getStatusCode());
            $this->assertSame(0, $duringBody['answers_revision']);
            $this->assertSame([], $duringBody['answers']);

            fwrite($worker['socket'], "release\n");
            $result = $this->readEvent($worker['socket'], 'result');
            $this->assertTrue($result['accepted']);

            // After the commit, a fresh read must see the new, equally
            // consistent snapshot: revision 1 with exactly the one answer.
            $afterResponse = (new GetAssessmentSessionAnswersController)(
                $this->principalRequest($fixture['participant'], $fixture['branch']), $fixture['public_id'], $this->readAction('2026-09-08T03:31:00+00:00'),
            );
            $afterBody = $afterResponse->getData(true);
            $this->assertSame(200, $afterResponse->getStatusCode());
            $this->assertSame(1, $afterBody['answers_revision']);
            $this->assertSame([['item_no' => 1, 'value' => 'A']], $afterBody['answers']);
        } finally {
            $this->stopWorkers([$worker]);
        }
    }

    private function readAction(string $iso): GetAssessmentSessionAnswers
    {
        return new GetAssessmentSessionAnswers(
            app(RlsContextRunner::class),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        );
    }

    private function principalRequest(int $participant, int $branch): Request
    {
        $request = Request::create('/api/sessions/x/answers', 'GET');
        $request->attributes->set('participant_principal', new ParticipantPrincipal($participant, $branch));

        return $request;
    }

    /** @return array{branch:int,participant:int} */
    private function graph(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'Answers Readback Synthetic',
                'organization_code' => $key, 'display_name' => 'Answers Readback Synthetic',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'full_name' => 'Answers Readback Synthetic',
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
            $definitionSource = [
                'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
                'provenance' => 'answers-readback-pg-test-only', 'total_duration_seconds' => 3600,
                'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 5]],
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

    /**
     * @param  array{branch:int,participant:int,session:int,public_id:string}  $fixture
     * @return array{pid:int,backend:int,socket:resource}
     */
    private function startAutosaveWorker(array $fixture): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Answers readback concurrency requires pcntl; never skip.');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create autosave worker.');
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
                    throw new RuntimeException('Worker start barrier timed out.');
                }

                $action = new AutosaveAssessmentAnswers(
                    app(RlsContextRunner::class),
                    new AssessmentAutosavePolicy,
                    new SealExpiredAssessmentSession(app(RlsContextRunner::class), app(ScoreAssessmentSession::class)),
                    function () use ($pair): DateTimeImmutable {
                        $this->writeEvent($pair[1], ['event' => 'locked']);
                        if (fgets($pair[1]) !== "release\n") {
                            throw new RuntimeException('Worker lock release barrier timed out.');
                        }

                        return new DateTimeImmutable('2026-09-08T03:30:45.000000+00:00');
                    },
                );
                $result = $action->execute($fixture['participant'], $fixture['public_id'], (string) Str::ulid(), 1, [
                    ['item_no' => 1, 'value' => 'A'],
                ]);
                $this->writeEvent($pair[1], ['event' => 'result', 'accepted' => $result->accepted, 'error' => $result->errorCode]);
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

    /**
     * @param  resource  $socket
     * @return array<string,mixed>
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
            throw new RuntimeException('Answers readback concurrency cleanup requires the marked disposable database.');
        }

        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.answers_readback_cleanup', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        $owner = DB::connection('answers_readback_cleanup');

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
            DB::purge('answers_readback_cleanup');
            config()->set('database.connections.answers_readback_cleanup', null);
        }
    }
}
