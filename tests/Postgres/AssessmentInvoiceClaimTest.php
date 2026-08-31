<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
use App\Models\OutboxMessage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\DispatchIntegrationOutbox;
use App\Services\Notifications\DispatchNotificationOutbox;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentPreviewFixture as Fixture;
use Throwable;

/** Disposable PostgreSQL only, independent non-owner processes and observed lock waits. */
final class AssessmentInvoiceClaimTest extends TestCase
{
    private array $fixtures = [];

    private Admin $admin;

    private AssessmentBill $bill;

    private int $method;

    private array $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(function_exists('pcntl_fork'), 'Real concurrency requires pcntl, never skip.');
        $this->assertSame(0, DB::transactionLevel());
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertNotSame($role->name, DB::selectOne("SELECT tableowner FROM pg_tables WHERE tablename = 'assessment_bills'")->tableowner);
        $this->previous = ['enabled' => config('assessment_integration.checkout.enabled'), 'duration' => config('assessment_billing.invoice_duration_hours'),
            'bus' => Bus::getFacadeRoot(), 'queue' => Queue::getFacadeRoot(), 'http' => Http::getFacadeRoot(), 'now' => Date::getTestNow()];
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_billing.invoice_duration_hours', 24);
        Date::setTestNow('2026-09-01T00:00:00Z');
        Http::preventStrayRequests();
        Http::fake([]);
        Bus::fake();
        Queue::fake();
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->fixtures[] = Fixture::create();
            $org = $this->fixtures[0]['organization'];
            $this->fixtures[] = Fixture::create(['organization' => $org]);
            DB::table('assessment_participants')->where('organization_id', $org)->update(['funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
            $this->admin = Admin::create(['branch_id' => $org, 'name' => 'Synthetic claim', 'email' => Str::ulid().'@example.test',
                'password' => 'synthetic', 'role' => AdminRole::BranchAdmin]);
            $this->method = DB::table('payment_methods')->insertGetId(['code' => 'xendit', 'display_name' => 'Synthetic claim', 'is_active' => true]);
            $selection = array_map(Fixture::selection(...), $this->fixtures);
            $preview = app(PreviewAssessmentBill::class)->execute($org, $selection, PayerType::Organization);
            $this->bill = app(ReserveAssessmentBill::class)->execute($this->admin, $selection, $this->method, $preview['selectionHash'], 'pg-claim');
        });
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
            Queue::assertNothingPushed();
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $org = $this->fixtures[0]['organization'];
                DB::table('outbox_messages')->where('aggregate_type', AssessmentBill::class)->where('aggregate_id', (string) $this->bill->id)->delete();
                DB::table('assessment_bill_items')->where('organization_id', $org)->delete();
                DB::table('assessment_bills')->where('organization_id', $org)->delete();
                DB::table('assessment_charges')->where('organization_id', $org)->delete();
                DB::table('audit_logs')->where('branch_id', $org)->delete();
                DB::table('admins')->where('id', $this->admin->id)->delete();
                foreach ($this->fixtures as $f) {
                    $code = DB::table('packages')->where('id', $f['package'])->value('code');
                    DB::table('assessment_participants')->where('id', $f['attempt'])->delete();
                    DB::table('integration_sources')->where('id', $f['source'])->delete();
                    DB::table('integration_clients')->where('id', $f['client'])->delete();
                    DB::table('participants')->where('id', $f['participant'])->delete();
                    DB::table('package_items')->where('package_id', $f['package'])->delete();
                    DB::table('packages')->where('id', $f['package'])->delete();
                    DB::table('payment_methods')->where('code', $code)->delete();
                }
                DB::table('payment_methods')->where('id', $this->method)->delete();
                DB::table('branches')->where('id', $org)->delete();
            });
            config()->set('assessment_integration.checkout.enabled', $this->previous['enabled']);
            config()->set('assessment_billing.invoice_duration_hours', $this->previous['duration']);
            Date::setTestNow($this->previous['now']);
            Bus::swap($this->previous['bus']);
            Queue::swap($this->previous['queue']);
            Http::swap($this->previous['http']);
            parent::tearDown();
        }
    }

    #[DataProvider('commitOrRollback')]
    public function test_waiting_worker_observes_outer_commit_or_rollback(bool $rollback): void
    {
        [$first, $waiting] = $this->whileClaimWaits(fn () => $this->claim(), $rollback);
        $this->assertSame('claimed', $first['decision']);
        $this->assertSame($rollback ? 'claimed' : 'replayed', $waiting['decision'], json_encode($waiting));
        if ($rollback) {
            $this->assertNotSame($first['messageId'], $waiting['messageId']);
        } else {
            $this->assertSame($first['messageId'], $waiting['messageId']);
        }
        $this->assertStored(1);
        app(RlsContextRunner::class)->runAsService(function () use ($waiting): void {
            $message = $this->message();
            $this->assertSame($waiting['messageId'], $message->message_id);
            $before = $message->getAttributes();
            Date::setTestNow('2026-09-04T00:00:00Z');
            config()->set('assessment_billing.invoice_duration_hours', -1); // Replay does not read new duration.
            $this->assertSame('replayed', $this->claim()['decision']);
            $this->assertSame($before, $message->fresh()->getAttributes());
            $this->assertSame(0, app(DispatchNotificationOutbox::class)->handle());
            $this->assertSame(0, app(DispatchIntegrationOutbox::class)->handle());
        });
    }

    public static function commitOrRollback(): iterable
    {
        yield 'commit' => [false];
        yield 'rollback' => [true];
    }

    public function test_waiting_claim_reloads_disabled_method_committed_by_other_process(): void
    {
        [, $waiting] = $this->whileClaimWaits(function (): array {
            DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);

            return ['disabled' => true];
        });
        $this->assertSame(DomainException::class, $waiting['class']);
        $this->assertSame('PAYMENT_METHOD_NOT_AVAILABLE', $waiting['error']);
        $this->assertStored(0);
    }

    public function test_runtime_roles_cannot_claim_or_write_outbox(): void
    {
        $runner = app(RlsContextRunner::class);
        $this->claim();
        $row = $runner->runAsService(fn () => $this->message()->getAttributes());
        unset($row['id']);
        $row['message_id'] = (string) Str::ulid();
        $row['deduplication_key'] = hash('sha256', $row['message_id']);
        foreach ([null, 'participant', 'branch_admin', 'super_admin', 'staff', 'psychologist'] as $role) {
            $run = fn (callable $callback) => $role === null ? $callback() : $runner->run(new RlsContext($role, $this->fixtures[0]['organization'], $this->fixtures[0]['participant']), $callback);
            try {
                $run(fn () => app(ClaimAssessmentBillInvoice::class)->execute($this->fixtures[0]['organization'], $this->bill->id));
                $this->fail('Non-service claim must fail.');
            } catch (LogicException $e) {
                $this->assertSame('Invoice claim requires service RLS context.', $e->getMessage());
            }
            try {
                $run(function () use ($row): void {
                    $this->assertSame(0, OutboxMessage::query()->where('aggregate_id', (string) $this->bill->id)->count());
                    DB::table('outbox_messages')->insert($row);
                });
                $this->fail('Outbox write must fail RLS.');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode());
            }
        }
        $this->assertStored(1);
    }

    #[DataProvider('failurePoints')]
    public function test_insert_failure_rolls_back_status_outbox_and_audit(string $table): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $query) use ($table, &$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert') && str_contains($query->sql, $table)) {
                $armed = false;
                throw new RuntimeException('synthetic-claim-failure');
            }
        });
        try {
            $this->claim();
            $this->fail('Injection must execute.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic-claim-failure', $e->getMessage());
            $this->assertFalse($armed);
        } finally {
            $armed = false;
        }
        $this->assertStored(0);
    }

    public static function failurePoints(): iterable
    {
        yield ['outbox_messages'];
        yield ['audit_logs'];
    }

    public function test_database_enforces_unique_intent_and_linkage(): void
    {
        $this->claim();
        foreach (['dedup', 'linkage', 'currency'] as $case) {
            try {
                app(RlsContextRunner::class)->runAsService(function () use ($case): void {
                    if ($case === 'dedup') {
                        $row = $this->message()->getAttributes();
                        unset($row['id']);
                        $row['message_id'] = (string) Str::ulid();
                        DB::table('outbox_messages')->insert($row);
                    } else {
                        DB::table('assessment_bill_items')->where('bill_id', $this->bill->id)->update($case === 'currency'
                            ? ['currency' => 'USD'] : ['participant_id' => $this->fixtures[0]['participant']]);
                    }
                });
                $this->fail('Database constraint must reject corruption.');
            } catch (QueryException $e) {
                $this->assertSame($case === 'dedup' ? '23505' : ($case === 'currency' ? '23514' : '23503'), $e->getCode());
            }
        }
        $this->assertStored(1);
    }

    public function test_corrupt_sum_count_expiry_and_initial_funding_replay_fail_closed(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->bill->update(['amount' => 201]);
            try {
                $this->claim();
                $this->fail('Corrupt total must fail.');
            } catch (DomainException $e) {
                $this->assertSame('INVOICE_TOTAL_INVALID', $e->getMessage());
            }
            $this->bill->update(['amount' => 200]);
            $this->bill->update(['item_count' => 3]);
            try {
                $this->claim();
                $this->fail('Corrupt count must fail.');
            } catch (DomainException $e) {
                $this->assertSame('INVOICE_TOTAL_INVALID', $e->getMessage());
            }
            $this->bill->update(['item_count' => 2]);
            $this->claim();
            $before = $this->message()->getAttributes();
            $payload = $this->message()->payload;
            $payload['requestedExpiresAt'] = '2026-09-03T00:00:00Z';
            $this->message()->forceFill(['payload' => $payload])->save();
            $corrupt = $this->message()->getAttributes();
            try {
                $this->claim();
                $this->fail('Changed expiry must fail.');
            } catch (DomainException $e) {
                $this->assertSame('INVOICE_INTENT_INVALID', $e->getMessage());
                $this->assertSame($corrupt, $this->message()->getAttributes());
            }
            $this->message()->forceFill(['payload' => json_decode($before['payload'], true, flags: JSON_THROW_ON_ERROR)])->save();
            $before = $this->message()->getAttributes();
            DB::table('assessment_participants')->where('id', $this->fixtures[0]['attempt'])->update([
                'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"INVOICED_TO_ORGANIZATION"}']);
            try {
                $this->claim();
                $this->fail('Changed initial decision must fail.');
            } catch (DomainException $e) {
                $this->assertSame('INVOICE_INTENT_INVALID', $e->getMessage());
                $this->assertSame($before, $this->message()->getAttributes());
            }
        });
        $this->assertStored(1, false);
    }

    public function test_positive_postgres_bigints_that_overflow_php_total_fail_before_claim(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $item = DB::table('assessment_bill_items')->where('bill_id', $this->bill->id)->orderBy('id')->first();
            DB::table('assessment_bill_items')->where('id', $item->id)->delete();
            $charge = AssessmentCharge::findOrFail($item->charge_id);
            $price = $charge->price_snapshot;
            $price['baseAmount'] = $price['amount'] = PHP_INT_MAX;
            $charge->update(['base_amount' => PHP_INT_MAX, 'amount' => PHP_INT_MAX, 'price_snapshot' => $price]);
            $row = (array) $item;
            $row['amount'] = PHP_INT_MAX;
            DB::table('assessment_bill_items')->insert($row);
            $this->bill->update(['amount' => PHP_INT_MAX]);
            try {
                $this->claim();
                $this->fail('Overflow must fail.');
            } catch (DomainException $e) {
                $this->assertSame('INVOICE_TOTAL_OVERFLOW', $e->getMessage());
            }
        });
        $this->assertStored(0);
    }

    private function claim(): array
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(ClaimAssessmentBillInvoice::class)->execute($this->fixtures[0]['organization'], $this->bill->id));
    }

    private function message(): OutboxMessage
    {
        return OutboxMessage::query()->where('aggregate_type', AssessmentBill::class)->where('aggregate_id', (string) $this->bill->id)->sole();
    }

    private function assertStored(int $count, bool $initialUnchanged = true): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($count, $initialUnchanged): void {
            $org = $this->fixtures[0]['organization'];
            $this->assertSame($count === 0 ? 'reserved' : 'issuing', $this->bill->fresh()->status);
            $this->assertSame($count, OutboxMessage::query()->where('aggregate_id', (string) $this->bill->id)->where('aggregate_type', AssessmentBill::class)->count());
            $this->assertSame($count, DB::table('audit_logs')->where('branch_id', $org)->where('action', 'assessment_bill.invoice_claimed')->count());
            if ($count === 1) {
                $this->assertSame(0, $this->message()->attempts);
                $this->assertSame('pending', $this->message()->status);
            }
            $this->assertSame(0, DB::table('assessment_bill_items')->where('bill_id', $this->bill->id)->whereNotNull('settled_at')->count());
            $this->assertSame(0, DB::table('assessment_charges')->where('organization_id', $org)->whereNotNull('free_settled_at')->count());
            $this->assertSame(0, DB::table('assessment_entitlements')->where('organization_id', $org)->count());
            $this->assertSame(0, DB::table('orders')->whereIn('participant_id', array_column($this->fixtures, 'participant'))->count());
            $this->assertNull($this->bill->fresh()->gateway_ref);
            foreach ($this->fixtures as $f) {
                $attempt = DB::table('assessment_participants')->where('id', $f['attempt'])->first();
                $this->assertSame('PROVISIONED', $attempt->assessment_status);
                $this->assertSame('INVOICED_TO_ORGANIZATION', $attempt->funding_mode);
                if ($initialUnchanged) {
                    $this->assertNull(json_decode($attempt->metadata, true)['checkout_initial_funding_mode']);
                }
            }
        });
    }

    /** Parent transaction holds the mutex; independent runtime child must wait until commit/rollback. */
    private function whileClaimWaits(callable $work, bool $rollback = false): array
    {
        DB::purge('pgsql'); // Fork before opening either PDO, never share an inherited connection.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Cannot create claim worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 15);
            try {
                $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                if ($identity->name !== 'psikotes_runtime') {
                    throw new RuntimeException('Child must use runtime role.');
                }
                DB::statement("SET lock_timeout = '10s'");
                DB::statement("SET statement_timeout = '12s'");
                fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Claim barrier timed out.');
                }
                $result = $this->claim();
                Http::assertNothingSent();
                Bus::assertNothingDispatched();
                Queue::assertNothingPushed();
                $this->assertNull(app(RlsContextRunner::class)->current());
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
        $first = null;
        try {
            $child = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR)['pid'];
            $this->assertNotSame($child, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
            try {
                app(RlsContextRunner::class)->runAsService(function () use ($work, $rollback, $pair, $child, &$first): void {
                    DB::table('branches')->where('id', $this->fixtures[0]['organization'])->lockForUpdate()->first();
                    $first = $work();
                    fwrite($pair[0], "go\n");
                    $deadline = microtime(true) + 5;
                    do {
                        $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$child]);
                        if ($waiting?->wait_event_type === 'Lock') {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    $this->assertSame('Lock', $waiting?->wait_event_type, 'Child must overlap and actually wait on PostgreSQL.');
                    if ($rollback) {
                        throw new RuntimeException('synthetic-outer-rollback');
                    }
                });
            } catch (RuntimeException $e) {
                if (! $rollback || $e->getMessage() !== 'synthetic-outer-rollback') {
                    throw $e;
                }
            }

            return [$first, json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR)];
        } finally {
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }
}
