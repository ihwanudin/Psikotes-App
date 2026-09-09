<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Enums\AdminRole;
use App\Filament\Actions\PreviewCollectiveBillSelection;
use App\Models\Admin;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture;
use Tests\Support\AssessmentPreviewFixture as Fixture;

/** Actual adapter on disposable PG; not an HTTP or confirmation transaction test. */
final class CollectiveBillPreviewTest extends TestCase
{
    private array $own;

    private array $foreign;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->own = $this->fixture('OWN');
            $this->foreign = $this->fixture('FOREIGN');
            $this->admin = Admin::create(['name' => 'Synthetic preview admin', 'email' => 'pg-preview@example.test',
                'password' => 'synthetic-password', 'role' => AdminRole::BranchAdmin,
                'branch_id' => $this->own['organization'], 'can_verify_payments' => true]);
        });
        Filament::auth()->setUser($this->admin);
        // Fixture transactions use savepoints. Clear their service GUCs before assertions.
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        DB::flushQueryLog();
        Filament::auth()->forgetUser();
        app()->instance('env', 'testing');
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_is_nonowner_nobypassrls_and_foreign_or_missing_ids_have_no_labels(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        foreach (['participants', 'assessment_participants', 'assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements'] as $table) {
            $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS owner FROM pg_class WHERE oid = to_regclass(?)', [$table]);
            $this->assertTrue($security->relrowsecurity, $table);
            $this->assertTrue($security->relforcerowsecurity, $table);
            $this->assertNotSame($role->name, $security->owner, $table);
        }
        $this->asBranch($this->own['organization'], function (): void {
            // The ordinary branch query cannot see B; service preview must also scope B out.
            $this->assertSame([$this->own['attempt']], DB::table('assessment_participants')->pluck('id')->all());
            $result = $this->preview([Fixture::selection($this->own), Fixture::selection($this->foreign),
                ['assessmentParticipantId' => PHP_INT_MAX, 'consultationRequested' => false]]);
            $this->assertSame(['items', 'currency', 'totalAmount', 'paidCount', 'freeCount', 'canReserve', 'selectionHash'], array_keys($result));
            $this->assertSame('Peserta OWN', $result['items'][0]['participantName']);
            $this->assertSame(['assessmentParticipantId', 'status', 'reason', 'participantName', 'externalCandidateId',
                'assessmentAttemptId', 'period', 'packageCode', 'packageName', 'baseAmount',
                'consultationRequested', 'consultationAmount', 'amount', 'currency'], array_keys($result['items'][0]));
            foreach (array_slice($result['items'], 1) as $item) {
                $this->assertSame(['assessmentParticipantId', 'status', 'reason'], array_keys($item));
                $this->assertSame('unavailable', $item['status']);
                $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $item['reason']);
            }
            $this->assertNull($result['totalAmount']);
            $this->assertNull($result['selectionHash']);
            $this->assertFalse($result['canReserve']);
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            foreach (['FOREIGN', 'PRIVATE-SENTINEL', 'metadata', 'policySnapshot', 'testTypes', 'invoice', 'proof', 'gateway'] as $private) {
                $this->assertStringNotContainsString($private, $json);
            }
        });
    }

    public function test_persisted_membership_wins_over_forged_session_and_stale_context_on_same_connection(): void
    {
        $pdo = DB::connection()->getPdo();
        $pid = DB::selectOne('SELECT pg_backend_pid() AS id')->id;
        $this->admin->branch_id = $this->foreign['organization'];
        $this->asBranch($this->own['organization'], function (): void {
            $this->assertSame('Peserta OWN', $this->preview()['items'][0]['participantName']);
            $this->assertNull($this->preview([Fixture::selection($this->foreign)])['totalAmount']);
        });
        $this->admin->branch_id = $this->own['organization'];
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('admins')->where('id', $this->admin->id)
            ->update(['branch_id' => $this->foreign['organization']]));
        foreach ([$this->own['organization'], $this->foreign['organization']] as $contextOrganization) {
            $this->asBranch($contextOrganization, function () use ($contextOrganization, $pdo, $pid): void {
                $this->assertSame($pdo, DB::connection()->getPdo());
                $this->assertSame($pid, DB::selectOne('SELECT pg_backend_pid() AS id')->id);
                if ($contextOrganization === $this->own['organization']) {
                    // admins_read RLS hides the moved principal from stale A context.
                    // Deny before elevation; callers must establish fresh context B.
                    try {
                        $this->preview([Fixture::selection($this->foreign)]);
                        $this->fail('Stale database context accepted moved principal.');
                    } catch (AuthorizationException $exception) {
                        $this->assertSame('BILL_PAYER_NOT_AUTHORIZED', $exception->getMessage());
                    }

                    return;
                }
                $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $this->preview()['items'][0]['reason']);
                $this->assertSame('Peserta FOREIGN', $this->preview([Fixture::selection($this->foreign)])['items'][0]['participantName']);
            });
        }
    }

    #[DataProvider('deniedPrincipals')]
    public function test_persisted_denial_even_with_stale_branch_admin_session_and_service_context(string $case): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($case): void {
            $this->assertSame(100, $this->preview()['totalAmount']);
            match ($case) {
                'guest' => Filament::auth()->forgetUser(),
                'non-admin' => Filament::auth()->setUser(new GenericUser(['id' => $this->admin->id])),
                'deleted' => DB::table('admins')->where('id', $this->admin->id)->update(['deleted_at' => now()]),
                'branchless' => DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => null]),
                default => DB::table('admins')->where('id', $this->admin->id)->update(['role' => $case]),
            };
            $context = app(RlsContextRunner::class)->current();
            try {
                $this->preview();
                $this->fail('Unauthorized principal accepted.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('BILL_PAYER_NOT_AUTHORIZED', $exception->getMessage());
                $this->assertSame($context, app(RlsContextRunner::class)->current());
                $this->assertSame('service', $this->databaseContext()['role']);
            }
        });
    }

    public static function deniedPrincipals(): iterable
    {
        foreach (['guest', 'non-admin', 'deleted', 'branchless', 'super_admin', 'staff', 'psychologist'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_elevated_context_restores_php_and_database_after_success_and_error(): void
    {
        $runner = app(RlsContextRunner::class);
        $context = new RlsContext('branch_admin', $this->own['organization'], $this->own['participant']);
        $pdo = DB::connection()->getPdo();
        $runner->run($context, function () use ($runner, $context): void {
            $before = $this->databaseContext();
            $this->assertSame(100, $this->preview()['totalAmount']);
            $this->assertSame($context, $runner->current());
            $this->assertSame($before, $this->databaseContext());
            try {
                $this->preview([]);
                $this->fail('Malformed selection accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame($context, $runner->current());
                $this->assertSame($before, $this->databaseContext());
            }
            $this->assertFalse(DB::table('assessment_participants')->where('id', $this->foreign['attempt'])->exists());
        });
        $this->assertNull($runner->current());
        DB::rollBack();
        $this->assertSame($pdo, DB::connection()->getPdo());
        $this->assertSame(['role' => null, 'branch' => null, 'participant' => null], $this->databaseContext());
    }

    public function test_existing_charge_snapshot_bill_item_and_entitlement_remain_unchanged(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $charge = AssessmentCharge::create(['assessment_participant_id' => $this->own['attempt'],
                'organization_id' => $this->own['organization'], 'participant_id' => $this->own['participant'],
                'package_id' => $this->own['package'], 'payer_type' => 'organization', 'base_amount' => 100,
                'consultation_amount' => 0, 'consultation_requested' => false, 'amount' => 100, 'currency' => 'IDR',
                'price_snapshot' => $this->snapshot($this->own['package']), 'policy_snapshot' => ['synthetic' => true]]);
            $this->assertSame(100, app(AssessmentPriceSnapshot::class)->fromCharge($charge, false)['amount']);
            DB::table('packages')->where('id', $this->own['package'])->update(['amount' => 999]);
            $billed = AssessmentBillingFixture::create('organization', ['organization' => $this->own['organization']]);
            DB::table('packages')->where('id', $billed['package'])->update(['is_active' => true]);
            DB::table('package_items')->insert([
                ['package_id' => $billed['package'], 'test_type' => 'ist', 'sort_order' => 1],
                ['package_id' => $billed['package'], 'test_type' => 'dass21', 'sort_order' => 2],
            ]);
            AssessmentCharge::findOrFail($billed['charge'])->update(['price_snapshot' => $this->snapshot($billed['package'])]);
            DB::table('assessment_bill_items')->insert(AssessmentBillingFixture::item($billed));
            DB::table('assessment_entitlements')->insert(AssessmentBillingFixture::entitlement($billed));
            $this->assertGreaterThan(0, DB::table('assessment_bill_items')
                ->where('organization_id', $this->own['organization'])->count());
            $this->assertGreaterThan(0, DB::table('assessment_entitlements')
                ->where('organization_id', $this->own['organization'])->count());
        });
        $this->asBranch($this->own['organization'], function (): void {
            $this->assertSame(100, $this->preview()['totalAmount']);
            $this->assertSame(100, $this->preview()['totalAmount']);
        });
    }

    public function test_query_count_is_bounded_at_ten_and_configured_selection_limit(): void
    {
        $limit = (int) config('assessment_billing.max_items');
        $this->assertGreaterThanOrEqual(10, $limit);
        $selection = app(RlsContextRunner::class)->runAsService(function () use ($limit): array {
            $selection = [Fixture::selection($this->own)];
            for ($i = 1; $i < $limit; $i++) {
                $selection[] = Fixture::selection($this->fixture('BATCH-'.$i, $this->own['organization']));
            }

            return $selection;
        });
        $this->asBranch($this->own['organization'], function () use ($selection, $limit): void {
            $counts = [];
            foreach ([10, $limit] as $size) {
                $queries = [];
                $result = $this->preview(array_slice($selection, 0, $size), $queries);
                $this->assertCount($size, $result['items']);
                $this->assertSame($size * 100, $result['totalAmount']);
                $this->assertCount($size, array_unique(array_column($result['items'], 'externalCandidateId')));
                $counts[] = count($queries);
                $this->assertLessThanOrEqual(16, count($queries));
                fwrite(STDERR, "\nCollective preview PG {$size} items: ".count($queries)." queries\n");
            }
            $this->assertSame($counts[0], $counts[1]);
        });
    }

    #[DataProvider('participantChanges')]
    public function test_participant_change_between_loaded_preview_and_label_query_fails_closed(string $change): void
    {
        $connection = DB::connection();
        $originalEvents = $connection->getEventDispatcher();
        $this->assertNotNull($originalEvents);
        $events = clone $originalEvents;
        $connection->setEventDispatcher($events);
        $fired = false;
        // Last backend batch read happens after participant eager loading. The
        // one-shot hook changes the DB while that preview relation stays stale.
        $events->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$fired, $change): void {
            if ($fired || ! str_contains($event->sql, 'from "assessment_bill_items"')) {
                return;
            }
            $fired = true;
            $this->assertSame('service', app(RlsContextRunner::class)->current()?->role);
            DB::table('participants')->where('id', $this->own['participant'])->update($change === 'deleted'
                ? ['deleted_at' => now()] : ['branch_id' => $this->foreign['organization']]);
        });
        try {
            $this->asBranch($this->own['organization'], function () use (&$fired): void {
                $context = app(RlsContextRunner::class)->current();
                $databaseContext = $this->databaseContext();
                try {
                    // Do not use read-only helper: the synthetic hook itself writes.
                    app(PreviewCollectiveBillSelection::class)->execute([Fixture::selection($this->own)]);
                    $this->fail('Stale participant label returned.');
                } catch (AuthorizationException $exception) {
                    $this->assertTrue($fired);
                    $this->assertSame('BILL_PAYER_NOT_AUTHORIZED', $exception->getMessage());
                    $this->assertSame($context, app(RlsContextRunner::class)->current());
                    $this->assertSame($databaseContext, $this->databaseContext());
                }
            });
        } finally {
            $connection->setEventDispatcher($originalEvents);
        }
    }

    public static function participantChanges(): iterable
    {
        yield ['deleted'];
    }

    private function fixture(string $label, ?int $organization = null): array
    {
        $fixture = Fixture::create($organization === null ? null : ['organization' => $organization]);
        DB::table('package_items')->insert([
            'package_id' => $fixture['package'], 'test_type' => 'dass21', 'sort_order' => 2,
        ]);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Peserta '.$label]);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'external_candidate_id' => 'CANDIDATE-'.$fixture['attempt'], 'assessment_round_id' => 'Synthetic period',
            'metadata' => '{"checkout_contract_version":"checkout-v2","private":"PRIVATE-SENTINEL"}']);

        return $fixture;
    }

    private function snapshot(int $package): array
    {
        return app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($package), false);
    }

    private function asBranch(int $organization, callable $callback): mixed
    {
        return app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $organization), $callback);
    }

    private function databaseContext(): array
    {
        return (array) DB::selectOne("SELECT nullif(current_setting('app.role', true), '') AS role, nullif(current_setting('app.branch_id', true), '') AS branch, nullif(current_setting('app.participant_id', true), '') AS participant");
    }

    private function effectRows(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $rows = [];
            foreach (['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
                'audit_logs', 'outbox_messages', 'orders', 'entitlements', 'consent_records', 'identity_verifications'] as $table) {
                // Compare every column, not just counts; service sees both tenants.
                $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $rows;
        });
    }

    private function preview(?array $selection = null, ?array &$queries = null): array
    {
        $before = $this->effectRows();
        $context = app(RlsContextRunner::class)->current();
        $databaseContext = $this->databaseContext();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            return app(PreviewCollectiveBillSelection::class)->execute($selection ?? [Fixture::selection($this->own)]);
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            // Check before effectRows can elevate/restore GUCs itself; otherwise
            // the verification helper could accidentally repair a context leak.
            $this->assertSame($context, app(RlsContextRunner::class)->current());
            $this->assertSame($databaseContext, $this->databaseContext());
            $this->assertSame($before, $this->effectRows());
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^\s*select\b/i', $query['query']);
            }
        }
    }
}
