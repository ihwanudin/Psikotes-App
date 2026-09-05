<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/** PostgreSQL runtime evidence for sensitive checkout consent audit privacy. */
final class CheckoutConsentAuditPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        app(RlsContextRunner::class)->runAsService(function (): void {
            foreach (['checkout.confirmed', 'checkout.consent_reaccepted', 'checkout_handoff.issued'] as $action) {
                DB::table('audit_logs')->insert([
                    'branch_id' => null,
                    'actor_type' => 'checkout_session',
                    'actor_id' => 'synthetic-private-session',
                    'action' => $action,
                    'subject_type' => 'SyntheticAttempt',
                    'subject_id' => 'synthetic-private-attempt',
                    'context' => json_encode(['synthetic' => true], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(),
                    'expires_at' => now()->addYear(),
                ]);
            }
        });
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_sensitive_checkout_consent_audits_are_service_only_without_hiding_other_audits(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertSame(0, DB::table('audit_logs')->count());

        $runner = app(RlsContextRunner::class);
        $this->assertSame(
            ['checkout.confirmed', 'checkout.consent_reaccepted', 'checkout_handoff.issued'],
            $runner->run(new RlsContext('service'), fn (): array => DB::table('audit_logs')->orderBy('id')->pluck('action')->all()),
        );
        $this->assertSame(
            ['checkout_handoff.issued'],
            $runner->run(new RlsContext('super_admin'), fn (): array => DB::table('audit_logs')->orderBy('id')->pluck('action')->all()),
        );

        $policy = DB::table('pg_policies')->where('schemaname', 'public')->where('tablename', 'audit_logs')
            ->where('policyname', 'audit_logs_checkout_consent_privacy')->sole(['cmd', 'permissive', 'roles', 'qual']);
        $this->assertSame('SELECT', $policy->cmd);
        $this->assertSame('RESTRICTIVE', $policy->permissive);
        $this->assertSame('{psikotes_runtime}', $policy->roles);
        $this->assertStringContainsString('checkout.confirmed', (string) $policy->qual);
        $this->assertStringContainsString('checkout.consent_reaccepted', (string) $policy->qual);
        $this->assertStringContainsString('service', (string) $policy->qual);
    }

    public function test_policy_migration_cycles_and_matches_fresh_schema_and_rollback(): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        $writeBefore = $this->policy('public', 'audit_logs_write');
        $schema = 'checkout_audit_policy_'.strtolower(Str::random(12));

        config()->set('database.connections.checkout_audit_policy_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('checkout_audit_policy_ddl_test');
        try {
            $this->assertSame('org_test_owner', $owner->selectOne('SELECT current_user AS name')->name);
            $owner->beginTransaction();
            $owner->statement('CREATE SCHEMA "'.$schema.'"');
            $owner->statement('CREATE TABLE "'.$schema.'".audit_logs (action text NOT NULL)');
            $owner->statement('SET LOCAL search_path TO "'.$schema.'", public');

            DB::setDefaultConnection('checkout_audit_policy_ddl_test');
            Schema::clearResolvedInstance('db.schema');
            $migration = require database_path('migrations/2026_09_06_000100_restrict_checkout_consent_audit_read_policy.php');

            $migration->up();
            $up = $this->policy($schema, 'audit_logs_checkout_consent_privacy');
            $this->assertSame('SELECT', $up['cmd']);
            $this->assertSame('RESTRICTIVE', $up['permissive']);
            $this->assertStringContainsString('checkout.confirmed', $up['qual']);
            $this->assertStringContainsString('checkout.consent_reaccepted', $up['qual']);
            $this->assertStringContainsString('service', $up['qual']);

            $migration->down();
            $this->assertNull($this->findPolicy($schema, 'audit_logs_checkout_consent_privacy'));

            $migration->up();
            $this->assertSame($up, $this->policy($schema, 'audit_logs_checkout_consent_privacy'));
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('checkout_audit_policy_ddl_test');
            config()->set('database.connections.checkout_audit_policy_ddl_test', null);
        }

        $this->assertEquals($writeBefore, $this->policy('public', 'audit_logs_write'));

        $schemaSql = file_get_contents(database_path('schema/rls_policies.sql'));
        $rollbackSql = file_get_contents(database_path('schema/rls_rollback.sql'));
        $this->assertIsString($schemaSql);
        $this->assertIsString($rollbackSql);
        $this->assertStringContainsString(
            'CREATE POLICY audit_logs_checkout_consent_privacy ON audit_logs AS RESTRICTIVE FOR SELECT TO psikotes_runtime',
            $schemaSql,
        );
        $this->assertStringContainsString("action NOT IN ('checkout.confirmed', 'checkout.consent_reaccepted')", $schemaSql);
        $this->assertStringContainsString(
            'DROP POLICY IF EXISTS audit_logs_checkout_consent_privacy ON audit_logs;',
            $rollbackSql,
        );
    }

    /** @return array{cmd:string,permissive:string,qual:string,with_check:?string} */
    private function policy(string $schema, string $name): array
    {
        $policy = $this->findPolicy($schema, $name);
        $this->assertNotNull($policy);

        return $policy;
    }

    /** @return array{cmd:string,permissive:string,qual:string,with_check:?string}|null */
    private function findPolicy(string $schema, string $name): ?array
    {
        $policy = DB::table('pg_policies')->where('schemaname', $schema)->where('tablename', 'audit_logs')
            ->where('policyname', $name)->first(['cmd', 'permissive', 'qual', 'with_check']);
        if ($policy === null) {
            return null;
        }

        return [
            'cmd' => (string) $policy->cmd,
            'permissive' => (string) $policy->permissive,
            'qual' => (string) $policy->qual,
            'with_check' => $policy->with_check === null ? null : (string) $policy->with_check,
        ];
    }
}
