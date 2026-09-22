<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RLS-GAP-05/06 remediation (tasks/handoffs/f2/database-rls-coverage-audit.md,
 * Group B -- Lead sign-off 2026-09-21/22). Both sequence-counter tables
 * (report_number_sequences, test_number_sequences) shipped with no
 * RLS/GRANT at all.
 *
 * Read/write-path mapping:
 * - report_number_sequences: one caller, ReportNumberIssuer::issue(),
 *   called only from ReportDocumentIssuer::issue() (already wrapped in
 *   RlsContextRunner::runAsService()), reached only from the Filament
 *   ReportGeneration page (AdminAbility::GenerateReports -- psychologist
 *   or super_admin). Already safe under a service-only policy, no code
 *   change needed.
 * - test_number_sequences: 3 of 4 callers (RegisterParticipant,
 *   ProvisionSelectionParticipant, ProvisionAssessmentParticipant) already
 *   run inside a service context. The 4th, the scheduled
 *   `test-numbers:prepare-month` command, had NO RLS context at all --
 *   fixed in the same PR by wrapping its call in
 *   RlsContextRunner::runAsService() (App\Console\Commands\PrepareTestNumberMonth).
 *
 * Neither table has a trigger of any kind (unlike Group A's
 * generic_assessment_result_* tables) -- both are plain row-lock counters
 * (SELECT ... FOR UPDATE then UPDATE), so SELECT+INSERT+UPDATE is the
 * genuine, ungapped requirement; no DELETE, no TRUNCATE.
 */
return new class extends Migration
{
    private const TABLES = [
        'report_number_sequences',
        'test_number_sequences',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $this->secure($table);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $this->execute(<<<SQL
                DROP POLICY IF EXISTS {$table}_service_select ON {$table};
                DROP POLICY IF EXISTS {$table}_service_insert ON {$table};
                DROP POLICY IF EXISTS {$table}_service_update ON {$table};
                ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;

                REVOKE ALL PRIVILEGES ON TABLE {$table} FROM psikotes_runtime;
                GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE {$table} TO psikotes_runtime;
                SQL);
        }
    }

    private function secure(string $table): void
    {
        $this->execute(<<<SQL
            REVOKE ALL PRIVILEGES ON TABLE {$table} FROM psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE ON TABLE {$table} TO psikotes_runtime;

            ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
            ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;

            CREATE POLICY {$table}_service_select ON {$table}
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() = 'service');
            CREATE POLICY {$table}_service_insert ON {$table}
                FOR INSERT TO psikotes_runtime
                WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY {$table}_service_update ON {$table}
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    /**
     * DB::unprepared() requires a literal-string argument (PHPStan); this
     * migration's SQL is built from a hardcoded private const table list,
     * never external input, so raw PDO exec() is the established workaround
     * (see PR #57, 2026_09_21_000100_fix_kraepelin_randomization_mode.php).
     */
    private function execute(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }
};
