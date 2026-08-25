<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class PostgresRoleSeparationTest extends TestCase
{
    public function test_runtime_role_is_non_owner_without_rls_bypass(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3).'/database/schema/postgres_roles.sql');

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE ROLE psikotes_runtime', $sql);
        $this->assertStringContainsString('NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS', $sql);
        $this->assertStringNotContainsString('BYPASSRLS LOGIN', $sql);
        $this->assertStringContainsString('CREATE SCHEMA IF NOT EXISTS dass', $sql);
    }

    public function test_compose_uses_distinct_runtime_and_owner_credentials(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 3).'/compose.yaml');

        $this->assertIsString($compose);
        $this->assertStringContainsString('DB_USERNAME: ${DB_RUNTIME_USERNAME:-psikotes_runtime}', $compose);
        $this->assertStringContainsString('POSTGRES_USER: ${DB_OWNER_USERNAME:-psikotes_owner}', $compose);
        $this->assertStringContainsString('DB_MIGRATION_USERNAME: ${DB_OWNER_USERNAME:-psikotes_owner}', $compose);
    }

    public function test_laravel_has_distinct_runtime_and_migration_connections(): void
    {
        $config = file_get_contents(dirname(__DIR__, 3).'/config/database.php');

        $this->assertIsString($config);
        $this->assertStringContainsString("'pgsql_migration' => [", $config);
        $this->assertStringContainsString("env('DB_RUNTIME_USERNAME', env('DB_USERNAME', 'root'))", $config);
        $this->assertStringContainsString("env('DB_MIGRATION_USERNAME', env('DB_OWNER_USERNAME', 'root'))", $config);
    }
}
