<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\FinalizeAssessmentBill;
use App\Data\Payments\PaymentEvent;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\PaymentStatus;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

/** Committed synthetic rows let independent runtime backends compete on the organization mutex. */
final class AssessmentBillPaymentFinalizationTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        Date::setTestNow(now()->startOfSecond());
        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $fixture = AssessmentAccessFixture::create();
            DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'PROVISIONED',
                'funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => json_encode([
                    'checkout_contract_version' => 'checkout-v2',
                    'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
                ], JSON_THROW_ON_ERROR),
            ]);
            DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])
                ->update(['status' => 'locked', 'ready_at' => null]);
            DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
            DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
                'status' => 'pending', 'paid_at' => null, 'gateway_ref' => 'xendit-invoice-reference',
            ]);

            return [...$fixture, 'reference' => DB::table('assessment_bills')
                ->where('id', $fixture['bill'])->value('public_reference')];
        });
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $organization = $this->fixture['organization'];
            $method = DB::table('assessment_bills')->where('id', $this->fixture['bill'])->value('payment_method_id');
            DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->where('aggregate_id', (string) $this->fixture['attempt'])->delete();
            DB::table('audit_logs')->where('branch_id', $organization)->delete();
            foreach (['assessment_entitlements', 'assessment_bill_items', 'assessment_bills', 'assessment_charges'] as $table) {
                DB::table($table)->where('organization_id', $organization)->delete();
            }
            foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                DB::table($table)->where('participant_id', $this->fixture['participant'])->delete();
            }
            DB::table('payment_methods')->where('id', $method)->delete();
        });
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_two_runtime_finalizers_commit_one_settlement_audit_and_activation_set(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        [$first, $second] = $this->race(fn (): array => $this->finalize(), fn (): array => $this->finalize());
        $decisions = [$first['decision'] ?? null, $second['decision'] ?? null];
        sort($decisions);
        $this->assertSame(['replayed', 'settled'], $decisions);

        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame('paid', DB::table('assessment_bills')->where('id', $this->fixture['bill'])->value('status'));
            $this->assertNotNull(DB::table('assessment_bill_items')->where('id', $this->fixture['item'])->value('settled_at'));
            $this->assertSame(1, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->where('action', 'assessment_bill.paid')->count());
            $this->assertSame(1, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->where('action', 'assessment.activated')->count());
            $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->where('aggregate_id', (string) $this->fixture['attempt'])->count());
            $bill = DB::table('assessment_bills')->where('id', $this->fixture['bill'])->sole();
            $audit = DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->where('action', 'assessment_bill.paid')->sole();
            $anchor = CarbonImmutable::parse((string) $audit->occurred_at)->utc();
            $this->assertSame(
                CarbonImmutable::parse((string) $bill->paid_at)->utc()->format('Y-m-d H:i:s.uP'),
                CarbonImmutable::parse((string) DB::table('assessment_bill_items')
                    ->where('id', $this->fixture['item'])->value('settled_at'))->utc()->format('Y-m-d H:i:s.uP'),
            );
            $this->assertSame(
                app(RetentionPolicy::class)->expiresAt(RetentionDataClass::Audit, $anchor)->format('Y-m-d H:i:s.uP'),
                CarbonImmutable::parse((string) $audit->expires_at)->utc()->format('Y-m-d H:i:s.uP'),
            );
            $this->assertSame(
                '2029-02-28 03:15:00.000000+00:00',
                app(RetentionPolicy::class)->expiresAt(
                    RetentionDataClass::Audit,
                    CarbonImmutable::parse('2024-02-29 10:15:00+07:00')->utc(),
                )->format('Y-m-d H:i:s.uP'),
            );
        });
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function finalize(): array
    {
        return app(FinalizeAssessmentBill::class)->execute(new PaymentEvent(
            eventId: 'xendit-invoice-event-paid',
            providerReference: 'xendit-invoice-reference',
            merchantReference: $this->fixture['reference'],
            status: PaymentStatus::Paid,
            occurredAt: now(),
            amount: 100,
            currency: 'IDR',
        ));
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
                if ($pair === false || ($pid = pcntl_fork()) === -1) {
                    throw new RuntimeException('Unable to create concurrent finalizer worker.');
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
                            throw new RuntimeException('Finalizer barrier timed out.');
                        }
                        $result = $callback();
                    } catch (Throwable $exception) {
                        $result = ['error' => $exception->getMessage(), 'class' => $exception::class];
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
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
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
                    $this->assertSame('Lock', $waiting?->wait_event_type, 'Both finalizers must overlap and wait.');
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
