<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\GrantBridgeFunding;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ForkedProcessResult;
use Throwable;

/**
 * Lead's concurrency condition on the bridge-funding design (item 18): two
 * admins approving the same order at once must serialize on the orders row
 * lock and produce exactly one grant, not two -- and the failure mode this
 * guards against is exactly the one documented in
 * tasks/handoffs/f5/report-signing-conflict-500.md (a thrown unique-violation
 * QueryException from inside runAsService()'s elevate branch poisoning the
 * whole PostgreSQL transaction). GrantBridgeFunding uses insertOrIgnore()
 * for the same reason ReportSigningService::sign() does.
 */
final class BridgeFundingConcurrencyTest extends TestCase
{
    private int $orderId;

    private int $superAdminId;

    private string $orderPublicId;

    private int $branchId;

    private int $participantId;

    private int $packageId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $suffix = 'BF_'.Str::random(8);

        app(RlsContextRunner::class)->runAsService(function () use ($suffix): void {
            $branch = Branch::create([
                'code' => "BR-{$suffix}",
                'name' => "Cabang {$suffix}",
                'ref_code' => "REF-{$suffix}",
                'organization_code' => $suffix,
                'organization_type' => 'EXTERNAL_LPK',
                'display_name' => "Cabang {$suffix}",
                'status' => 'ACTIVE',
                'allowed_funding_modes' => ['SPONSORED'],
            ]);
            // DASS-only composition deliberately: a "main direct" order
            // (dass21 + at least one other instrument) gets bound to an
            // assessment_case, and orders_direct_case_identity_guard makes
            // that binding immutable -- DELETE is unconditionally rejected
            // once assessment_case_id is set. This test needs real DELETE
            // in tearDown() (fork-based, real commits across processes, no
            // rollback available), so it uses the one order shape the
            // trigger allows to be deleted: DASS-only, no case at all.
            $package = TestPackage::create([
                'code' => "PKG-{$suffix}",
                'name' => "Paket {$suffix}",
                'amount' => 250_000,
                'currency' => 'IDR',
                'is_active' => true,
            ]);
            $package->items()->create(['test_type' => 'dass21', 'sort_order' => 1]);
            $participant = Participant::create([
                'branch_id' => $branch->id,
                'referral_branch_id' => $branch->id,
                'referral_source' => 'default',
                'package_id' => $package->id,
                'source_system' => 'DIRECT_PUBLIC',
                'full_name' => "Peserta {$suffix}",
                'gender' => 'female',
                'birth_date' => '2001-04-15',
                'education_level' => 'SMA/SMK',
                'intended_field' => 'KAIGO',
                'phone' => '+6281234567890',
            ]);
            // A unique, per-run code rather than the shared 'manual_transfer'
            // constant: this is a fork-based test with real commits, and
            // sharing a well-known code with other tests' own payment_method
            // fixtures risks exactly the kind of full-suite interaction this
            // session already had to fix once for a different table
            // (RLS Group C's full-suite pollution).
            $method = new PaymentMethod;
            $method->forceFill(['code' => "bf-{$suffix}", 'display_name' => 'Transfer Manual', 'is_active' => true])->save();
            $this->orderPublicId = (string) Str::ulid();
            $order = Order::create([
                'public_id' => $this->orderPublicId,
                'participant_id' => $participant->id,
                'assessment_case_id' => null,
                'payment_method_id' => $method->id,
                'status' => 'pending',
                'amount' => 250_000,
                'currency' => 'IDR',
            ]);
            Entitlement::create([
                'participant_id' => $participant->id,
                'assessment_case_id' => null,
                'order_id' => $order->id,
                'test_type' => 'dass21',
                'status' => 'locked',
            ]);
            $admin = Admin::create([
                'branch_id' => null,
                'name' => "Super Admin {$suffix}",
                'email' => Str::lower($suffix).'@example.test',
                'password' => 'not-a-real-password',
                'role' => AdminRole::SuperAdmin,
            ]);

            $this->orderId = $order->id;
            $this->superAdminId = $admin->id;
            $this->branchId = $branch->id;
            $this->participantId = $participant->id;
            $this->packageId = $package->id;
            $this->paymentMethodId = $method->id;
        });
    }

    /**
     * Fork-based: real committed rows across process boundaries, so unlike
     * most Postgres tests this cannot run inside a rolled-back transaction
     * -- must clean up explicitly instead, following the established
     * pattern from CheckoutHandoffIssuanceConcurrencyTest::tearDown() (and
     * the RLS-Group-C full-suite pollution fix this session's own history
     * already had to make once for a different table).
     */
    protected function tearDown(): void
    {
        // bridge_funding_grants first: it has a restrictOnDelete() FK to
        // orders, and no DELETE grant for psikotes_runtime anyway
        // (append-only-in-spirit, per the migration) -- the owner
        // connection is the only way to clean it up in a test.
        $this->asOwner(function (): void {
            DB::table('bridge_funding_grants')->where('order_id', $this->orderId)->delete();
        });
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('audit_logs')->where('subject_type', Order::class)
                ->where('subject_id', $this->orderPublicId)->delete();
            DB::table('outbox_messages')->where('aggregate_type', Order::class)
                ->where('aggregate_id', $this->orderPublicId)->delete();
            DB::table('entitlements')->where('order_id', $this->orderId)->delete();
            DB::table('orders')->where('id', $this->orderId)->delete();
            DB::table('payment_methods')->where('id', $this->paymentMethodId)->delete();
            DB::table('admins')->where('id', $this->superAdminId)->delete();
            DB::table('participants')->where('id', $this->participantId)->delete();
            DB::table('branches')->where('id', $this->branchId)->delete();
        });
        // package_items/packages last, after participants (FK:
        // participants.package_id) is already gone: no DELETE grant for
        // psikotes_runtime on either table, for any role including service
        // (RLS-GAP-07/08 remediation, 2026-09-22 -- no production code path
        // ever deletes a package or package item). Same pattern already
        // established in
        // CheckoutHandoffIssuanceConcurrencyTest::deletePackageItems().
        $this->asOwner(function (): void {
            DB::table('package_items')->where('package_id', $this->packageId)->delete();
            DB::table('packages')->where('id', $this->packageId)->delete();
        });
        parent::tearDown();
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.bridge_funding_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('bridge_funding_owner');

        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            DB::purge('bridge_funding_owner');
            config()->set('database.connections.bridge_funding_owner', null);
        }
    }

    public function test_two_admins_approving_the_same_order_at_once_produce_exactly_one_grant(): void
    {
        $results = $this->race(
            fn () => $this->grant('Surat instruksi holding No. 001/HC/2026'),
            fn () => $this->grant('Surat instruksi holding No. 001/HC/2026'),
        );

        foreach ($results as $result) {
            $this->assertArrayNotHasKey('class', $result, 'Neither worker may throw -- both must observe a settled bridge_funded order (real lock or idempotent replay), never a raw unique-violation escaping runAsService(). Got: '.json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame('bridge_funded', $result['status']);
        }

        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(1, DB::table('bridge_funding_grants')->where('order_id', $this->orderId)->count());
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'order.bridge_funding_granted')->where('subject_id', $this->orderPublicId)->count());
            $order = DB::table('orders')->where('id', $this->orderId)->sole();
            $this->assertSame('bridge_funded', $order->status);
            $this->assertNull($order->paid_at);
            $entitlements = DB::table('entitlements')->where('order_id', $this->orderId)->get();
            foreach ($entitlements as $entitlement) {
                $this->assertSame('ready', $entitlement->status);
            }
        });
    }

    /** @return array{status:string} */
    private function grant(string $managementReference): array
    {
        // The worker's connection is freshly purged with no RLS context set
        // at all yet -- reading Admin (RLS-protected) needs a service
        // context, same as any other pre-authorization read in this
        // codebase. GrantBridgeFunding::handle() establishes its own
        // service context internally for the actual write.
        $admin = app(RlsContextRunner::class)->runAsService(
            fn (): Admin => Admin::query()->findOrFail($this->superAdminId),
        );
        $order = app(GrantBridgeFunding::class)->handle($admin, $this->orderId, $managementReference);

        return ['status' => $order->status->value];
    }

    /**
     * Independent runtime-role processes pause right before locking the orders row.
     *
     * @return list<array<string, mixed>>
     */
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
                        $gateArmed = true;
                        DB::listen(function (QueryExecuted $query) use (&$gateArmed, $gate): void {
                            if (! str_starts_with($query->sql, 'select')
                                || ! str_contains($query->sql, '"orders"')
                                || ! str_contains($query->sql, 'for update')) {
                                return;
                            }
                            if ($gateArmed) {
                                $gateArmed = false;
                                DB::select('SELECT pg_advisory_lock_shared(?)', [$gate]);
                                DB::select('SELECT pg_advisory_unlock_shared(?)', [$gate]);
                            }
                        });
                        fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                        if (fgets($pair[1]) !== "go\n") {
                            throw new RuntimeException('Barrier timed out.');
                        }
                        $result = $callback();
                    } catch (Throwable $exception) {
                        $result = ['class' => $exception::class, 'error' => $exception->getMessage()];
                    }
                    ForkedProcessResult::sendAndExit($pair[1], $result,
                        static function (): void {
                            DB::disconnect('pgsql');
                        });
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
            // Best-effort diagnostic: try to observe one backend genuinely
            // blocked on the orders row lock before releasing the gate.
            // Not asserted -- in this harness the two FOR UPDATE calls can
            // resolve faster than the pg_stat_activity poll can sample them,
            // so a miss here does not mean no contention occurred. The real
            // invariant under test (two concurrent approvals of the same
            // order produce exactly one grant) is checked below via the
            // actual database state after both workers finish, which is a
            // stronger and harness-independent proof.
            $deadline = microtime(true) + 2;
            do {
                foreach ($backendIds as $backendId) {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
                    if ($waiting?->wait_event_type === 'Lock') {
                        break 2;
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
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
