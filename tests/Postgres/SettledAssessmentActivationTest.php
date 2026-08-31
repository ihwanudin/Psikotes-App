<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\ActivateSettledAssessment;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture as Fixture;
use Throwable;

/** Committed synthetic rows permit independent runtime backends to compete on real locks. */
final class SettledAssessmentActivationTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        Date::setTestNow(now()->startOfSecond());
        $this->f = app(RlsContextRunner::class)->runAsService(function (): array {
            $f = Fixture::create();
            DB::table('assessment_participants')->where('id', $f['attempt'])->update(['assessment_status' => 'PROVISIONED']);
            DB::table('assessment_entitlements')->where('id', $f['entitlement'])->update(['status' => 'locked', 'ready_at' => null]);

            return $f;
        });
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $org = $this->f['organization'];
            $method = DB::table('assessment_bills')->where('id', $this->f['bill'])->value('payment_method_id');
            DB::table('outbox_messages')->where('topic', 'assessment.activation')->where('aggregate_id', (string) $this->f['attempt'])->delete();
            DB::table('audit_logs')->where('branch_id', $org)->delete();
            foreach (['assessment_entitlements', 'assessment_bill_items', 'assessment_bills', 'assessment_charges',
                'assessment_participants', 'integration_clients'] as $table) {
                DB::table($table)->where('organization_id', $org)->delete();
            }
            foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                DB::table($table)->where('participant_id', $this->f['participant'])->delete();
            }
            DB::table('participants')->where('id', $this->f['participant'])->delete();
            DB::table('package_items')->where('package_id', $this->f['package'])->delete();
            DB::table('packages')->where('id', $this->f['package'])->delete();
            DB::table('payment_methods')->where('id', $method)->delete();
            DB::table('branches')->where('id', $org)->delete();
        });
        Date::setTestNow();
        parent::tearDown();
    }

    private function principal(): AssessmentPrincipal
    {
        return new AssessmentPrincipal($this->f['participant'], $this->f['organization'], $this->f['attempt']);
    }

    private function activate(): array
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(ActivateSettledAssessment::class)->execute($this->principal()));
    }

    private function assertStored(string $status, int $messages): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($status, $messages): void {
            $this->assertSame($status, DB::table('assessment_entitlements')->where('id', $this->f['entitlement'])->value('status'));
            $this->assertSame($messages, DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->where('aggregate_id', (string) $this->f['attempt'])->count());
            $this->assertSame($messages, DB::table('audit_logs')->where('branch_id', $this->f['organization'])->count());
        });
    }

    public function test_runtime_activates_once_and_readonly_gate_accepts_result(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertSame(['ist'], $this->activate());
        $this->assertSame([], $this->activate());
        app(RlsContextRunner::class)->runAsService(fn () => $this->assertSame($this->f['entitlement'],
            app(AssessmentEntitlementGate::class)->assertReady($this->principal(), 'ist')->id));
        $this->assertStored('ready', 1);
    }

    public function test_participant_context_cannot_activate_directly(): void
    {
        $this->expectException(LogicException::class);
        app(RlsContextRunner::class)->run(new RlsContext('participant', $this->f['organization'], $this->f['participant']),
            fn () => app(ActivateSettledAssessment::class)->execute($this->principal()));
    }

    public function test_outbox_crash_rolls_back_to_savepoint_even_if_caller_commits(): void
    {
        $failOnce = true;
        DB::listen(function (QueryExecuted $query) use (&$failOnce): void {
            if ($failOnce && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'outbox_messages')) {
                $failOnce = false;
                throw new RuntimeException('synthetic outbox crash');
            }
        });
        app(RlsContextRunner::class)->runAsService(function (): void {
            try {
                $this->activate();
                $this->fail('Expected outbox crash');
            } catch (RuntimeException $e) {
                $this->assertSame('synthetic outbox crash', $e->getMessage());
            }
        });
        $this->assertStored('locked', 0);
        $this->assertSame(['ist'], $this->activate());
        $this->assertStored('ready', 1);
    }

    public function test_concurrent_retries_activate_and_enqueue_only_once(): void
    {
        [$first, $second] = $this->race(fn () => $this->activate(), fn () => $this->activate());
        $this->assertSame(['ist'], $first);
        $this->assertSame([], $second);
        $this->assertStored('ready', 1);
    }

    public function test_consent_withdrawal_committed_while_activation_waits_is_rechecked(): void
    {
        [$first, $second] = $this->race(function (): array {
            app(RlsContextRunner::class)->runAsService(function (): void {
                DB::table('branches')->where('id', $this->f['organization'])->lockForUpdate()->first();
                DB::table('consent_records')->where('participant_id', $this->f['participant'])
                    ->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            });

            return ['withdrawn'];
        }, fn () => $this->activate());
        $this->assertSame(['withdrawn'], $first);
        $this->assertSame([], $second);
        $this->assertStored('locked', 0);
    }

    /** Independent processes, observed lock waits, and a parent barrier; no sequential concurrency claim. */
    private function race(callable $first, callable $second): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        try {
            foreach ([$first, $second] as $callback) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false) {
                    throw new RuntimeException('Unable to create barrier socket.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork worker.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    foreach ($workers as $worker) {
                        fclose($worker['socket']);
                    }
                    stream_set_timeout($pair[1], 15);
                    try {
                        $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                        if ($identity->name !== 'psikotes_runtime') {
                            throw new RuntimeException('Worker must use runtime role.');
                        }
                        DB::statement("SET lock_timeout = '10s'");
                        DB::statement("SET statement_timeout = '12s'");
                        fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                        if (fgets($pair[1]) !== "go\n") {
                            throw new RuntimeException('Barrier timed out.');
                        }
                        $result = $callback();
                    } catch (Throwable $e) {
                        $result = ['error' => $e->getMessage(), 'class' => $e::class];
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
            $this->assertNotSame($backendIds[0], $backendIds[1]);
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendIds): void {
                DB::table('branches')->where('id', $this->f['organization'])->lockForUpdate()->first();
                foreach ($workers as $index => $worker) {
                    fwrite($worker['socket'], "go\n");
                    $deadline = microtime(true) + 5;
                    do {
                        $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendIds[$index]]);
                        if ($waiting?->wait_event_type === 'Lock') {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    $this->assertSame('Lock', $waiting?->wait_event_type, 'Both workers must overlap and wait.');
                }
            });
            $results = [];
            foreach ($workers as $worker) {
                $results[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }
}
