<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RLS-GAP-01..04 remediation (tasks/handoffs/f2/database-rls-coverage-audit.md,
 * Group A -- Lead sign-off 2026-09-21). All four generic_assessment_result_*
 * tables (Selection-integration result-callback pipeline) shipped with no
 * RLS/GRANT at all. Read/write-path mapping (see the handoff doc) confirmed
 * every real production caller already runs inside an explicit `service`
 * RLS context: the scheduled `integrations:dispatch-generic-result-callbacks`
 * command and the `DispatchGenericAssessmentResultCallback` queue job both go
 * through GenericAssessmentResultCallbackOrchestrator, which wraps every
 * DB-touching block in `RlsContextRunner::run(new RlsContext('service'), ...)`;
 * the Selection poll controller's whole request is wrapped by
 * AuthenticateSelectionResultPoll middleware the same way. So a plain
 * service-only policy set is safe here -- no caller needed rewriting, unlike
 * Group B/C.
 *
 * All four tables get SELECT+INSERT+UPDATE (no DELETE anywhere -- every
 * table's own trigger, added by its original creation migration, rejects
 * DELETE unconditionally). This looked wrong at first for
 * `..._versions`/`..._outbox`, whose triggers ALSO reject every UPDATE
 * unconditionally ("append-only") -- a first draft of this migration granted
 * those two only SELECT+INSERT. That failed a real PostgreSQL proof test:
 * both GenericAssessmentResultStore::persistAuthorizedSnapshot() and
 * GenericAssessmentResultOutbox::enqueueExact() take a `lockForUpdate()` read
 * on their own table before inserting the next row (the standard
 * next-sequence-number race guard), and PostgreSQL's `SELECT ... FOR UPDATE`
 * requires the UPDATE privilege even though the resulting lock is never
 * followed by an actual UPDATE statement. So UPDATE is granted (and an
 * UPDATE policy exists) on all four -- the real "nothing can ever actually
 * update this row" guarantee for `..._versions`/`..._outbox` still comes
 * entirely from their own append-only trigger, not from GRANT/RLS; this
 * migration's job is only to make sure `psikotes_runtime` under a `service`
 * context can do what the application code legitimately needs to do.
 *
 * Caution for whoever wires in the two callers that don't exist yet
 * (GenericAssessmentResultStore::persistAuthorizedSnapshot(),
 * GenericAssessmentResultOutbox::enqueueExact() -- both still test-only per
 * their own docblocks, "future authorized scoring boundary"): neither class
 * asserts an RLS context itself, so the new caller must wrap its write in
 * `RlsContextRunner::runAsService()` (or an equivalent already-service
 * context) -- the policies below will otherwise reject it outright.
 */
return new class extends Migration
{
    private const TABLES = [
        'generic_assessment_result_versions',
        'generic_assessment_result_outbox',
        'generic_assessment_result_dispatch_attempts',
        'generic_assessment_result_callback_schedules',
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
