<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * item-delivery reconciliation, #121 (ME reader) with #76 (RMIB reader),
 * found by Lead's organization-postgres CI run on PR #128 (2026-09-22).
 *
 * Two already-merged migrations both do a wholesale `CREATE OR REPLACE
 * FUNCTION guard_test_sessions_identity_revision()` / drop-and-recreate
 * `test_sessions_identity_revision_guard` (SQLite), each hardcoding the
 * FULL function/trigger body rather than an incremental delta:
 *
 *   - 2026_09_21_000200_add_item_content_variant_to_test_sessions.php (#76)
 *     added `item_content_variant` immutability to the base body.
 *   - 2026_09_22_010000_add_timed_segments_to_test_sessions.php (later
 *     timestamp, so it runs second and wins) added segment-state fields to
 *     the ORIGINAL base body -- built without knowledge of #76's variant
 *     check, since neither migration conflicted textually (different
 *     files, no merge conflict a reviewer would see).
 *
 * Net effect on `main` today, confirmed by real PostgreSQL evidence (not
 * assumed): `item_content_variant` is NOT enforced immutable by the
 * trigger right now -- `UPDATE test_sessions SET item_content_variant = ...`
 * silently succeeds where it must raise 'test session identity or revision
 * contract violation'. This directly contradicts the guarantee
 * GetAssessmentSessionItems's own docblock and API_CONTRACT.md both state
 * ("Varian dipilih SEKALI... dikunci bersama sesi... immutable setelah
 * insert"). A real, if narrow, correctness gap already on `main`, not
 * something this reconciliation branch introduces -- this branch's own
 * Postgres CI run is what surfaced it.
 *
 * Fix: a further additive migration (neither #76's nor #109's own file is
 * touched, per this session's established practice) that reinstalls the
 * function/trigger as the union of both -- the segment-aware body from
 * 2026_09_22_010000, with `item_content_variant` added back into the
 * unconditional identity-violation check exactly where #76 originally put
 * it (not into the participant-role-specific check -- item_content_variant
 * must never change for ANY role, service included, matching #76's own
 * design).
 *
 * down() reverts the function/trigger to the immediately-prior state (the
 * segment-aware, variant-unaware body 2026_09_22_010000 installed) -- it
 * does not touch the item_content_variant column itself (that column's
 * own lifecycle stays owned by #76's migration) or refuse based on
 * populated rows, since removing this enforcement layer discards no data,
 * only a guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
                $this->installPostgresGuardFunction(withVariant: true);
            } else {
                $this->installSqliteGuardTrigger(withVariant: true);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
                $this->installPostgresGuardFunction(withVariant: false);
            } else {
                $this->installSqliteGuardTrigger(withVariant: false);
            }
        });
    }

    private function installPostgresGuardFunction(bool $withVariant): void
    {
        $variantIdentityCheck = $withVariant
            ? "\n                    OR NEW.item_content_variant IS DISTINCT FROM OLD.item_content_variant"
            : '';

        $this->execute(<<<SQL
            CREATE OR REPLACE FUNCTION guard_test_sessions_identity_revision() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR NEW.participant_id IS DISTINCT FROM OLD.participant_id
                    OR NEW.test_type IS DISTINCT FROM OLD.test_type
                    OR NEW.attempt_no IS DISTINCT FROM OLD.attempt_no
                    OR NEW.authorization_id IS DISTINCT FROM OLD.authorization_id
                    OR NEW.allocation_intent_id IS DISTINCT FROM OLD.allocation_intent_id
                    OR NEW.duration_seconds IS DISTINCT FROM OLD.duration_seconds{$variantIdentityCheck} THEN
                    RAISE EXCEPTION 'test session identity or revision contract violation';
                END IF;
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'created' AND NEW.status IN ('in_progress','void'))
                    OR (OLD.status = 'in_progress' AND NEW.status IN ('submitted','expired','void'))
                    OR (OLD.status = 'submitted' AND NEW.status IN ('scored','void'))
                    OR (OLD.status = 'expired' AND NEW.status = 'void')
                ) THEN
                    RAISE EXCEPTION 'illegal test session state transition';
                END IF;
                IF (NEW.current_segment_index IS DISTINCT FROM OLD.current_segment_index
                    OR NEW.current_segment_became_current_at IS DISTINCT FROM OLD.current_segment_became_current_at
                    OR NEW.current_segment_started_at IS DISTINCT FROM OLD.current_segment_started_at) THEN
                    IF NOT (
                        (OLD.status = 'created' AND NEW.status = 'in_progress')
                        OR (OLD.status = 'in_progress' AND NEW.status = 'in_progress')
                    ) THEN
                        RAISE EXCEPTION 'test session segment state is only mutable while starting or in progress';
                    END IF;
                    IF OLD.current_segment_index IS NOT NULL AND NEW.current_segment_index IS NOT NULL
                        AND NEW.current_segment_index < OLD.current_segment_index THEN
                        RAISE EXCEPTION 'test session segment index must be monotonically non-decreasing';
                    END IF;
                END IF;
                IF app_private.app_role() = 'participant' THEN
                    IF NEW.answers_revision IS DISTINCT FROM OLD.answers_revision
                        OR NEW.current_segment_index IS DISTINCT FROM OLD.current_segment_index
                        OR NEW.current_segment_became_current_at IS DISTINCT FROM OLD.current_segment_became_current_at
                        OR NEW.current_segment_started_at IS DISTINCT FROM OLD.current_segment_started_at
                        OR NOT (
                            (OLD.status = 'created' AND NEW.status = 'in_progress')
                            OR (OLD.status = 'in_progress' AND NEW.status = 'submitted')
                        ) THEN
                        RAISE EXCEPTION 'participant session mutation is not authorized';
                    END IF;
                    IF OLD.status = 'created' THEN
                        NEW.started_at := statement_timestamp();
                        NEW.ends_at := NEW.started_at + make_interval(secs => OLD.duration_seconds);
                    ELSE
                        NEW.submitted_at := statement_timestamp();
                    END IF;
                END IF;
                IF NEW.answers_revision IS DISTINCT FROM OLD.answers_revision AND (
                    NEW.answers_revision <> OLD.answers_revision + 1
                    OR NOT EXISTS (
                        SELECT 1 FROM assessment_autosave_mutations mutation
                        WHERE mutation.session_id = NEW.id
                            AND mutation.revision = NEW.answers_revision
                    )
                ) THEN
                    RAISE EXCEPTION 'test session revision requires its exact mutation receipt';
                END IF;
                IF NOT (OLD.status = 'created' AND NEW.status = 'in_progress')
                    AND (NEW.started_at IS DISTINCT FROM OLD.started_at
                        OR NEW.ends_at IS DISTINCT FROM OLD.ends_at) THEN
                    RAISE EXCEPTION 'test session timing window is immutable';
                END IF;
                IF NEW.submitted_at IS DISTINCT FROM OLD.submitted_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'submitted') THEN
                    RAISE EXCEPTION 'submitted evidence is immutable';
                END IF;
                IF NEW.scored_at IS DISTINCT FROM OLD.scored_at
                    AND NOT (OLD.status = 'submitted' AND NEW.status = 'scored') THEN
                    RAISE EXCEPTION 'scored evidence is immutable';
                END IF;
                IF NEW.expired_at IS DISTINCT FROM OLD.expired_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'expired') THEN
                    RAISE EXCEPTION 'expired evidence is immutable';
                END IF;
                IF (NEW.voided_at IS DISTINCT FROM OLD.voided_at
                    OR NEW.void_reason IS DISTINCT FROM OLD.void_reason)
                    AND NOT (OLD.status <> 'void' AND NEW.status = 'void') THEN
                    RAISE EXCEPTION 'void evidence is immutable';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            SQL);
    }

    private function installSqliteGuardTrigger(bool $withVariant): void
    {
        $variantIdentityCheck = $withVariant
            ? "\n                OR NEW.item_content_variant IS NOT OLD.item_content_variant"
            : '';

        $this->execute('DROP TRIGGER IF EXISTS test_sessions_identity_revision_guard');
        $this->execute(<<<SQL
            CREATE TRIGGER test_sessions_identity_revision_guard
            BEFORE UPDATE ON test_sessions
            WHEN NEW.public_id IS NOT OLD.public_id
                OR NEW.participant_id IS NOT OLD.participant_id
                OR NEW.test_type IS NOT OLD.test_type
                OR NEW.attempt_no IS NOT OLD.attempt_no
                OR NEW.authorization_id IS NOT OLD.authorization_id
                OR NEW.allocation_intent_id IS NOT OLD.allocation_intent_id
                OR NEW.duration_seconds IS NOT OLD.duration_seconds{$variantIdentityCheck}
                OR (NEW.answers_revision IS NOT OLD.answers_revision AND (
                    NEW.answers_revision <> OLD.answers_revision + 1
                    OR NOT EXISTS (
                        SELECT 1 FROM assessment_autosave_mutations mutation
                        WHERE mutation.session_id = NEW.id
                            AND mutation.revision = NEW.answers_revision
                    )
                ))
                OR (NEW.status IS NOT OLD.status AND NOT (
                    (OLD.status = 'created' AND NEW.status IN ('in_progress','void'))
                    OR (OLD.status = 'in_progress' AND NEW.status IN ('submitted','expired','void'))
                    OR (OLD.status = 'submitted' AND NEW.status IN ('scored','void'))
                    OR (OLD.status = 'expired' AND NEW.status = 'void')
                ))
                OR (NOT (OLD.status = 'created' AND NEW.status = 'in_progress') AND (
                    NEW.started_at IS NOT OLD.started_at OR NEW.ends_at IS NOT OLD.ends_at
                ))
                OR (NEW.submitted_at IS NOT OLD.submitted_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'submitted'))
                OR (NEW.scored_at IS NOT OLD.scored_at
                    AND NOT (OLD.status = 'submitted' AND NEW.status = 'scored'))
                OR (NEW.expired_at IS NOT OLD.expired_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'expired'))
                OR ((NEW.voided_at IS NOT OLD.voided_at OR NEW.void_reason IS NOT OLD.void_reason)
                    AND NOT (OLD.status <> 'void' AND NEW.status = 'void'))
                OR ((NEW.current_segment_index IS NOT OLD.current_segment_index
                        OR NEW.current_segment_became_current_at IS NOT OLD.current_segment_became_current_at
                        OR NEW.current_segment_started_at IS NOT OLD.current_segment_started_at)
                    AND NOT (
                        (OLD.status = 'created' AND NEW.status = 'in_progress')
                        OR (OLD.status = 'in_progress' AND NEW.status = 'in_progress')
                    ))
                OR (OLD.current_segment_index IS NOT NULL AND NEW.current_segment_index IS NOT NULL
                    AND NEW.current_segment_index < OLD.current_segment_index)
            BEGIN SELECT RAISE(ABORT, 'test session identity or revision contract violation'); END
            SQL);
    }

    private function execute(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('Unable to reconcile the test session identity/revision guard.');
        }
    }
};
