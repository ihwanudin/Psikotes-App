<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

final class PostgresRlsDefinitionTest extends TestCase
{
    private string $sql;

    protected function setUp(): void
    {
        parent::setUp();

        $contents = file_get_contents(dirname(__DIR__, 3).'/database/schema/rls_policies.sql');
        $this->assertIsString($contents);
        $this->sql = $contents;
    }

    public function test_tenant_and_sensitive_tables_force_rls(): void
    {
        foreach ([
            'branches',
            'admins',
            'participants',
            'referral_visits',
            'consent_records',
            'payment_methods',
            'orders',
            'entitlements',
            'audit_logs',
            'outbox_messages',
            'dass.assessments',
            'dass.responses',
            'dass.results',
        ] as $table) {
            $this->assertStringContainsString("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY", $this->sql);
            $this->assertStringContainsString("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY", $this->sql);
        }
    }

    public function test_missing_context_resolves_to_null_instead_of_privileged_defaults(): void
    {
        $this->assertStringContainsString("current_setting('app.role', true)", $this->sql);
        $this->assertStringContainsString("current_setting('app.branch_id', true)", $this->sql);
        $this->assertStringContainsString("current_setting('app.participant_id', true)", $this->sql);
        $this->assertStringNotContainsString("COALESCE(app_private.app_role(), 'service')", $this->sql);
    }

    public function test_application_context_is_set_transaction_locally(): void
    {
        $runner = file_get_contents(dirname(__DIR__, 3).'/app/Security/RlsContextRunner.php');
        $this->assertIsString($runner);

        $this->assertStringContainsString("set_config('app.role', ?, true)", $runner);
        $this->assertStringContainsString("set_config('app.branch_id', ?, true)", $runner);
        $this->assertStringContainsString("set_config('app.participant_id', ?, true)", $runner);
    }

    public function test_dass_policy_excludes_all_admin_roles(): void
    {
        $dassPolicy = $this->section('dass_policy_start', 'dass_policy_end');

        $this->assertStringContainsString("app_private.app_role() IN ('service', 'psychologist')", $dassPolicy);
        $this->assertStringNotContainsString('super_admin', $dassPolicy);
        $this->assertStringNotContainsString('branch_admin', $dassPolicy);
        $this->assertStringNotContainsString("'staff'", $dassPolicy);
    }

    public function test_financial_mutations_are_limited_to_service_and_super_admin(): void
    {
        $financePolicy = $this->section('finance_policy_start', 'finance_policy_end');

        $this->assertStringContainsString("app_private.app_role() IN ('service', 'super_admin')", $financePolicy);
        $this->assertStringContainsString("app_private.app_role() IN ('branch_admin', 'staff')", $financePolicy);
    }

    public function test_payment_webhook_ledger_forces_rls_and_only_service_can_write(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 3).'/database/migrations/2026_08_25_000800_create_payment_webhook_events.php');
        $this->assertIsString($migration);

        $this->assertStringContainsString('ALTER TABLE payment_webhook_events ENABLE ROW LEVEL SECURITY', $migration);
        $this->assertStringContainsString('ALTER TABLE payment_webhook_events FORCE ROW LEVEL SECURITY', $migration);
        $this->assertStringContainsString("app_private.app_role() IN ('service', 'super_admin')", $migration);
        $this->assertStringContainsString("app_private.app_role() = 'service'", $migration);
    }

    private function section(string $start, string $end): string
    {
        $matches = [];
        $matched = preg_match("/-- {$start}(.*?)-- {$end}/s", $this->sql, $matches);
        $this->assertSame(1, $matched);

        return $matches[1];
    }
}
