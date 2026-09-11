<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContextRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GenericEntitlementCaseRequirementMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
        $this->asOwner(fn () => $this->migrate('down'));
    }

    protected function tearDown(): void
    {
        try {
            $this->asOwner(function (): void {
                DB::statement('ALTER TABLE entitlements DROP CONSTRAINT IF EXISTS entitlements_case_requirement_check');
            });
        } finally {
            parent::tearDown();
        }
    }

    public function test_validated_exact_constraint_preserves_security_base_and_child_grant(): void
    {
        $graph = $this->directGraph('valid');
        $before = $this->catalogSnapshot();
        $baseGuard = $this->guardSnapshot();

        $this->asOwner(fn () => $this->migrate('up'));
        $this->asOwner(fn () => $this->migrate('up'));

        $constraint = DB::selectOne(<<<'SQL'
            SELECT contype,convalidated,condeferrable,condeferred,connoinherit,
                   pg_get_constraintdef(oid,false) definition
            FROM pg_constraint
            WHERE conrelid='entitlements'::regclass AND conname='entitlements_case_requirement_check'
            SQL);
        $this->assertNotNull($constraint);
        $this->assertSame('c', $constraint->contype);
        $this->assertTrue($constraint->convalidated);
        $this->assertFalse($constraint->condeferrable);
        $this->assertFalse($constraint->condeferred);
        $this->assertFalse($constraint->connoinherit);
        $this->assertSame($before, $this->catalogSnapshot());
        $this->assertNotSame($baseGuard, $this->guardSnapshot());
        $this->assertStringContainsString("assessment_case.origin = 'INTEGRATED'", $this->guardSnapshot()['body']);
        $this->assertStringContainsString('mapping.assessment_case_id = assessment_case.id', $this->guardSnapshot()['body']);
        $this->assertSame($graph['entitlement'], app(RlsContextRunner::class)->runAsService(
            fn (): int => DB::table('test_session_grants')->where('entitlement_id', $graph['entitlement'])
                ->sole()->entitlement_id,
        ));
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlementRow($graph['participant'], $graph['order'], null, 'papi'),
        ));
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlementRow($graph['participant'], $graph['order'], $graph['case'], 'dass21'),
        ));
        try {
            app(RlsContextRunner::class)->runAsService(fn () => DB::table('entitlements')->insert(
                $this->entitlementRow($graph['participant'], $graph['order'], null, 'dass21'),
            ));
            $this->fail('Original participant and test unique index must remain effective.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }

        $this->asOwner(fn () => $this->migrate('down'));
        $this->asOwner(fn () => $this->migrate('down'));
        $this->assertSame($before, $this->catalogSnapshot());
        $this->assertSame($baseGuard, $this->guardSnapshot());
        $this->assertNull(DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conrelid='entitlements'::regclass
             AND conname='entitlements_case_requirement_check'",
        ));
    }

    public function test_invalid_history_aborts_atomically_and_restores_force_rls(): void
    {
        $participantId = app(RlsContextRunner::class)->runAsService(function (): int {
            $key = strtoupper(substr((string) Str::ulid(), 0, 20));
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'name' => 'invalid', 'ref_code' => $key,
                'organization_code' => $key, 'display_name' => 'invalid',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
                'package_id' => null, 'source_system' => 'RESULT_TEST', 'full_name' => 'invalid',
                'intended_field' => 'KAIGO', 'phone' => '620000000000',
            ]);
            DB::table('entitlements')->insert([
                'participant_id' => $participant, 'order_id' => null,
                'assessment_case_id' => null, 'test_type' => 'ist', 'status' => 'ready',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $participant;
        });
        $before = $this->catalogSnapshot();

        try {
            $this->asOwner(fn () => $this->migrate('up'));
            $this->fail('Invalid history must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('historical entitlement case requirements', $exception->getMessage());
        }

        $this->assertSame($before, $this->catalogSnapshot());
        $this->assertNull(DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conrelid='entitlements'::regclass
             AND conname='entitlements_case_requirement_check'",
        ));
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('entitlements')
            ->where('participant_id', $participantId)->delete());
    }

    public function test_counterfeit_named_constraint_is_rejected_without_catalog_delta(): void
    {
        $this->asOwner(fn () => DB::statement(
            'ALTER TABLE entitlements ADD CONSTRAINT entitlements_case_requirement_check CHECK (true) NOT VALID',
        ));
        $before = $this->catalogSnapshot(includeRequirement: true);

        foreach (['up', 'down'] as $operation) {
            try {
                $this->asOwner(fn () => $this->migrate($operation));
                $this->fail('Counterfeit constraint must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('counterfeit or partial requirement', $exception->getMessage());
            }
            $this->assertSame($before, $this->catalogSnapshot(includeRequirement: true));
        }
    }

    public function test_counterfeit_integrated_guard_is_rejected_without_catalog_delta(): void
    {
        $this->asOwner(fn () => $this->migrate('up'));
        $exactBody = $this->guardSnapshot()['body'];
        $this->asOwner(fn () => $this->replaceGuardBody('BEGIN RETURN NEW; END;'));
        $before = $this->guardSnapshot();

        try {
            foreach (['up', 'down'] as $operation) {
                try {
                    $this->asOwner(fn () => $this->migrate($operation));
                    $this->fail('Counterfeit integrated guard must fail closed.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('counterfeit or partial requirement and guard state', $exception->getMessage());
                }
                $this->assertSame($before, $this->guardSnapshot());
            }
        } finally {
            $this->asOwner(fn () => $this->replaceGuardBody($exactBody));
            $this->asOwner(fn () => $this->migrate('down'));
        }
    }

    public function test_exact_integrated_graph_is_accepted_and_prevents_unsafe_guard_rollback(): void
    {
        $this->asOwner(fn () => $this->migrate('up'));
        $graph = $this->integratedGraph('integrated');
        app(RlsContextRunner::class)->runAsService(function () use ($graph): void {
            DB::table('entitlements')->insert($this->entitlementRow(
                $graph['participant'],
                null,
                $graph['case'],
                'ist',
            ));
            DB::table('entitlements')->insert($this->entitlementRow(
                $graph['participant'],
                null,
                null,
                'dass21',
            ));
        });
        $unmappedCase = app(RlsContextRunner::class)->runAsService(fn (): int => DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $graph['participant'],
            'organization_id' => $graph['branch'], 'package_id' => $graph['package'],
            'origin' => 'INTEGRATED', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlementRow($graph['participant'], null, $unmappedCase, 'papi'),
        ));

        try {
            $this->asOwner(fn () => $this->migrate('down'));
            $this->fail('Integrated entitlement history must prevent guard rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('integrated entitlement history prevents guard rollback', $exception->getMessage());
        }

        app(RlsContextRunner::class)->runAsService(fn () => DB::table('entitlements')
            ->where('participant_id', $graph['participant'])->delete());
        $this->asOwner(fn () => $this->migrate('down'));
    }

    /** @return array{participant:int,case:int,order:int,entitlement:int} */
    private function directGraph(string $suffix): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($suffix): array {
            $key = strtoupper(substr((string) Str::ulid(), 0, 20));
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'name' => $suffix, 'ref_code' => $key,
                'organization_code' => $key, 'display_name' => $suffix,
            ]);
            $package = DB::table('packages')->insertGetId([
                'code' => 'req-'.$key, 'name' => $suffix, 'amount' => 99000,
                'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['dass21', 'ist', 'papi'] as $sort => $type) {
                DB::table('package_items')->insert([
                    'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
                'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC', 'full_name' => $suffix,
                'intended_field' => 'KAIGO', 'phone' => '620000000000',
            ]);
            $publicId = (string) Str::ulid();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant, 'organization_id' => $branch,
                'package_id' => $package, 'origin' => 'DIRECT_PUBLIC', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $method = DB::table('payment_methods')->insertGetId([
                'code' => 'req-'.$key, 'display_name' => $suffix, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $order = DB::table('orders')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant, 'assessment_case_id' => $case,
                'payment_method_id' => $method, 'status' => 'paid', 'amount' => 99000,
                'currency' => 'IDR', 'paid_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('entitlements')->insert($this->entitlementRow($participant, $order, null, 'dass21'));
            $entitlement = DB::table('entitlements')->insertGetId(
                $this->entitlementRow($participant, $order, $case, 'ist'),
            );
            DB::table('entitlements')->insert($this->entitlementRow($participant, $order, $case, 'papi'));
            $session = DB::table('test_sessions')->insertGetId([
                'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
                'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
                'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
                'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('test_session_grants')->insert([
                'test_session_id' => $session, 'assessment_case_id' => $case,
                'participant_id' => $participant, 'organization_id' => $branch,
                'test_type' => 'ist', 'origin' => 'DIRECT_PUBLIC', 'grant_kind' => 'entitlement',
                'order_id' => $order, 'entitlement_id' => $entitlement, 'created_at' => now(),
            ]);

            return compact('participant', 'case', 'order', 'entitlement');
        });
    }

    /** @return array{participant:int,case:int,branch:int,package:int} */
    private function integratedGraph(string $suffix): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($suffix): array {
            $key = strtoupper(substr((string) Str::ulid(), 0, 20));
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'name' => $suffix, 'ref_code' => $key,
                'organization_code' => $key, 'display_name' => $suffix,
            ]);
            $package = DB::table('packages')->insertGetId([
                'code' => 'int-'.$key, 'name' => $suffix, 'amount' => 99000,
                'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['dass21', 'ist', 'papi'] as $sort => $type) {
                DB::table('package_items')->insert([
                    'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
                'package_id' => $package, 'source_system' => 'SYNTHETIC', 'full_name' => $suffix,
                'intended_field' => 'UMUM', 'phone' => '620000000001',
            ]);
            $publicId = (string) Str::ulid();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant, 'organization_id' => $branch,
                'package_id' => $package, 'origin' => 'INTEGRATED', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $client = DB::table('integration_clients')->insertGetId([
                'organization_id' => $branch, 'client_id' => 'client-'.$key,
                'credential_reference' => 'synthetic', 'result_delivery_mode' => 'POLL',
                'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('assessment_participants')->insert([
                'assessment_case_id' => $case, 'integration_client_id' => $client,
                'organization_id' => $branch, 'participant_id' => $participant, 'package_id' => $package,
                'assessment_attempt_id' => $publicId, 'source_system' => 'SYNTHETIC',
                'external_candidate_id' => 'candidate-'.$key, 'funding_mode' => 'SPONSORED',
                'assessment_status' => 'READY', 'result_version' => 0, 'idempotency_key' => 'key-'.$key,
                'request_hash' => hash('sha256', 'request-'.$key),
                'logical_assessment_key' => hash('sha256', 'logical-'.$key),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return compact('participant', 'case', 'branch', 'package');
        });
    }

    /** @return array<string,mixed> */
    private function entitlementRow(int $participant, ?int $order, ?int $case, string $type): array
    {
        return [
            'participant_id' => $participant, 'order_id' => $order, 'assessment_case_id' => $case,
            'test_type' => $type, 'status' => 'ready', 'ready_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function catalogSnapshot(bool $includeRequirement = false): array
    {
        $constraintFilter = $includeRequirement ? '' : "AND con.conname <> 'entitlements_case_requirement_check'";

        return array_values(array_map(static fn (object $row): array => (array) $row, DB::select(<<<SQL
            SELECT 'table' kind,class.relname name,class.relrowsecurity::text value,
                   class.relforcerowsecurity::text extra,COALESCE(class.relacl::text,'') more
            FROM pg_class class WHERE class.oid='entitlements'::regclass
            UNION ALL
            SELECT 'constraint',con.conname,pg_get_constraintdef(con.oid,false),
                   con.convalidated::text,con.condeferrable::text
            FROM pg_constraint con WHERE con.conrelid='entitlements'::regclass {$constraintFilter}
            UNION ALL
            SELECT 'index',indexname,indexdef,'','' FROM pg_indexes
            WHERE schemaname='public' AND tablename='entitlements'
            UNION ALL
            SELECT 'trigger',trigger.tgname,pg_get_triggerdef(trigger.oid,false),
                   trigger.tgenabled::text,procedure.prosrc
            FROM pg_trigger trigger JOIN pg_proc procedure ON procedure.oid=trigger.tgfoid
            WHERE trigger.tgrelid='entitlements'::regclass AND NOT trigger.tgisinternal
              AND trigger.tgname <> 'entitlements_case_identity_guard'
            UNION ALL
            SELECT 'policy',policyname,cmd,COALESCE(qual,''),COALESCE(with_check,'')
            FROM pg_policies WHERE schemaname='public' AND tablename='entitlements'
            ORDER BY kind,name
            SQL)));
    }

    /** @return array{type:int,enabled:string,namespace:string,name:string,security_definer:bool,config:string,language:string,body:string,definition:string} */
    private function guardSnapshot(): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT trigger.tgtype type,trigger.tgenabled enabled,namespace.nspname namespace,
                   function.proname name,function.prosecdef security_definer,
                   function.proconfig::text config,language.lanname language,function.prosrc body,
                   pg_get_triggerdef(trigger.oid,false) definition
            FROM pg_trigger trigger JOIN pg_proc function ON function.oid=trigger.tgfoid
            JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
            JOIN pg_language language ON language.oid=function.prolang
            WHERE trigger.tgrelid='entitlements'::regclass AND NOT trigger.tgisinternal
              AND trigger.tgname='entitlements_case_identity_guard'
            SQL);
        if ($row === null) {
            throw new RuntimeException('Entitlement case guard unavailable.');
        }

        $data = (array) $row;

        return [
            'type' => (int) $data['type'],
            'enabled' => (string) $data['enabled'],
            'namespace' => (string) $data['namespace'],
            'name' => (string) $data['name'],
            'security_definer' => (bool) $data['security_definer'],
            'config' => (string) $data['config'],
            'language' => (string) $data['language'],
            'body' => (string) $data['body'],
            'definition' => (string) $data['definition'],
        ];
    }

    private function replaceGuardBody(string $body): void
    {
        $sql = 'CREATE OR REPLACE FUNCTION app_private.guard_generic_entitlement_case_identity() RETURNS trigger '
            .'LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$'
            .$body.'$guard$;';
        if (! DB::statement($sql)) {
            throw new RuntimeException('Unable to replace entitlement case guard.');
        }
    }

    private function assertRejected(callable $operation): void
    {
        try {
            app(RlsContextRunner::class)->runAsService($operation);
            $this->fail('Constraint was not enforced.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/2026_09_10_000400_enforce_generic_entitlement_case_identity.php');
        if (! $migration instanceof Migration) {
            throw new RuntimeException('Requirement migration unavailable.');
        }

        return $migration;
    }

    private function migrate(string $operation): void
    {
        if (! in_array($operation, ['up', 'down'], true)) {
            throw new RuntimeException('Unsupported migration operation.');
        }
        $migration = $this->migration();
        (new \ReflectionMethod($migration, $operation))->invoke($migration);
    }

    private function asOwner(callable $callback): mixed
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.requirement_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('requirement_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            return $callback();
        } finally {
            DB::disconnect('requirement_owner');
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            config()->set('database.connections.requirement_owner', null);
        }
    }
}
