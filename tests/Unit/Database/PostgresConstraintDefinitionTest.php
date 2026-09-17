<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

final class PostgresConstraintDefinitionTest extends TestCase
{
    public function test_tenant_identity_checks_and_single_default_branch_are_defined(): void
    {
        $migration = $this->migration('2026_08_25_000100_create_tenant_identity_tables.php');

        $this->assertStringContainsString('branches_one_default_idx', $migration);
        $this->assertStringContainsString('WHERE is_default = true', $migration);
        $this->assertStringContainsString('participants_referral_source_check', $migration);
        $this->assertStringContainsString('consent_records_status_check', $migration);
        $this->assertStringContainsString('consent_records_timestamp_check', $migration);
    }

    public function test_payment_state_and_amount_checks_are_defined(): void
    {
        $migration = $this->migration('2026_08_25_000200_create_payment_and_operations_tables.php');

        $this->assertStringContainsString('orders_status_check', $migration);
        $this->assertStringContainsString('orders_amount_check CHECK (amount > 0)', $migration);
        $this->assertStringContainsString('entitlements_status_check', $migration);
        $this->assertStringContainsString("unique(['participant_id', 'test_type'])", $migration);
    }

    public function test_dass_namespace_and_response_ranges_are_defined(): void
    {
        $migration = $this->migration('2026_08_25_000300_create_isolated_dass_schema.php');

        $this->assertStringContainsString('CREATE SCHEMA IF NOT EXISTS dass', $migration);
        $this->assertStringContainsString('item_number BETWEEN 1 AND 21', $migration);
        $this->assertStringContainsString('response_value BETWEEN 0 AND 3', $migration);
    }

    private function migration(string $file): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3)."/database/migrations/{$file}");
        $this->assertIsString($contents);

        return $contents;
    }
}
