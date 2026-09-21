<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentSessions\AutosaveAssessmentAnswers;
use App\Actions\AssessmentSessions\GetAssessmentSession;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Actions\AssessmentSessions\SubmitAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionSubmitPolicy;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Http\Controllers\AutosaveAssessmentAnswersController;
use App\Http\Controllers\GetAssessmentSessionController;
use App\Http\Controllers\SubmitAssessmentSessionController;
use App\Http\Requests\AutosaveAssessmentAnswersRequest;
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
 * F2 session-http, Correction (2026-09-21, Lead review of PR #49). The
 * original plan (Q5) promised a thin PostgreSQL test through the real
 * controllers, proving the route/middleware/RLS stack this PR adds doesn't
 * undermine the already-proven action-level atomicity -- not a re-proof of
 * that atomicity itself (see AssessmentSessionAutosaveActionTest.php /
 * AssessmentSessionSubmitActionTest.php for the exhaustive action-level
 * evidence). The PR shipped without it; this file is that missing piece,
 * added as its own commit per Lead's explicit instruction not to amend a PR
 * already under review.
 *
 * Same convention as StartParticipantSessionControllerSecurityTest.php:
 * controllers are invoked directly with a manually-built Request/FormRequest
 * (participant_principal set as an attribute, exactly as the real
 * `participant.jwt` middleware would leave it) against the real
 * psikotes_runtime non-owner/NOBYPASSRLS role -- this is what "PostgreSQL
 * HTTP-level" means in this codebase's existing tests/Postgres/** files,
 * which extend bare PHPUnit\Framework\TestCase specifically to avoid
 * Laravel's SQLite-oriented HTTP test bootstrapping. It does not additionally
 * exercise the JWT middleware itself or route resolution -- both are already
 * covered elsewhere (AssessmentSessionHttpBoundaryTest.php for routing/
 * middleware wiring; the participant.jwt middleware has its own coverage).
 */
final class AssessmentSessionHttpControllerTest extends TestCase
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

    public function test_all_three_endpoints_happy_path_through_the_real_controllers(): void
    {
        $identity = DB::selectOne(
            'SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
        );
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);

        $fixture = $this->fixture();
        $principal = new ParticipantPrincipal($fixture['participant'], $fixture['branch']);

        $getResponse = (new GetAssessmentSessionController)(
            $this->principalRequest($principal),
            $fixture['public_id'],
            $this->getAction('2026-09-08T03:30:00+00:00'),
        );
        $this->assertSame(200, $getResponse->getStatusCode());
        $getBody = $getResponse->getData(true);
        $this->assertSame('in_progress', $getBody['status']);
        $this->assertSame(0, $getBody['answers_revision']);

        $mutation = (string) Str::ulid();
        $autosaveResponse = (new AutosaveAssessmentAnswersController)(
            $this->autosaveRequest($principal, [
                'mutation_id' => $mutation, 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']],
            ]),
            $fixture['public_id'],
            $this->autosaveAction('2026-09-08T03:30:00+00:00'),
        );
        $this->assertSame(200, $autosaveResponse->getStatusCode());
        $autosaveBody = $autosaveResponse->getData(true);
        $this->assertSame('in_progress', $autosaveBody['status']);
        $this->assertSame(1, $autosaveBody['answers_revision']);
        $this->assertSame(1, $this->serviceCount('answers', $fixture['session']));

        $submitResponse = (new SubmitAssessmentSessionController)(
            $this->principalRequest($principal),
            $fixture['public_id'],
            $this->submitAction('2026-09-08T03:31:00+00:00'),
        );
        $this->assertSame(200, $submitResponse->getStatusCode());
        $submitBody = $submitResponse->getData(true);
        // AssessmentSessionSubmitPolicy only ever transitions InProgress ->
        // Submitted -- scoring is a separate later pipeline this action does
        // not run, so 'submitted' is the real post-state, not the contract
        // example's literal 'scored'. See the handoff doc.
        $this->assertSame('submitted', $submitBody['status']);
        $this->assertSame(1, $submitBody['answers_revision']);
        $this->assertSame('submitted', DB::table('test_sessions')->where('id', $fixture['session'])->value('status'));
    }

    public function test_foreign_session_is_404_through_the_real_controllers_under_postgres_rls(): void
    {
        $owner = $this->fixture();
        $stranger = $this->graph();
        $principal = new ParticipantPrincipal($stranger['participant'], $stranger['branch']);

        $getResponse = (new GetAssessmentSessionController)(
            $this->principalRequest($principal), $owner['public_id'], $this->getAction('2026-09-08T03:30:00+00:00'),
        );
        $this->assertSame(404, $getResponse->getStatusCode());
        $this->assertSame('SESSION_NOT_FOUND', $getResponse->getData(true)['error']['code']);

        $autosaveResponse = (new AutosaveAssessmentAnswersController)(
            $this->autosaveRequest($principal, [
                'mutation_id' => (string) Str::ulid(), 'revision' => 1, 'items' => [['item_no' => 1, 'value' => 'A']],
            ]),
            $owner['public_id'],
            $this->autosaveAction('2026-09-08T03:30:00+00:00'),
        );
        $this->assertSame(404, $autosaveResponse->getStatusCode());
        $this->assertSame('SESSION_NOT_FOUND', $autosaveResponse->getData(true)['error']['code']);

        $submitResponse = (new SubmitAssessmentSessionController)(
            $this->principalRequest($principal), $owner['public_id'], $this->submitAction('2026-09-08T03:30:00+00:00'),
        );
        $this->assertSame(404, $submitResponse->getStatusCode());
        $this->assertSame('SESSION_NOT_FOUND', $submitResponse->getData(true)['error']['code']);

        $this->assertSame(0, $this->serviceCount('answers', $owner['session']));
        $this->assertSame('in_progress', DB::table('test_sessions')->where('id', $owner['session'])->value('status'));
    }

    public function test_two_processes_race_the_same_autosave_mutation_through_the_real_controller(): void
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

            $this->assertSame(200, $winner['status']);
            $this->assertSame(200, $loser['status']);
            $this->assertFalse($winner['body']['replayed']);
            $this->assertTrue($loser['body']['replayed']);
            $this->assertSame($winner['body']['answers_revision'], $loser['body']['answers_revision']);
            $this->assertSame($winner['body']['accepted_item_numbers'], $loser['body']['accepted_item_numbers']);
        } finally {
            $this->stopWorkers($workers);
        }

        $snapshot = $this->serviceSnapshot($fixture['session']);
        $this->assertSame(1, $snapshot['revision']);
        $this->assertSame(1, $snapshot['mutations']);
        $this->assertSame(1, $snapshot['answers']);
    }

    private function principalRequest(ParticipantPrincipal $principal): Request
    {
        $request = Request::create('/api/sessions/x', 'GET');
        $request->attributes->set('participant_principal', $principal);

        return $request;
    }

    /** @param array<string, mixed> $payload */
    private function autosaveRequest(ParticipantPrincipal $principal, array $payload): AutosaveAssessmentAnswersRequest
    {
        $request = AutosaveAssessmentAnswersRequest::create('/api/sessions/x/answers', 'POST', $payload);
        $request->setContainer(app());
        $request->attributes->set('participant_principal', $principal);
        $request->validateResolved();

        return $request;
    }

    private function getAction(string $iso): GetAssessmentSession
    {
        return new GetAssessmentSession(
            app(RlsContextRunner::class),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        );
    }

    private function autosaveAction(string $iso): AutosaveAssessmentAnswers
    {
        return new AutosaveAssessmentAnswers(
            app(RlsContextRunner::class),
            new AssessmentAutosavePolicy,
            new SealExpiredAssessmentSession(app(RlsContextRunner::class)),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        );
    }

    private function submitAction(string $iso): SubmitAssessmentSession
    {
        return new SubmitAssessmentSession(
            app(RlsContextRunner::class),
            new AssessmentSessionSubmitPolicy,
            new SealExpiredAssessmentSession(app(RlsContextRunner::class)),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        );
    }

    /** @return array{branch:int,participant:int} */
    private function graph(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'HTTP Controller Synthetic',
                'organization_code' => $key, 'display_name' => 'HTTP Controller Synthetic',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'full_name' => 'HTTP Controller Synthetic',
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
                'provenance' => 'session-http-controller-pg-test-only', 'total_duration_seconds' => 3600,
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

    /** @return array{revision:int,mutations:int,answers:int} */
    private function serviceSnapshot(int $session): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($session): array {
            $row = DB::table('test_sessions')->where('id', $session)->select('answers_revision')->sole();

            return [
                'revision' => (int) $row->answers_revision,
                'mutations' => DB::table('assessment_autosave_mutations')->where('session_id', $session)->count(),
                'answers' => DB::table('answers')->where('session_id', $session)->count(),
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
        $this->assertTrue(function_exists('pcntl_fork'), 'Assessment session HTTP concurrency requires pcntl; never skip.');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create assessment session HTTP worker.');
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

                $principal = new ParticipantPrincipal($fixture['participant'], $fixture['branch']);
                $request = $this->autosaveRequest($principal, [
                    'mutation_id' => $mutation, 'revision' => 1, 'items' => [['item_no' => 1, 'value' => ['choice' => 'A']]],
                ]);
                $action = new AutosaveAssessmentAnswers(
                    app(RlsContextRunner::class),
                    new AssessmentAutosavePolicy,
                    new SealExpiredAssessmentSession(app(RlsContextRunner::class)),
                    function () use ($pair): DateTimeImmutable {
                        $this->writeEvent($pair[1], ['event' => 'locked']);
                        if (fgets($pair[1]) !== "release\n") {
                            throw new RuntimeException('Worker lock release barrier timed out.');
                        }

                        return new DateTimeImmutable('2026-09-08T03:30:00.654321+00:00');
                    },
                );
                $response = (new AutosaveAssessmentAnswersController)($request, $fixture['public_id'], $action);
                $this->writeEvent($pair[1], [
                    'event' => 'result', 'status' => $response->getStatusCode(), 'body' => $response->getData(true),
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
            throw new RuntimeException('Exactly one worker should acquire the session lock.');
        }
        $readySocket = reset($read);
        if (! is_resource($readySocket)) {
            throw new RuntimeException('Lock holder socket is invalid.');
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
            throw new RuntimeException('HTTP controller concurrency cleanup requires the marked disposable database.');
        }

        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.assessment_http_controller_cleanup', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        $owner = DB::connection('assessment_http_controller_cleanup');

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
            DB::purge('assessment_http_controller_cleanup');
            config()->set('database.connections.assessment_http_controller_cleanup', null);
        }
    }
}
