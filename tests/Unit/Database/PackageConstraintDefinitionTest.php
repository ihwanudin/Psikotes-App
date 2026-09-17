<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Tests\TestCase;

final class PackageConstraintDefinitionTest extends TestCase
{
    public function test_postgres_constraints_lock_currency_activation_and_test_types(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_08_25_000175_create_test_packages.php',
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString("packages_currency_check CHECK (currency = 'IDR')", $migration);
        $this->assertStringContainsString('packages_activation_check', $migration);
        $this->assertStringContainsString('amount IS NOT NULL AND amount > 0', $migration);
        $this->assertStringContainsString('package_items_test_type_check', $migration);
        $this->assertStringContainsString("'ist', 'papi', 'rmib', 'kraepelin', 'dass21'", $migration);
    }
}
