<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

/** PostgreSQL proof that both resolver entrypoints retain one canonical participant lock. */
final class CaseAuthorizationResolverConcurrencyTest extends TestCase
{
    /** @var array{organization:int,participant:int,package:int,attempt:int,method:int} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $fixture = AssessmentAccessFixture::create();
            $method = DB::table('assessment_bills')->where('id', $fixture['bill'])->value('payment_method_id');
            if (! is_numeric($method)) {
                throw new RuntimeException('Synthetic assessment bill must expose its payment method in service scope.');
            }

            return [...$fixture, 'method' => (int) $method];
        });
        $this->fixture = [
            'organization' => (int) $fixture['organization'],
            'participant' => (int) $fixture['participant'],
            'package' => (int) $fixture['package'],
            'attempt' => (int) $fixture['attempt'],
            'method' => $fixture['method'],
        ];
    }

    protected function tearDown(): void
    {
        $this->cleanupFixture();
        parent::tearDown();
    }

    public function test_opposing_entrypoints_serialize_on_the_outer_participant_lock_without_deadlock(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Resolver concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $holder = $this->worker('integrated');

        try {
            fwrite($holder['socket'], "go\n");
            $this->assertSame('resolved', $this->event($holder['socket'])['event']);

            $waiter = $this->worker('participant');
            try {
                fwrite($waiter['socket'], "go\n");
                $this->assertBackendWaitsOnLock($waiter['backend']);

                fwrite($holder['socket'], "release\n");
                $this->assertSame('committed', $this->event($holder['socket'])['event']);
                $result = $this->event($waiter['socket']);
                $this->assertSame('rejected', $result['event']);
                $this->assertSame(CaseAuthorizationRejected::class, $result['type']);
            } finally {
                $this->stop($waiter);
            }
        } finally {
            $this->stop($holder);
        }
    }

    /** @return array{pid:int,backend:int,socket:resource} */
    private function worker(string $mode): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create resolver worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 15);
            DB::purge('pgsql');
            try {
                DB::statement("SET lock_timeout = '8s'");
                DB::statement("SET statement_timeout = '12s'");
                $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                if ($identity->name !== 'psikotes_runtime') {
                    throw new RuntimeException('Resolver worker must use the runtime role.');
                }
                $this->write($pair[1], ['event' => 'ready', 'backend' => (int) $identity->pid]);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Resolver start barrier timed out.');
                }

                app(RlsContextRunner::class)->runAsService(function () use ($mode, $pair): void {
                    $resolver = app(CaseAuthorizationResolver::class);
                    if ($mode === 'integrated') {
                        $resolver->resolveIntegratedForUpdate(
                            new AssessmentPrincipal($this->fixture['participant'], $this->fixture['organization'], $this->fixture['attempt']),
                            GenericAssessmentInstrument::Ist,
                        );
                        $this->write($pair[1], ['event' => 'resolved']);
                        if (fgets($pair[1]) !== "release\n") {
                            throw new RuntimeException('Resolver release barrier timed out.');
                        }

                        return;
                    }

                    $resolver->resolveParticipantForUpdate(
                        new ParticipantPrincipal($this->fixture['participant'], $this->fixture['organization']),
                        GenericAssessmentInstrument::Ist,
                    );
                });
                $this->write($pair[1], ['event' => 'committed']);
            } catch (Throwable $exception) {
                $this->write($pair[1], [
                    'event' => $exception instanceof CaseAuthorizationRejected ? 'rejected' : 'unexpected',
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }

        fclose($pair[1]);
        stream_set_timeout($pair[0], 15);
        $ready = $this->event($pair[0]);
        $this->assertSame('ready', $ready['event']);

        return ['pid' => $pid, 'backend' => $ready['backend'], 'socket' => $pair[0]];
    }

    private function assertBackendWaitsOnLock(int $backend): void
    {
        $deadline = microtime(true) + 5;
        do {
            $activity = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend]);
            if ($activity?->wait_event_type === 'Lock') {
                $this->assertSame('Lock', $activity->wait_event_type);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('Participant resolver did not wait on the retained outer transaction lock.');
    }

    /**
     * @param  resource  $socket
     * @return array<string, mixed>
     */
    private function event($socket): array
    {
        $line = fgets($socket);
        if ($line === false) {
            throw new RuntimeException('Resolver worker closed without an event.');
        }

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $event
     */
    private function write($socket, array $event): void
    {
        fwrite($socket, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /** @param array{pid:int,backend:int,socket:resource} $worker */
    private function stop(array $worker): void
    {
        fclose($worker['socket']);
        pcntl_waitpid($worker['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function cleanupFixture(): void
    {
        $runId = getenv('ORG_TEST_RUN_ID');
        $database = DB::selectOne(<<<'SQL'
            SELECT shobj_description(oid, 'pg_database') AS marker
            FROM pg_database
            WHERE datname = current_database()
            SQL);
        if (! is_string($runId) || $runId === '' || $database?->marker !== "ONCAM_ORG_TEST:{$runId}") {
            throw new RuntimeException('Resolver concurrency cleanup requires the marked disposable database.');
        }

        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.case_authorization_cleanup', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        $owner = DB::connection('case_authorization_cleanup');

        try {
            $owner->transaction(function () use ($owner): void {
                $organization = $this->fixture['organization'];
                $participant = $this->fixture['participant'];
                $owner->statement("SET LOCAL session_replication_role = 'replica'");
                $this->assertSame(1, $owner->table('payment_methods')
                    ->where('id', $this->fixture['method'])->count());
                foreach (['assessment_entitlements', 'assessment_bill_items', 'assessment_bills', 'assessment_charges'] as $table) {
                    $owner->table($table)->where('organization_id', $organization)->delete();
                }
                foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                    $owner->table($table)->where('participant_id', $participant)->delete();
                }
                $owner->table('assessment_participants')->where('participant_id', $participant)->delete();
                $owner->table('assessment_cases')->where('participant_id', $participant)->delete();
                $owner->table('integration_clients')->where('organization_id', $organization)->delete();
                $owner->table('package_items')->where('package_id', $this->fixture['package'])->delete();
                $owner->table('packages')->where('id', $this->fixture['package'])->delete();
                $owner->table('participants')->where('id', $participant)->delete();
                $this->assertSame(1, $owner->table('payment_methods')
                    ->where('id', $this->fixture['method'])->delete());
                $this->assertSame(0, $owner->table('payment_methods')
                    ->where('id', $this->fixture['method'])->count());
                $owner->table('branches')->where('id', $organization)->delete();
            });
        } finally {
            DB::purge('case_authorization_cleanup');
            config()->set('database.connections.case_authorization_cleanup', null);
        }
    }
}
