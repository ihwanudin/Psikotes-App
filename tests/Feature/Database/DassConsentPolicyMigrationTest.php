<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\OrganizationPaymentTestCase;

final class DassConsentPolicyMigrationTest extends OrganizationPaymentTestCase
{
    public function test_postgres_policy_migration_is_a_no_op_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        DB::statement('CREATE TABLE migration_sentinel (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        DB::table('migration_sentinel')->insert(['id' => 1, 'value' => 'unchanged']);
        $schemaBefore = DB::select("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name");

        $migration = require database_path('migrations/2026_09_05_000500_restrict_dass_consent_read_policy.php');
        $migration->up();
        $migration->down();

        $this->assertEquals($schemaBefore, DB::select("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"));
        $this->assertDatabaseHas('migration_sentinel', ['id' => 1, 'value' => 'unchanged']);
    }
}
