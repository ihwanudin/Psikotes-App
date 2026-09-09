<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\ProvisionAssessmentParticipant;
use App\Models\AssessmentCase;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class GenericAssessmentCaseProvisioningTest extends TestCase
{
    /** @var array{organization:int, package:int} */
    private array $fixture;

    private IntegrationClient $client;

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->code = 'G_'.Str::ulid();
        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $organization = Branch::create([
                'code' => $this->code,
                'ref_code' => $this->code,
                'name' => 'Generic PG',
                'organization_code' => $this->code,
                'display_name' => 'Generic PG',
                'status' => 'ACTIVE',
                'allowed_funding_modes' => ['SPONSORED'],
                'is_active' => true,
            ]);
            $package = TestPackage::create([
                'code' => $this->code,
                'name' => 'Generic PG',
                'amount' => 1000,
                'currency' => 'IDR',
                'is_active' => true,
            ]);
            $package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);
            $package->items()->create(['test_type' => 'dass21', 'sort_order' => 2]);
            $this->client = IntegrationClient::create([
                'organization_id' => $organization->id,
                'client_id' => $this->code,
                'credential_reference' => 'synthetic',
                'enabled' => true,
            ])->refresh();
            IntegrationSource::create([
                'integration_client_id' => $this->client->id,
                'source_system' => $this->code,
                'contract_version' => 'v1',
                'authentication_mode' => 'HMAC_SHA256',
                'allowed_assessment_packages' => [$this->code],
                'participant_provisioning_mode' => 'API',
                'commercial_mode' => 'CONTRACT',
                'allowed_funding_modes' => ['SPONSORED'],
                'status' => 'ACTIVE',
            ]);

            return ['organization' => $organization->id, 'package' => $package->id];
        });
    }

    public function test_same_key_race_commits_one_bound_case(): void
    {
        $results = $this->race(fn () => $this->provision(), fn () => $this->provision());

        $this->assertSame([false, true], $this->replayFlags($results));
        $this->assertSame($results[0]['assessment_attempt_id'], $results[1]['assessment_attempt_id']);
        $this->assertRows(1, 1);
    }

    public function test_different_key_same_logical_race_commits_one_bound_case(): void
    {
        $results = $this->race(fn () => $this->provision(), fn () => $this->provision(key: 'other-key'));

        $this->assertSame([false, true], $this->replayFlags($results));
        $this->assertSame($results[0]['assessment_attempt_id'], $results[1]['assessment_attempt_id']);
        $this->assertRows(1, 1);
    }

    public function test_key_and_logical_collision_fails_without_new_case_or_side_effect(): void
    {
        $firstKey = 'generic-first';
        $second = [...$this->payload(), 'externalCandidateId' => 'CANDIDATE-2'];
        $this->provision(key: $firstKey);
        $this->provision($second, 'generic-second');

        try {
            $this->provision($second, $firstKey);
            $this->fail('Ambiguous replay accepted.');
        } catch (IdempotencyConflict) {
            $this->assertRows(2, 2);
            $this->assertSideEffects(4, 2, 2);
        }
    }

    public function test_failure_after_case_and_attempt_insert_leaves_no_orphan(): void
    {
        $once = true;
        DB::listen(function (QueryExecuted $query) use (&$once): void {
            if ($once && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'assessment_participants')) {
                $once = false;
                throw new RuntimeException('synthetic generic provisioning crash');
            }
        });

        try {
            $this->provision();
            $this->fail('Expected crash.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic generic provisioning crash', $exception->getMessage());
        }

        $this->assertRows(0, 0);
        $this->assertSideEffects(0, 0, 0);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'sourceSystem' => $this->code,
            'externalCandidateId' => 'CANDIDATE-1',
            'externalProcessId' => 'PROCESS-1',
            'externalRegistrationId' => 'REGISTRATION-1',
            'assessmentRoundId' => 'ROUND-1',
            'organizationCode' => $this->code,
            'assessmentPackageCode' => $this->code,
            'fundingMode' => 'SPONSORED',
            'profile' => [
                'fullName' => 'Generic Participant',
                'birthDate' => '2000-01-01',
                'gender' => 'MALE',
                'educationLevel' => 'SMA',
                'email' => 'generic@example.test',
                'phone' => '628123456789',
            ],
            'metadata' => ['cohortCode' => 'PG'],
        ];
    }

    /** @param array<string, mixed> $override
     * @return array{participant_id:int, assessment_attempt_id:string, assessment_status:string, replayed:bool}
     */
    private function provision(array $override = [], string $key = 'generic-key'): array
    {
        return app(ProvisionAssessmentParticipant::class)->handle(
            [...$this->payload(), ...$override],
            $this->client,
            $key,
        );
    }

    private function assertRows(int $participants, int $attempts): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($participants, $attempts): void {
            $this->assertSame($participants, DB::table('participants')->where('branch_id', $this->fixture['organization'])->count());
            $this->assertSame($attempts, DB::table('assessment_participants')->where('organization_id', $this->fixture['organization'])->count());
            $this->assertSame($attempts, DB::table('assessment_cases')->where('organization_id', $this->fixture['organization'])->count());
            foreach (AssessmentParticipant::with('assessmentCase')->where('organization_id', $this->fixture['organization'])->get() as $attempt) {
                $case = $attempt->assessmentCase;
                $this->assertInstanceOf(AssessmentCase::class, $case);
                $this->assertSame($attempt->assessment_attempt_id, $case->public_id);
                $this->assertSame($attempt->participant_id, $case->participant_id);
                $this->assertSame($attempt->organization_id, $case->organization_id);
                $this->assertSame($attempt->package_id, $case->package_id);
                $this->assertSame('INTEGRATED', $case->origin);
                $this->assertNull($case->intended_field_snapshot);
            }
        });
    }

    private function assertSideEffects(int $entitlements, int $outbox, int $audit): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($entitlements, $outbox, $audit): void {
            $this->assertSame($entitlements, DB::table('entitlements')
                ->whereIn('participant_id', DB::table('participants')->where('branch_id', $this->fixture['organization'])->select('id'))
                ->count());
            $this->assertSame($outbox, DB::table('outbox_messages')->where('topic', 'psychotest.assessment-event')
                ->whereIn('aggregate_id', DB::table('assessment_participants')->where('organization_id', $this->fixture['organization'])->selectRaw('id::text'))
                ->count());
            $this->assertSame($audit, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->where('action', 'assessment_participant.provisioned')->count());
        });
    }

    /** @param array<int, array<string, mixed>> $results
     * @return list<bool>
     */
    private function replayFlags(array $results): array
    {
        $flags = array_column($results, 'replayed');
        sort($flags);

        return $flags;
    }

    /** Independent runtime-role processes wait on a shared advisory-lock start barrier. */
    private function race(callable $first, callable $second): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $gate = random_int(1, 2_000_000_000);
        $gateHeld = false;
        $workers = [];
        try {
            foreach ([$first, $second] as $callback) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false || ($pid = pcntl_fork()) === -1) {
                    throw new RuntimeException('Unable to create concurrency worker.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    foreach ($workers as $worker) {
                        fclose($worker['socket']);
                    }
                    DB::purge('pgsql');
                    stream_set_timeout($pair[1], 15);
                    try {
                        $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                        if ($identity->name !== 'psikotes_runtime') {
                            throw new RuntimeException('Worker must use runtime role.');
                        }
                        fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                        if (fgets($pair[1]) !== "go\n") {
                            throw new RuntimeException('Barrier timed out.');
                        }
                        DB::select('SELECT pg_advisory_lock_shared(?)', [$gate]);
                        DB::select('SELECT pg_advisory_unlock_shared(?)', [$gate]);
                        $result = $callback();
                    } catch (Throwable $exception) {
                        $result = ['class' => $exception::class, 'error' => $exception->getMessage()];
                    }
                    fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                    fclose($pair[1]);
                    DB::disconnect('pgsql');
                    exit(0);
                }
                fclose($pair[1]);
                stream_set_timeout($pair[0], 15);
                $workers[] = ['pid' => $pid, 'socket' => $pair[0]];
            }
            $backendIds = [];
            foreach ($workers as $worker) {
                $backendIds[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR)['pid'];
            }
            DB::select('SELECT pg_advisory_lock(?)', [$gate]);
            $gateHeld = true;
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }
            $this->assertNotSame($backendIds[0], $backendIds[1]);
            foreach ($backendIds as $backendId) {
                $deadline = microtime(true) + 5;
                do {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
                    if ($waiting?->wait_event_type === 'Lock') {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertSame('Lock', $waiting?->wait_event_type, 'Both workers must reach the start barrier.');
            }
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            $gateHeld = false;

            $results = [];
            foreach ($workers as $worker) {
                $results[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if ($gateHeld) {
                DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            }
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }
}
