<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 item-delivery Stage 2 continuation (2026-09-21). RMIB's job-interest
 * items carry two gendered label tracks (`job_male`/`job_female`) for the
 * same 108 positions; the reader picks one based on the participant's
 * gender. Lead decision (2026-09-21, opt. (a)): that choice is resolved
 * ONCE, at session allocation, and frozen with the session -- never
 * re-derived from the participant's current profile on a later
 * `GET /sessions/{id}/items` read. Without this, a profile correction
 * mid-test would silently change which job labels a participant sees,
 * disagreeing with the ranking they already built up in their head against
 * the original labels.
 *
 * `item_content_variant` is a nullable, opaque, per-reader selector string
 * (e.g. `'male'`/`'female'` for RMIB) written exactly once, at INSERT, by
 * `AllocateAndStartAssessmentSession::insertCreatedSession()`. It carries
 * no meaning of its own to this schema -- readers without a variant axis
 * (Kraepelin, PAPI, and RMIB until this migration lands) leave it NULL
 * forever. Immutable after insert, same guarantee as every other identity
 * field on this table (public_id, test_type, duration_seconds, ...):
 * extends the existing `guard_test_sessions_identity_revision()` trigger
 * function (PostgreSQL: `CREATE OR REPLACE FUNCTION`, same OID, existing
 * trigger/grants/RLS untouched, same technique as #57's
 * `2026_09_21_000100_fix_kraepelin_randomization_mode.php`; SQLite: drop
 * and recreate, since SQLite has no `CREATE OR REPLACE TRIGGER`) rather
 * than adding a second, parallel guard mechanism for one column.
 *
 * Safety: aborts with a clear message before touching anything if any
 * existing row would violate the new column's shape (impossible today,
 * since the column doesn't exist yet, but mirrors #57's own reasoning:
 * this migration runs in environments this session cannot see, so "no
 * such row could exist yet" stays a reasoned expectation, not an
 * unchecked assumption). `down()` refuses if any row has a non-null
 * value, rather than silently discarding a locked-in item content
 * decision.
 */
return new class extends Migration
{
    private const COLUMN = 'item_content_variant';

    private const ORIGINAL_TRIGGER_FUNCTION_NAME = 'guard_test_sessions_identity_revision';

    public function up(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
            }

            if (Schema::hasColumn('test_sessions', self::COLUMN)) {
                $this->abort('item_content_variant column already exists');
            }

            Schema::table('test_sessions', function (Blueprint $table): void {
                $table->string(self::COLUMN, 32)->nullable();
            });

            if ($driver === 'pgsql') {
                DB::statement(<<<'SQL'
                    ALTER TABLE test_sessions ADD CONSTRAINT test_sessions_item_content_variant_check CHECK (
                        item_content_variant IS NULL
                        OR (
                            length(item_content_variant) BETWEEN 1 AND 32
                            AND item_content_variant = btrim(item_content_variant)
                            AND item_content_variant !~ '[[:space:][:cntrl:]]'
                        )
                    )
                    SQL);
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
            }

            if (! Schema::hasColumn('test_sessions', self::COLUMN)) {
                return;
            }
            if (DB::table('test_sessions')->whereNotNull(self::COLUMN)->exists()) {
                $this->abort('populated item_content_variant values prevent rollback');
            }

            if ($driver === 'pgsql') {
                $this->installPostgresGuardFunction(withVariant: false);
                DB::statement('ALTER TABLE test_sessions DROP CONSTRAINT IF EXISTS test_sessions_item_content_variant_check');
            } else {
                $this->installSqliteGuardTrigger(withVariant: false);
            }

            Schema::table('test_sessions', function (Blueprint $table): void {
                $table->dropColumn(self::COLUMN);
            });
        });
    }

    private function installPostgresGuardFunction(bool $withVariant): void
    {
        $variantIdentityCheck = $withVariant
            ? "\n                    OR NEW.item_content_variant IS DISTINCT FROM OLD.item_content_variant"
            : '';

        $this->execute(<<<SQL
            CREATE OR REPLACE FUNCTION {$this->originalTriggerFunctionName()}() RETURNS trigger AS \$\$
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
                IF app_private.app_role() = 'participant' THEN
                    IF NEW.answers_revision IS DISTINCT FROM OLD.answers_revision
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
            BEGIN SELECT RAISE(ABORT, 'test session identity or revision contract violation'); END
            SQL);
    }

    private function originalTriggerFunctionName(): string
    {
        return self::ORIGINAL_TRIGGER_FUNCTION_NAME;
    }

    private function execute(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('Unable to install the item content variant identity guard.');
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Item content variant migration aborted: '.$reason.'.');
    }
};
