<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GenericEntitlementUniquenessCompatibilityBoundaryTest extends TestCase
{
    public function test_legacy_boundary_is_restored_for_pre_contraction_migration_tests(): void
    {
        $this->assertRuntimeRole();
        $this->asOwner(function (): void {
            $this->assertNull($this->legacyConstraint());
            $this->assertNotNull($this->index('entitlements_case_test_type_unique'));
            $this->assertNotNull($this->index('entitlements_dass_participant_unique'));
            $this->migrateDown();
        });
        $this->assertRuntimeRole();

        $legacy = $this->legacyConstraint();
        $this->assertNotNull($legacy);
        $this->assertSame('UNIQUE (participant_id, test_type)', $legacy);
        $this->assertNotNull($this->index('entitlements_case_test_type_unique'));
        $this->assertNull($this->index('entitlements_dass_participant_unique'));
    }

    private function assertRuntimeRole(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_user AS name, role.rolsuper, role.rolbypassrls
            FROM pg_roles role WHERE role.rolname = current_user
            SQL);
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
    }

    private function legacyConstraint(): ?string
    {
        $definition = DB::scalar(<<<'SQL'
            SELECT pg_get_constraintdef(oid,false) definition
            FROM pg_constraint WHERE conrelid='entitlements'::regclass
              AND conname='entitlements_participant_id_test_type_unique'
            SQL);

        return is_string($definition) ? $definition : null;
    }

    private function index(string $name): ?object
    {
        if (! in_array($name, ['entitlements_case_test_type_unique', 'entitlements_dass_participant_unique'], true)) {
            throw new RuntimeException('Unsupported entitlement compatibility index.');
        }

        return DB::selectOne(<<<'SQL'
            SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='entitlements'
              AND indexname=?
            SQL, [$name]);
    }

    private function migrateDown(): void
    {
        $migration = require database_path('migrations/2026_09_10_000500_contract_generic_entitlement_uniqueness.php');
        if (! $migration instanceof Migration) {
            throw new RuntimeException('Uniqueness migration unavailable.');
        }
        (new \ReflectionMethod($migration, 'down'))->invoke($migration);
    }

    private function asOwner(callable $callback): mixed
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.uniqueness_boundary_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('uniqueness_boundary_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            return $callback();
        } finally {
            DB::disconnect('uniqueness_boundary_owner');
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            config()->set('database.connections.uniqueness_boundary_owner', null);
        }
    }
}
