<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Enums\AdminRole;
use App\Filament\Resources\OrganizationBills\OrganizationBillResource as Resource;
use App\Filament\Resources\OrganizationBills\Pages\ViewOrganizationBill;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\AssessmentBillingFixture as Fixture;

/** Query/projection evidence only: the PG bootstrap is not an HTTP/Livewire test case. */
final class OrganizationBillPortalTest extends TestCase
{
    private const string PROOF_KEY = 'assessment-bills/ab/cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc.jpg';

    private array $own;

    private array $foreign;

    private array $self;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->own = $this->bill('OWN');
            $this->foreign = $this->bill('FOREIGN');
            $this->self = $this->bill('SELF', $this->own['organization'], 'self');
            $this->admin = Admin::create([
                'name' => 'Synthetic portal admin', 'email' => 'pg-portal@example.test',
                'password' => 'synthetic-test-password', 'role' => AdminRole::BranchAdmin,
                'branch_id' => $this->own['organization'], 'can_verify_payments' => true,
            ]);
        });
        Filament::auth()->setUser($this->admin);
        $this->clearDatabaseContext();
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

    public function test_actual_resource_and_detail_projection_run_as_nonowner_with_forced_rls(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'assessment_participants', 'participants'] as $table) {
            $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS owner FROM pg_class WHERE oid = to_regclass(?)', [$table]);
            $this->assertTrue($security->relrowsecurity, $table);
            $this->assertTrue($security->relforcerowsecurity, $table);
            $this->assertNotSame($role->name, $security->owner, $table);
        }
        $this->asBranch($this->own['organization'], function (): void {
            $this->assertTrue(Resource::canViewAny());
            $this->assertSame([$this->own['bill']], Resource::getEloquentQuery()->pluck('id')->all());
            $bill = Resource::resolveRecordRouteBinding($this->own['bill']);
            $this->assertInstanceOf(AssessmentBill::class, $bill);
            $this->assertTrue(Resource::canView($bill));
            $this->assertEqualsCanonicalizing(['id', 'organization_id', 'payer_type', 'public_reference', 'amount', 'currency',
                'item_count', 'status', 'created_at', 'expires_at', 'paid_at', 'verified_at'], array_keys($bill->getAttributes()));
            $rows = $this->allocations($bill);
            $this->assertCount(1, $rows);
            $this->assertSame('Peserta OWN', $rows[0]['participant']);
            $this->assertSame('Paket snapshot OWN', $rows[0]['package']);
            $this->assertSame('Periode OWN', $rows[0]['period']);
            $this->assertSame(100, $rows[0]['amount']);
            $this->assertSame(100, $rows[0]['base_amount']);
            $this->assertSame(0, $rows[0]['consultation_amount']);
            $this->assertSame(['participant', 'attempt', 'period', 'package', 'base_amount', 'consultation_amount', 'amount', 'settled_at'], array_keys($rows[0]));
            $serialized = json_encode([$bill->toArray(), $rows], JSON_THROW_ON_ERROR);
            foreach (['CLINICAL-SENTINEL', self::PROOF_KEY, 'PRIVATE-GATEWAY', 'PRIVATE-INVOICE', 'PRIVATE-REVIEW', 'FOREIGN', 'CATALOG-CHANGED'] as $private) {
                $this->assertStringNotContainsString($private, $serialized);
            }
            foreach ([$this->foreign['bill'], $this->self['bill'], PHP_INT_MAX] as $deniedId) {
                $this->assertNull(Resource::resolveRecordRouteBinding($deniedId));
            }
            // Even a record supplied outside route binding cannot authorize foreign detail.
            $forged = (new AssessmentBill)->forceFill(['id' => $this->foreign['bill'], 'organization_id' => $this->own['organization'], 'payer_type' => 'organization']);
            $this->assertSame($this->foreign['bill'], $forged->id);
            $this->assertFalse(Resource::canView($forged));
            $this->expectException(AuthorizationException::class);
            $this->allocations($forged);
        });
    }

    #[DataProvider('deniedRoles')]
    public function test_persisted_role_revocation_denies_stale_session_even_in_broad_rls_context(AdminRole $role): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('admins')->where('id', $this->admin->id)->update(['role' => $role->value]));
        // Keep the stale BranchAdmin session, and deliberately grant broad DB reads:
        // application authorization must still reject even SuperAdmin/legacy verifier.
        app(RlsContextRunner::class)->run(new RlsContext('super_admin'), function (): void {
            $this->assertFalse(Resource::canViewAny());
            $this->assertSame([], Resource::getEloquentQuery()->get()->all());
            $this->assertNull(Resource::resolveRecordRouteBinding($this->own['bill']));
        });
    }

    public static function deniedRoles(): iterable
    {
        yield [AdminRole::SuperAdmin];
        yield [AdminRole::Staff];
        yield [AdminRole::Psychologist];
    }

    public function test_membership_change_rechecks_existing_detail_and_reused_connection_context(): void
    {
        $pdo = DB::connection()->getPdo();
        $pid = DB::selectOne('SELECT pg_backend_pid() AS id')->id;
        $oldBill = $this->asBranch($this->own['organization'], fn () => Resource::resolveRecordRouteBinding($this->own['bill']));
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => $this->foreign['organization']]));
        $this->asBranch($this->own['organization'], function () use ($oldBill): void {
            $this->assertFalse(Resource::canView($oldBill));
            $this->assertSame([], Resource::getEloquentQuery()->get()->all());
            try {
                $this->allocations($oldBill);
                $this->fail('Stale detail remained readable.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        });
        $this->asBranch($this->foreign['organization'], function () use ($pid, $pdo): void {
            $this->assertSame($pdo, DB::connection()->getPdo());
            $this->assertSame($pid, DB::selectOne('SELECT pg_backend_pid() AS id')->id);
            $this->assertSame([$this->foreign['bill']], Resource::getEloquentQuery()->pluck('id')->all());
            $bill = Resource::resolveRecordRouteBinding($this->foreign['bill']);
            $this->assertSame('Peserta FOREIGN', $this->allocations($bill)[0]['participant']);
            $this->assertNull(Resource::resolveRecordRouteBinding($this->own['bill']));
        });
        foreach (['staff', 'psychologist', 'participant'] as $role) {
            app(RlsContextRunner::class)->run(new RlsContext($role, $this->foreign['organization'], $this->foreign['participant']), function (): void {
                $this->assertSame([], Resource::getEloquentQuery()->get()->all());
                $this->assertNull(Resource::resolveRecordRouteBinding($this->foreign['bill']));
            });
        }
        try {
            $this->asBranch($this->own['organization'], fn () => throw new RuntimeException('Synthetic rollback'));
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic rollback', $exception->getMessage());
        }
        $this->assertNull(app(RlsContextRunner::class)->current());
        // Outer fixture transaction means contexts above use savepoints. Explicitly
        // test absent context too, then end that real transaction on the same PDO.
        $this->clearDatabaseContext();
        $this->assertSame([], Resource::getEloquentQuery()->get()->all());
        DB::rollBack();
        $this->assertSame($pdo, DB::connection()->getPdo());
        $this->assertNull(DB::selectOne('SELECT app_private.app_role() AS role')->role);
        $this->assertSame([], Resource::getEloquentQuery()->get()->all());
    }

    public function test_guest_missing_membership_deleted_admin_and_public_environment_are_closed(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            Filament::auth()->forgetUser();
            $this->assertFalse(Resource::canViewAny());
            $this->assertNull(Resource::resolveRecordRouteBinding($this->own['bill']));
            Filament::auth()->setUser($this->admin);
            app()->instance('env', 'production');
            $this->assertFalse(Resource::isDiscovered());
            $this->assertFalse(Resource::shouldRegisterNavigation());
            $this->assertSame([], Resource::getEloquentQuery()->get()->all());
            app()->instance('env', 'testing');
            DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => null]);
            $this->assertFalse(Resource::canViewAny());
            DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => $this->own['organization'], 'deleted_at' => now()]);
            $this->assertFalse(Resource::canViewAny());
            $this->assertNull(Resource::resolveRecordRouteBinding($this->own['bill']));
        });
    }

    public function test_paginated_resource_query_count_and_detail_eager_loading(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            for ($i = 1; $i < 50; $i++) {
                $this->bill('PAGE-'.$i, $this->own['organization']);
            }
            for ($i = 1; $i < 10; $i++) {
                $this->bill('ALLOCATION-'.$i, $this->own['organization'], 'organization', $this->own['bill']);
            }
            DB::table('assessment_bills')->where('id', $this->own['bill'])->update(['amount' => 1000, 'item_count' => 10]);
        });
        $this->asBranch($this->own['organization'], function (): void {
            foreach ([10, 25, 50] as $size) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $page = Resource::getEloquentQuery()->orderBy('id')->paginate($size);
                $count = count(DB::getQueryLog());
                DB::disableQueryLog();
                $this->assertSame(50, $page->total());
                $this->assertCount($size, $page->items());
                $this->assertLessThanOrEqual(3, $count);
                fwrite(STDERR, "\nPortal PG resource page {$size}: {$count} queries\n");
            }
            $bill = Resource::resolveRecordRouteBinding($this->own['bill']);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertCount(10, $this->allocations($bill));
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThanOrEqual(7, count($queries));
            fwrite(STDERR, "\nPortal PG detail 10 allocations: ".count($queries)." queries\n");
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/"(?:metadata|invoice_url|proof_object_key|proof_checksum_sha256|proof_mime_type|proof_size_bytes|proof_uploaded_at|gateway_ref|rejection_reason)"/', $query['query']);
            }
        });
    }

    private function asBranch(int $organization, callable $callback): mixed
    {
        return app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $organization), $callback);
    }

    private function clearDatabaseContext(): void
    {
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
    }

    private function allocations(AssessmentBill $bill): array
    {
        $page = new ViewOrganizationBill;
        $page->record = $bill;

        // Invoke the actual private UI projection, not a copied test query. Does not
        // claim to run Livewire mount, rendering, middleware, or hydration on PG.
        return (new ReflectionMethod($page, 'allocations'))->invoke($page);
    }

    private function bill(string $label, ?int $organization = null, string $payer = 'organization', ?int $bill = null): array
    {
        $fixture = Fixture::create($payer, $organization === null ? null : ['organization' => $organization]);
        DB::table('packages')->where('id', $fixture['package'])->update(['name' => 'Paket snapshot '.$label, 'is_active' => true]);
        DB::table('package_items')->insert([
            ['package_id' => $fixture['package'], 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $fixture['package'], 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $snapshots = app(AssessmentPriceSnapshot::class);
        $snapshot = $snapshots->capture(TestPackage::with('items')->findOrFail($fixture['package']), false);
        AssessmentCharge::findOrFail($fixture['charge'])->update(['price_snapshot' => $snapshot]);
        $this->assertSame($snapshot, $snapshots->fromCharge(AssessmentCharge::findOrFail($fixture['charge']), false));
        if ($bill !== null) {
            DB::table('assessment_bills')->where('id', $fixture['bill'])->delete();
            $fixture['bill'] = $bill;
        }
        DB::table('assessment_bill_items')->insert(Fixture::item($fixture));
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Peserta '.$label]);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'assessment_round_id' => 'Periode '.$label, 'metadata' => '{"clinical":"CLINICAL-SENTINEL"}',
        ]);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'invoice_url' => 'https://gateway.example.test/PRIVATE-INVOICE', 'proof_object_key' => self::PROOF_KEY,
            'proof_checksum_sha256' => str_repeat('a', 64), 'proof_mime_type' => 'image/jpeg',
            'proof_size_bytes' => 1, 'proof_uploaded_at' => now(),
            'gateway_ref' => 'PRIVATE-GATEWAY-'.$label, 'rejection_reason' => 'PRIVATE-REVIEW',
        ]);
        DB::table('packages')->where('id', $fixture['package'])->update(['name' => 'CATALOG-CHANGED', 'amount' => 999999]);

        return $fixture;
    }
}
