<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Registration\RegisterParticipant;
use App\Http\Controllers\ParticipantRegistrationController;
use App\Http\Requests\StoreParticipantRegistrationRequest;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\CreateRegistrationInvoice;
use App\Services\Referral\ReferralAttribution;
use Database\Seeders\TestPackageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Response as InertiaResponse;
use Inertia\Support\Header;
use PHPUnit\Framework\TestCase;

/**
 * RLS-GAP-07/08 remediation (packages, package_items -- Group C, Lead
 * sign-off 2026-09-22). See
 * database/migrations/2026_09_22_000200_harden_test_package_catalog_rls.php's
 * doc comment for the full read/write-path mapping, the SELECT-widening
 * rationale, and the explained-not-fixed FORCE-without-ENABLE anomaly this
 * migration's idempotent ENABLE+FORCE resolves regardless of the value it
 * finds on entry (both tables enter this migration with
 * relforcerowsecurity=true already, from 2026_09_09_000600 -- the round
 * trip test below undoes and redoes this migration's REAL applied state,
 * not a synthetic one).
 */
final class TestPackageCatalogRlsSecurityTest extends TestCase
{
    private const array TABLES = ['packages', 'package_items'];

    private const array ADMIN_ROLES = ['super_admin', 'central_admin', 'branch_admin', 'staff', 'psychologist'];

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        // Without this, every fixture below (branches, packages,
        // participants, and a full real registration in the controller
        // store() test) genuinely commits to the shared disposable
        // database and leaks into every other Postgres test that runs
        // after this file -- exactly the kind of row-count pollution the
        // full-suite run (not the isolated -Filter run) caught elsewhere
        // in this remediation effort.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_has_forced_least_privilege_rls_with_the_expected_policies(): void
    {
        foreach (self::TABLES as $table) {
            $identity = DB::selectOne(
                "SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS table_owner FROM pg_class WHERE oid = '{$table}'::regclass",
            );
            $this->assertTrue($identity->relrowsecurity, $table);
            $this->assertTrue($identity->relforcerowsecurity, $table);
            $this->assertNotSame('psikotes_runtime', $identity->table_owner, $table);

            foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
                $this->assertTrue((bool) DB::scalar(
                    "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                    [$table, $privilege],
                ), "{$table}: {$privilege}");
            }
            foreach (['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
                $this->assertFalse((bool) DB::scalar(
                    "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                    [$table, $privilege],
                ), "{$table}: {$privilege}");
            }

            $policies = DB::table('pg_policies')
                ->where('schemaname', 'public')->where('tablename', $table)
                ->orderBy('policyname')->get();
            $this->assertSame(
                ["{$table}_insert", "{$table}_read", "{$table}_update"],
                $policies->pluck('policyname')->all(),
                $table,
            );
        }

        // packages: read is every admin role, update is service+super_admin+central_admin.
        $this->assertPolicyRoles('packages_read', [...self::ADMIN_ROLES, 'service']);
        $this->assertPolicyRoles('packages_insert', ['service']);
        $this->assertPolicyRoles('packages_update', ['service', 'super_admin', 'central_admin']);
        // package_items: same broad read, but update is service-only -- no
        // admin UI ever writes to it (Filament only edits packages fields).
        $this->assertPolicyRoles('package_items_read', [...self::ADMIN_ROLES, 'service']);
        $this->assertPolicyRoles('package_items_insert', ['service']);
        $this->assertPolicyRoles('package_items_update', ['service']);
    }

    public function test_participant_context_cannot_read_or_write_either_table(): void
    {
        $packageId = $this->seedPackage();

        foreach (self::TABLES as $table) {
            app(RlsContextRunner::class)->run(
                new RlsContext('participant', 1, 1),
                fn () => $this->assertSame(0, DB::table($table)->count(), $table),
            );
        }

        $this->assertSqlState('42501', fn () => app(RlsContextRunner::class)->run(
            new RlsContext('participant', 1, 1),
            fn () => DB::table('packages')->insert([
                'code' => 'PARTICIPANT-INJECTED', 'name' => 'x', 'currency' => 'IDR',
                'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
            ]),
        ));

        // UPDATE does not throw the way INSERT does under RLS -- a row
        // failing the USING check is excluded from the target set (0 rows
        // affected, no exception), the same lesson Groups A/B already
        // documented.
        $affected = app(RlsContextRunner::class)->run(
            new RlsContext('participant', 1, 1),
            fn () => DB::table('packages')->where('id', $packageId)->update(['name' => 'hijacked']),
        );
        $this->assertSame(0, $affected);
        $unchanged = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('packages')->where('id', $packageId)->value('name'),
        );
        $this->assertNotSame('hijacked', $unchanged);
    }

    public function test_every_admin_role_can_read_packages_but_only_super_admin_and_central_admin_can_update(): void
    {
        $packageId = $this->seedPackage();

        foreach (self::ADMIN_ROLES as $role) {
            $count = app(RlsContextRunner::class)->run(
                new RlsContext($role, in_array($role, ['super_admin', 'central_admin'], true) ? null : 1),
                fn () => DB::table('packages')->count(),
            );
            $this->assertGreaterThan(0, $count, $role);
        }

        foreach (['branch_admin', 'staff', 'psychologist'] as $role) {
            $affected = app(RlsContextRunner::class)->run(
                new RlsContext($role, 1),
                fn () => DB::table('packages')->where('id', $packageId)->update(['name' => "hijacked-by-{$role}"]),
            );
            $this->assertSame(0, $affected, $role);
        }

        $affected = app(RlsContextRunner::class)->run(
            new RlsContext('super_admin'),
            fn () => DB::table('packages')->where('id', $packageId)->update(['name' => 'renamed-by-super-admin']),
        );
        $this->assertSame(1, $affected);
        $name = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('packages')->where('id', $packageId)->value('name'),
        );
        $this->assertSame('renamed-by-super-admin', $name);

        $affected = app(RlsContextRunner::class)->run(
            new RlsContext('central_admin'),
            fn () => DB::table('packages')->where('id', $packageId)->update(['name' => 'renamed-by-central-admin']),
        );
        $this->assertSame(1, $affected);
        $name = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('packages')->where('id', $packageId)->value('name'),
        );
        $this->assertSame('renamed-by-central-admin', $name);
    }

    public function test_service_context_can_insert_select_and_lock_for_update(): void
    {
        $packageId = app(RlsContextRunner::class)->runAsService(function (): int {
            $id = DB::table('packages')->insertGetId([
                'code' => 'SVC-'.Str::random(8), 'name' => 'Service package', 'amount' => 100000,
                'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('package_items')->insert([
                'package_id' => $id, 'test_type' => 'dass21', 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // ReserveAssessmentBill's real access pattern: SELECT ... FOR
            // UPDATE with no following UPDATE statement -- requires the
            // UPDATE grant even though nothing is ever literally updated
            // through this path (Group A's lesson).
            DB::table('packages')->where('id', $id)->lockForUpdate()->get(['id']);
            DB::table('package_items')->where('package_id', $id)->lockForUpdate()->get(['id']);

            return $id;
        });

        $this->assertGreaterThan(0, $packageId);
    }

    public function test_the_seeder_populates_the_catalog_under_a_service_context(): void
    {
        (new TestPackageSeeder)->run();

        $count = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('packages')->where('code', 'DASS21')->count(),
        );
        $this->assertGreaterThan(0, $count, 'TestPackageSeeder must have populated at least the DASS21 template.');
    }

    /**
     * The real ParticipantRegistrationController::create() action -- the
     * TestPackage::query() call this PR wrapped in runAsService() sits
     * between two already-wrapped queries in the same method. This proves
     * it now actually runs (no exception, real packages returned) with NO
     * RLS context pre-established by the caller, exactly like an anonymous
     * `GET /register` visit.
     */
    public function test_the_real_controller_create_action_lists_packages_with_no_pre_established_context(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->seedPackage();
        $this->seedDefaultBranch();

        $request = Request::create('/register', 'GET');
        $request->headers->set(Header::INERTIA, 'true');
        $request->setLaravelSession(app('session')->driver());

        $controller = new ParticipantRegistrationController;
        $response = $controller->create($request, app(ReferralAttribution::class), app(RlsContextRunner::class));

        $this->assertInstanceOf(InertiaResponse::class, $response);
        $rendered = $response->toResponse($request);
        $this->assertInstanceOf(JsonResponse::class, $rendered);
        $props = $rendered->getData(true)['props'];
        $this->assertFalse($props['packageConfigurationPending']);
        $this->assertNotEmpty($props['packages']);
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    /**
     * Lead's explicit requirement: prove the real
     * StoreParticipantRegistrationRequest::rules() closure (not a stand-in)
     * against real PostgreSQL RLS, both directions -- a real active
     * package validates clean, and a package_id that plain does not exist
     * fails validation (422 semantics, the standard Laravel validation
     * failure shape) rather than either silently passing because RLS hid
     * every row from view, or surfacing as an uncaught 500 from a raw
     * QueryException escaping the closure.
     */
    public function test_the_real_form_request_validates_package_id_against_postgres_in_both_directions(): void
    {
        $packageId = $this->seedPackage();
        $token = (string) Str::uuid();

        $accepted = $this->makeRegistrationRequest($token, $packageId);
        $acceptedValidator = Validator::make($accepted->all(), $accepted->rules());
        $this->assertFalse($acceptedValidator->errors()->has('package_id'), 'A real active package must validate cleanly.');

        $neverExisted = $packageId + 999_999;
        $rejected = $this->makeRegistrationRequest($token, $neverExisted);

        try {
            $rejectedValidator = Validator::make($rejected->all(), $rejected->rules());
            $this->assertTrue(
                $rejectedValidator->errors()->has('package_id'),
                'A package_id that was never inserted must fail validation, not silently pass '
                .'because RLS made every packages row invisible to whatever context ran the check.',
            );
        } catch (QueryException $exception) {
            $this->fail('The package_id existence check must not surface a raw database error to the caller: '.$exception->getMessage());
        }
    }

    /**
     * The full real path: real controller, real FormRequest, real
     * RegisterParticipant/CreateRegistrationInvoice -- a valid, active
     * package_id results in an actual participant row, under PostgreSQL
     * RLS end to end.
     */
    public function test_the_real_controller_store_action_registers_a_participant_for_a_valid_package(): void
    {
        $packageId = $this->seedPackage();
        $this->seedDefaultBranch();
        $this->seedActivePaymentMethod();
        $token = (string) Str::uuid();

        $formRequest = $this->makeRegistrationRequest($token, $packageId);
        $formRequest->setContainer(app());
        $formRequest->validateResolved();

        $controller = new ParticipantRegistrationController;
        $response = app()->call([$controller, 'store'], [
            'request' => $formRequest,
            'register' => app(RegisterParticipant::class),
            'createInvoice' => app(CreateRegistrationInvoice::class),
        ]);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringEndsWith('/registration/received', $response->getTargetUrl());

        $participant = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('participants')->where('package_id', $packageId)->first(),
        );
        $this->assertNotNull($participant);
        $this->assertSame('Ayu Postgres', $participant->full_name);
    }

    /**
     * The disposable test database already ran this migration's up() once,
     * as part of the normal `migrate` bootstrap every test starts from --
     * so, unlike a hand-rolled "before" fixture, down() here is undoing
     * the REAL thing that was really applied, not a synthetic setup.
     * Down-then-up is Lead's explicit round-trip requirement: prove FORCE
     * and ENABLE come back correctly after a rollback-then-migrate
     * sequence, regardless of whatever FORCE value the table carried in
     * (2026_09_09_000600 already left both tables at
     * relforcerowsecurity=true before this migration ever runs the first
     * time -- see the migration's own doc comment).
     */
    public function test_migration_down_up_cycle_round_trips_cleanly(): void
    {
        $this->asOwner(function (): void {
            $migration = require database_path('migrations/2026_09_22_000200_harden_test_package_catalog_rls.php');

            DB::beginTransaction();
            try {
                $migration->down();
                foreach (self::TABLES as $table) {
                    $state = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = '{$table}'::regclass");
                    $this->assertFalse($state->relrowsecurity, $table);
                    $this->assertFalse($state->relforcerowsecurity, $table);
                    $count = DB::table('pg_policies')->where('schemaname', 'public')->where('tablename', $table)->count();
                    $this->assertSame(0, $count, $table);
                }

                $migration->up();
                foreach (self::TABLES as $table) {
                    $state = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = '{$table}'::regclass");
                    $this->assertTrue($state->relrowsecurity, $table);
                    $this->assertTrue($state->relforcerowsecurity, $table);
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @param list<string> $expectedRoles */
    private function assertPolicyRoles(string $policyName, array $expectedRoles): void
    {
        $policy = DB::table('pg_policies')->where('schemaname', 'public')->where('policyname', $policyName)->first();
        $this->assertNotNull($policy, $policyName);
        $definition = ($policy->qual ?? '').($policy->with_check ?? '');
        foreach ($expectedRoles as $role) {
            $this->assertStringContainsString("'{$role}'", $definition, "{$policyName} should recognize {$role}");
        }
    }

    private function seedPackage(): int
    {
        return app(RlsContextRunner::class)->runAsService(function (): int {
            $id = DB::table('packages')->insertGetId([
                'code' => 'GC-'.Str::random(10), 'name' => 'Paket Group C', 'amount' => 250000,
                'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['dass21', 'ist'] as $sort => $type) {
                DB::table('package_items')->insert([
                    'package_id' => $id, 'test_type' => $type, 'sort_order' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $id;
        });
    }

    private function seedDefaultBranch(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            if (DB::table('branches')->where('is_default', true)->exists()) {
                return;
            }
            $key = (string) Str::ulid();
            DB::table('branches')->insert([
                'code' => $key, 'ref_code' => $key, 'name' => 'GC Default', 'is_default' => true,
                'organization_code' => $key, 'display_name' => 'GC Default',
            ]);
        });
    }

    private function seedActivePaymentMethod(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            if (DB::table('payment_methods')->where('code', 'manual_transfer')->exists()) {
                DB::table('payment_methods')->where('code', 'manual_transfer')->update(['is_active' => true]);

                return;
            }
            DB::table('payment_methods')->insert([
                'code' => 'manual_transfer', 'display_name' => 'Transfer manual', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function makeRegistrationRequest(string $token, int $packageId): StoreParticipantRegistrationRequest
    {
        $session = app('session')->driver();
        $session->put('registration.token', $token);

        $request = StoreParticipantRegistrationRequest::create('/registrations', 'POST', [
            '_registration_token' => $token,
            'package_id' => $packageId,
            'payment_method_code' => 'manual_transfer',
            'full_name' => 'Ayu Postgres',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'email' => 'ayu.postgres@example.test',
            'consent_psychotest' => true,
            'consent_dass' => true,
            'include_consultation' => false,
        ]);
        $request->setLaravelSession($session);

        return $request;
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        }
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.test_package_catalog_owner', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        DB::setDefaultConnection('test_package_catalog_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('test_package_catalog_owner');
            config()->set('database.connections.test_package_catalog_owner', null);
        }
    }
}
