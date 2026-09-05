<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Actions\Payments\SetPaymentMethodActivation;
use App\Actions\Payments\UpdateFundingPolicy;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBillItem;
use App\Models\Participant;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentPreviewFixture as Fixture;
use Throwable;

/** Real independent runtime connections; committed synthetic fixtures are removed by exact IDs. */
final class AssessmentBillReservationTest extends TestCase
{
    private array $fixtures = [];

    private array $admins = [];

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency proof requires pcntl; do not skip.');
        $this->assertSame(0, DB::transactionLevel());
        app(RlsContextRunner::class)->runAsService(function (): void {
            $fixture = Fixture::create();
            $this->addMandatoryDass($fixture['package']);
            $this->fixtures[] = $fixture;
            $org = $this->fixtures[0]['organization'];
            for ($i = 0; $i < 2; $i++) {
                $fixture = Fixture::create(['organization' => $org]);
                $this->addMandatoryDass($fixture['package']);
                $this->fixtures[] = $fixture;
            }
            foreach ([AdminRole::BranchAdmin, AdminRole::BranchAdmin, AdminRole::SuperAdmin] as $role) {
                $this->admins[] = Admin::create(['branch_id' => $org, 'name' => 'Synthetic concurrency',
                    'email' => Str::ulid().'@example.test', 'password' => 'synthetic-password', 'role' => $role]);
            }
            // Share only this test's channel, not any active application configuration.
            $this->method = DB::table('payment_methods')->insertGetId(['code' => 'manual_transfer', 'display_name' => 'Synthetic', 'is_active' => true]);
        });
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $org = $this->fixtures[0]['organization'];
            DB::table('assessment_bill_items')->where('organization_id', $org)->delete();
            DB::table('assessment_bills')->where('organization_id', $org)->delete();
            DB::table('assessment_charges')->where('organization_id', $org)->delete();
            DB::table('audit_logs')->where('branch_id', $org)->delete();
            DB::table('admins')->whereIn('id', array_map(fn ($admin) => $admin->id, $this->admins))->delete();
            foreach ($this->fixtures as $fixture) {
                $packageCode = DB::table('packages')->where('id', $fixture['package'])->value('code');
                DB::table('assessment_participants')->where('id', $fixture['attempt'])->delete();
                DB::table('integration_sources')->where('id', $fixture['source'])->delete();
                DB::table('integration_clients')->where('id', $fixture['client'])->delete();
                DB::table('participants')->where('id', $fixture['participant'])->delete();
                DB::table('package_items')->where('package_id', $fixture['package'])->delete();
                DB::table('packages')->where('id', $fixture['package'])->delete();
                DB::table('payment_methods')->where('code', $packageCode)->delete();
            }
            DB::table('payment_methods')->where('id', $this->method)->delete();
            DB::table('branches')->where('id', $org)->delete();
        });
        parent::tearDown();
    }

    private function preview(array $selection, ?Participant $participant = null): string
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(PreviewAssessmentBill::class)->execute(
            $this->fixtures[0]['organization'], $selection, $participant === null ? PayerType::Organization : PayerType::SelfPay,
            $participant?->id)['selectionHash']);
    }

    private function reserve(Admin|Participant $actor, array $selection, string $hash, string $key): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($actor, $selection, $hash, $key): array {
            $bill = app(ReserveAssessmentBill::class)->execute($actor, $selection, $this->method, $hash, $key);

            return ['bill' => $bill->id, 'amount' => $bill->amount, 'count' => $bill->item_count];
        });
    }

    private function addMandatoryDass(int $packageId): void
    {
        DB::table('package_items')->insert([
            'package_id' => $packageId, 'test_type' => 'dass21', 'sort_order' => 2,
        ]);
    }

    public function test_two_admins_retry_same_intent_get_the_same_single_bill(): void
    {
        $selection = array_map(Fixture::selection(...), $this->fixtures);
        $hash = $this->preview($selection);
        [$first, $second] = $this->race(
            fn () => $this->reserve($this->admins[0], $selection, $hash, 'same-intent'),
            fn () => $this->reserve($this->admins[1], array_reverse($selection), $hash, 'same-intent'));
        $this->assertArrayNotHasKey('error', $first);
        $this->assertSame($first, $second);
        $this->assertStored(1, 3, 300);
    }

    public function test_overlapping_batches_have_one_winner_and_no_partial_loser(): void
    {
        $firstList = array_map(Fixture::selection(...), array_slice($this->fixtures, 0, 2));
        $secondList = array_map(Fixture::selection(...), array_slice($this->fixtures, 1, 2));
        $firstHash = $this->preview($firstList);
        $secondHash = $this->preview($secondList);
        [$first, $second] = $this->race(
            fn () => $this->reserve($this->admins[0], $firstList, $firstHash, 'batch-one'),
            fn () => $this->reserve($this->admins[1], $secondList, $secondHash, 'batch-two'));
        $this->assertArrayNotHasKey('error', $first);
        $this->assertSame('PREVIEW_CHANGED', $second['error']);
        $this->assertStored(1, 2, 200);
    }

    public function test_self_and_organization_compete_for_the_same_attempt_claim(): void
    {
        $participant = app(RlsContextRunner::class)->runAsService(fn () => Participant::findOrFail($this->fixtures[0]['participant']));
        $self = [Fixture::selection($this->fixtures[0])];
        $batch = array_map(Fixture::selection(...), $this->fixtures);
        $selfHash = $this->preview($self, $participant);
        $batchHash = $this->preview($batch);
        [$first, $second] = $this->race(
            fn () => $this->reserve($participant, $self, $selfHash, 'self-intent'),
            fn () => $this->reserve($this->admins[1], $batch, $batchHash, 'batch-intent'));
        $this->assertArrayNotHasKey('error', $first);
        $this->assertSame('PREVIEW_CHANGED', $second['error']);
        $this->assertStored(1, 1, 100);
    }

    public function test_policy_off_queued_first_prevents_waiting_reservation(): void
    {
        $selection = array_map(Fixture::selection(...), $this->fixtures);
        $hash = $this->preview($selection);
        [$first, $second] = $this->race(function (): array {
            app(UpdateFundingPolicy::class)->forSource($this->admins[2], $this->fixtures[0]['organization'],
                $this->fixtures[0]['source'], ['allowed_payer_types' => ['self'], 'locked_payer_type' => null]);

            return ['off' => true];
        }, fn () => $this->reserve($this->admins[1], $selection, $hash, 'waiting-intent'));
        $this->assertSame(['off' => true], $first);
        $this->assertSame('PREVIEW_CHANGED', $second['error']);
        $this->assertStored(0, 0, 0);
    }

    public function test_same_key_with_different_selection_is_not_replayed_to_second_admin(): void
    {
        $firstList = [Fixture::selection($this->fixtures[0])];
        $secondList = [Fixture::selection($this->fixtures[1])];
        $firstHash = $this->preview($firstList);
        $secondHash = $this->preview($secondList);
        [$first, $second] = $this->race(
            fn () => $this->reserve($this->admins[0], $firstList, $firstHash, 'same-key'),
            fn () => $this->reserve($this->admins[1], $secondList, $secondHash, 'same-key'));
        $this->assertArrayNotHasKey('error', $first);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $second['error']);
        $this->assertStored(1, 1, 100);
    }

    public function test_payment_method_off_queued_first_prevents_new_reservation(): void
    {
        $selection = [Fixture::selection($this->fixtures[0])];
        $hash = $this->preview($selection);
        [$first, $second] = $this->race(function (): array {
            app(SetPaymentMethodActivation::class)->handle($this->admins[2], $this->method, false);

            return ['off' => true];
        }, fn () => $this->reserve($this->admins[1], $selection, $hash, 'method-intent'), 'payment_methods', $this->method);
        $this->assertSame(['off' => true], $first);
        $this->assertSame('PAYMENT_METHOD_NOT_AVAILABLE', $second['error']);
        $this->assertStored(0, 0, 0);
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('audit_logs')
            ->where('action', 'payment_method.activation_changed')->where('subject_id', (string) $this->method)->delete());
    }

    public function test_price_update_queued_first_invalidates_waiting_confirmation(): void
    {
        $selection = [Fixture::selection($this->fixtures[0])];
        $hash = $this->preview($selection);
        $package = $this->fixtures[0]['package'];
        [$first, $second] = $this->race(function () use ($package): array {
            app(RlsContextRunner::class)->runAsService(fn () => DB::table('packages')->where('id', $package)->update(['amount' => 200]));

            return ['updated' => true];
        }, fn () => $this->reserve($this->admins[1], $selection, $hash, 'price-intent'), 'packages', $package);
        $this->assertSame(['updated' => true], $first);
        $this->assertSame('PREVIEW_CHANGED', $second['error']);
        $this->assertStored(0, 0, 0);
    }

    public function test_postgres_failure_after_items_exist_rolls_back_every_reservation_row(): void
    {
        $selection = array_map(Fixture::selection(...), $this->fixtures);
        $hash = $this->preview($selection);
        $event = 'eloquent.creating: '.AssessmentBillItem::class;
        $count = 0;
        Event::listen($event, function () use (&$count): void {
            if (++$count === 3) {
                throw new RuntimeException('synthetic-write-failure');
            }
        });
        try {
            $this->reserve($this->admins[0], $selection, $hash, 'rollback-intent');
            $this->fail('Failure injection did not run.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic-write-failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame(3, $count);
        $this->assertStored(0, 0, 0);
    }

    private function assertStored(int $bills, int $items, int $amount): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($bills, $items, $amount): void {
            $org = $this->fixtures[0]['organization'];
            $this->assertSame($bills, DB::table('assessment_bills')->where('organization_id', $org)->count());
            $this->assertSame($items, DB::table('assessment_bill_items')->where('organization_id', $org)->count());
            $this->assertSame($items, DB::table('assessment_charges')->where('organization_id', $org)->count());
            $this->assertSame($amount, (int) DB::table('assessment_bill_items')->where('organization_id', $org)->sum('amount'));
            $this->assertSame(0, DB::table('assessment_bill_items')->where('organization_id', $org)->whereNotNull('settled_at')->count());
            $this->assertSame(0, DB::table('assessment_entitlements')->where('organization_id', $org)->count());
            $this->assertSame($bills, DB::table('audit_logs')->where('branch_id', $org)->where('action', 'assessment_bill.reserved')->count());
        });
    }

    /** Start two independent backends, prove both are blocked, then release the organization lock. */
    private function race(callable $first, callable $second, string $lockTable = 'branches', ?int $lockId = null): array
    {
        $this->assertSame(0, DB::transactionLevel());
        DB::purge('pgsql'); // No PDO socket may be inherited across fork.
        $workers = [];
        try {
            foreach ([$first, $second] as $callback) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false) {
                    throw new RuntimeException('Unable to create barrier socket.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork reservation worker.');
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
                            throw new RuntimeException('Worker is not runtime.');
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
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendIds, $lockTable, $lockId): void {
                DB::table($lockTable)->where('id', $lockId ?? $this->fixtures[0]['organization'])->lockForUpdate()->first();
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
                    $this->assertSame('Lock', $waiting?->wait_event_type, 'Worker must actually overlap and wait on a database lock.');
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
