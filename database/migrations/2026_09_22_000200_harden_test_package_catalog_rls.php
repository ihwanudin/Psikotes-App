<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RLS-GAP-07/08 remediation (packages, package_items -- Group C, Lead
 * sign-off 2026-09-22). Neither table ever got ENABLE ROW LEVEL SECURITY
 * or a single policy; both shipped fully open to psikotes_runtime.
 *
 * FORCE-without-ENABLE anomaly (explained, deliberately not fixed at its
 * source -- Lead's explicit instruction): the ratchet's discovery query
 * found relforcerowsecurity=true on both tables despite relrowsecurity=
 * false. Root cause:
 * database/migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php:496-502's
 * setPostgresForceRls() reuses the exact same table list as that
 * migration's own lockPostgresTables() lock set (participants, packages,
 * package_items, assessment_cases, orders, entitlements) to toggle FORCE
 * off/on around a backfill, without checking whether each table actually
 * has RLS enabled. packages/package_items only needed the LOCK there
 * (their rows are read via joins during that migration's backfill), not
 * the FORCE toggle -- but the two lists were never split, so FORCE got
 * set unconditionally. Every later migration's own capture-then-restore
 * dance (2026_09_09_000700, 2026_09_10_000300, 2026_09_10_000400)
 * faithfully preserves whatever it finds, so they perpetuate the anomaly
 * rather than cause it. Lead's call: do not edit 000600 -- it is already
 * applied in every migrated environment, and editing a historical
 * migration would make schema history diverge across environments. This
 * migration's own idempotent ENABLE+FORCE below is what makes the
 * previously-meaningless FORCE flag actually mean something, regardless
 * of whatever value it already carried in when this runs.
 *
 * Read/write-path mapping (Explore agent, 2026-09-22): every real write is
 * either the `service`-context TestPackageSeeder (the only inserter --
 * TestPackagePolicy::create()/delete() are always false, no admin path
 * creates or deletes a package) or one of many `service`-context action
 * classes across Actions/Payments, Actions/Integrations,
 * Services/Integrations, Services/AssessmentSessions (RegisterParticipant,
 * ReserveAssessmentBill, CheckoutSessionLifecycle, CaseAuthorizationResolver,
 * etc. -- all already assert/establish an RlsContextRunner service
 * context). The one real admin write path is Filament's
 * TestPackageResource (edits amount/consultation_amount/is_active on
 * `packages` only -- package_items has no admin UI at all), gated to
 * super_admin via AdminAbility::ManageTestPackages.
 *
 * SELECT is intentionally broader than the originally-scoped "service OR
 * super_admin" (Lead's sign-off, widening the design after this mapping
 * surfaced it): AssessmentParticipantResource's package_id filter and
 * AssessmentParticipantExportController both read `packages` too, gated
 * only by AdminAbility::ViewParticipants, which is true for every admin
 * role (super_admin, branch_admin, staff, psychologist) -- a
 * super_admin-only read policy would 403 the other three roles' own
 * participant filter and CSV export. packages/package_items are a global,
 * non-participant-scoped catalog (no branch_id/organization_id column at
 * all), so a read policy this broad leaks nothing branch-specific.
 *
 * lockForUpdate() reads (ReserveAssessmentBill on both tables; several
 * TestPackage::query()->lockForUpdate()->find() call sites on packages)
 * need UPDATE privilege even though no literal UPDATE statement follows
 * (Group A's lesson: SELECT ... FOR UPDATE requires the UPDATE grant).
 * package_items' UPDATE policy is service-only for exactly that reason --
 * no admin path ever writes to it -- while packages' UPDATE policy also
 * recognizes super_admin, for the real Filament edit path.
 *
 * Two currently-unwrapped call sites were fixed in this same PR (code, not
 * table design): ParticipantRegistrationController::create()'s
 * TestPackage::query() call (the two queries either side of it in the same
 * method were already service-wrapped; this one wasn't), and
 * StoreParticipantRegistrationRequest's package_id existence check, which
 * needed a closure rule rather than Rule::exists('packages','id')->where()
 * -- that rule object's actual query only runs later, inside the
 * validator's own DatabasePresenceVerifier, well after rules() returns, so
 * wrapping rules() itself in runAsService() would have had no effect.
 * TestPackageSeeder was also wrapped (it had zero RLS context at all).
 */
return new class extends Migration
{
    /** @var array<string, array{select: list<string>, insert: list<string>, update: list<string>}> */
    private const TABLES = [
        'packages' => [
            'select' => ['service', 'super_admin', 'branch_admin', 'staff', 'psychologist'],
            'insert' => ['service'],
            'update' => ['service', 'super_admin'],
        ],
        'package_items' => [
            'select' => ['service', 'super_admin', 'branch_admin', 'staff', 'psychologist'],
            'insert' => ['service'],
            'update' => ['service'],
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table => $roles) {
            $this->secure($table, $roles);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::TABLES) as $table) {
            $this->execute(<<<SQL
                DROP POLICY IF EXISTS {$table}_read ON {$table};
                DROP POLICY IF EXISTS {$table}_insert ON {$table};
                DROP POLICY IF EXISTS {$table}_update ON {$table};
                ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;

                REVOKE ALL PRIVILEGES ON TABLE {$table} FROM psikotes_runtime;
                GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE {$table} TO psikotes_runtime;
                SQL);
        }
    }

    /** @param array{select: list<string>, insert: list<string>, update: list<string>} $roles */
    private function secure(string $table, array $roles): void
    {
        $select = $this->roleList($roles['select']);
        $insert = $this->roleList($roles['insert']);
        $update = $this->roleList($roles['update']);

        $this->execute(<<<SQL
            REVOKE ALL PRIVILEGES ON TABLE {$table} FROM psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE ON TABLE {$table} TO psikotes_runtime;

            ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
            ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;

            CREATE POLICY {$table}_read ON {$table}
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() IN ({$select}));
            CREATE POLICY {$table}_insert ON {$table}
                FOR INSERT TO psikotes_runtime
                WITH CHECK (app_private.app_role() IN ({$insert}));
            CREATE POLICY {$table}_update ON {$table}
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() IN ({$update}))
                WITH CHECK (app_private.app_role() IN ({$update}));
            SQL);
    }

    /** @param list<string> $roles */
    private function roleList(array $roles): string
    {
        return implode(', ', array_map(
            static fn (string $role): string => "'{$role}'",
            $roles,
        ));
    }

    /**
     * DB::unprepared() requires a literal-string argument (PHPStan); this
     * migration's SQL is built from hardcoded private const data, never
     * external input, so raw PDO exec() is the established workaround (see
     * PR #57, 2026_09_21_000100_fix_kraepelin_randomization_mode.php).
     */
    private function execute(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }
};
